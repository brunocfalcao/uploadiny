<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AgentAccess;
use App\Services\AgentTokenStorage;
use App\UploadinyTokenAbility;
use App\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AgentAccessConcurrencyTest extends TestCase
{
    public function test_overlapping_rotation_failures_and_revocation_preserve_the_surviving_credential(): void
    {
        $directory = sys_get_temp_dir().'/uploadiny-key-race-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory, 0700, true);
        touch($directory.'/database.sqlite');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $directory.'/database.sqlite', 'filesystems.disks.local.root' => $directory.'/private', 'cache.default' => 'file', 'cache.stores.file.path' => $directory.'/cache', 'cache.stores.file.lock_path' => $directory.'/cache']);
        DB::purge();
        Storage::forgetDisk('local');
        try {
            Artisan::call('migrate', ['--force' => true]);
            $user = User::factory()->create(['email' => 'key-race@example.test']);
            $phone = $user->createToken('phone', UploadinyTokenAbility::phone());
            $access = app(AgentAccess::class);
            foreach ([['yes', 'no', 'rotate'], ['no', 'yes', 'rotate'], ['no', 'no', 'revoke']] as [$failA, $failB, $operation]) {
                @unlink($directory.'/ready');
                @unlink($directory.'/resume');
                $this->assertTrue($access->rotate($user));
                $previous = $access->current($user);
                $this->assertSame(AgentAccess::TOKEN_NAME, PersonalAccessToken::findToken($previous)->name);
                $a = new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-agent-access.php'), $directory, (string) $user->id, 'a', 'rotate', $failA]);
                $b = new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-agent-access.php'), $directory, (string) $user->id, 'b', $operation, $failB]);
                try {
                    $a->start();
                    $deadline = microtime(true) + 4;
                    while (! is_file($directory.'/ready') && $a->isRunning() && microtime(true) < $deadline) {
                        usleep(1000);
                    }
                    $this->assertFileExists($directory.'/ready', $a->getErrorOutput());
                    $b->start();
                    usleep(250000);
                    touch($directory.'/resume');
                    $a->wait();
                    $b->wait();
                    $this->assertSame($failA === 'yes' ? 75 : 0, $a->getExitCode(), $a->getErrorOutput());
                    $this->assertSame($failB === 'yes' ? 75 : 0, $b->getExitCode(), $b->getErrorOutput());
                    if ($operation === 'revoke') {
                        $this->assertNull($access->current($user));
                        Storage::disk('local')->assertMissing('credentials/uploadiny-agent-token.txt');
                        $this->assertSame(0, $user->tokens()->where('name', AgentAccess::TOKEN_NAME)->count());
                    } else {
                        $current = Storage::disk('local')->get('credentials/uploadiny-agent-token.txt');
                        $this->assertTrue(hash_equals($current, $access->current($user) ?? ''), 'The managed credential must match the surviving valid agent token.');
                        $this->assertSame(1, $user->tokens()->where('name', AgentAccess::TOKEN_NAME)->count());
                        $this->assertNull(PersonalAccessToken::findToken($previous));
                    }
                    $this->assertSame(UploadinyTokenAbility::phone(), $phone->accessToken->fresh()->abilities);
                } finally {
                    $a->stop();
                    $b->stop();
                }
            }
        } finally {
            DB::disconnect();
            File::deleteDirectory($directory);
        }
    }

    public static function nestedOutcomes(): array
    {
        return [['rotate', 'commit'], ['rotate', 'rollback'], ['revoke', 'commit'], ['revoke', 'rollback'], ['rotate-savepoint', 'commit']];
    }

    #[DataProvider('nestedOutcomes')]
    public function test_independent_recovery_waits_for_the_outer_key_transaction(string $operation, string $outcome): void
    {
        $directory = sys_get_temp_dir().'/uploadiny-key-outer-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory, 0700, true);
        touch($directory.'/database.sqlite');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $directory.'/database.sqlite', 'filesystems.disks.local.root' => $directory.'/private', 'cache.default' => 'file', 'cache.stores.file.path' => $directory.'/cache', 'cache.stores.file.lock_path' => $directory.'/cache']);
        DB::purge();
        Storage::forgetDisk('local');
        $writer = null;
        try {
            Artisan::call('migrate', ['--force' => true]);
            $user = User::factory()->create(['email' => 'nested-key-'.bin2hex(random_bytes(5)).'@example.test']);
            $phone = $user->createToken('phone', UploadinyTokenAbility::phone());
            $access = app(AgentAccess::class);
            $this->assertTrue($access->rotate($user));
            $previous = $access->current($user);
            $this->assertFalse(app(AgentTokenStorage::class)->hasRecovery());
            $writer = new Process([PHP_BINARY, base_path('tests/Fixtures/nested-agent-access.php'), $directory, (string) $user->id, $operation, $outcome]);
            $writer->start();
            $deadline = microtime(true) + 6;
            while (! is_file($directory.'/ready') && $writer->isRunning() && microtime(true) < $deadline) {
                usleep(1000);
            }
            $this->assertFileExists($directory.'/ready', $writer->getErrorOutput());
            $before = hash('sha256', Storage::disk('local')->get('credentials/uploadiny-agent-token.txt') ?? '');
            $reader = new Process([PHP_BINARY, base_path('tests/Fixtures/nested-agent-access.php'), $directory, (string) $user->id, 'recover', 'unused']);
            $reader->mustRun();
            $observation = json_decode($reader->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $after = hash('sha256', Storage::disk('local')->get('credentials/uploadiny-agent-token.txt') ?? '');
            touch($directory.'/resume');
            $writer->wait();
            $this->assertSame(0, $writer->getExitCode(), $writer->getErrorOutput());
            $this->assertTrue($observation['journal'], 'A live outer transaction must retain its recovery journal.');
            $this->assertSame($before, $after, 'Independent recovery must leave the active credential untouched.');
            $current = $access->current($user);
            if ($operation === 'revoke' && $outcome === 'commit') {
                $this->assertNull($current);
                $this->assertSame(0, $user->tokens()->where('name', AgentAccess::TOKEN_NAME)->count());
            } else {
                $this->assertNotNull($current);
                $this->assertSame(1, $user->tokens()->where('name', AgentAccess::TOKEN_NAME)->count());
                $this->assertTrue(hash_equals(Storage::disk('local')->get('credentials/uploadiny-agent-token.txt'), $current));
                $this->assertSame($outcome === 'rollback' || $operation === 'rotate-savepoint', hash_equals($previous, $current));
            }
            $this->assertSame(UploadinyTokenAbility::phone(), $phone->accessToken->fresh()->abilities);
            $this->assertFalse(app(AgentTokenStorage::class)->hasRecovery());
        } finally {
            $writer?->stop();
            DB::disconnect();
            File::deleteDirectory($directory);
        }
    }
}

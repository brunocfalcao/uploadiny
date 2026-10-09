<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AgentAccess;
use App\UploadinyTokenAbility;
use App\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
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
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AgentAccess;
use App\Services\WorkspaceDeletion;
use App\UploadImage;
use App\UploadinyTokenAbility;
use App\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InterruptedPrivateWriteTest extends TestCase
{
    public static function operations(): array
    {
        return array_map(fn ($operation) => [$operation], ['rotate', 'rotate-nested', 'upload', 'duplicate', 'transfer', 'drawing']);
    }

    #[DataProvider('operations')]
    public function test_interrupted_rotation_and_asset_writes_recover_to_committed_state(string $operation): void
    {
        $directory = sys_get_temp_dir().'/uploadiny-private-write-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory, 0700, true);
        touch($directory.'/database.sqlite');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $directory.'/database.sqlite', 'filesystems.disks.local.root' => $directory.'/private', 'cache.default' => 'file', 'cache.stores.file.path' => $directory.'/cache', 'cache.stores.file.lock_path' => $directory.'/cache']);
        DB::purge();
        Storage::forgetDisk('local');
        try {
            Artisan::call('migrate', ['--force' => true]);
            $disk = Storage::disk('local');
            $user = User::factory()->create(['email' => 'interrupted-write@example.test']);
            $phone = $user->createToken('phone', UploadinyTokenAbility::phone());
            $disk->put('images/unowned.png', 'unowned original');

            foreach (['before-write', 'after-write', 'before-commit', 'after-commit'] as $point) {
                $access = app(AgentAccess::class);
                $this->assertTrue($access->rotate($user));
                $previous = $access->current($user);
                $image = UploadImage::factory()->create(['annotated_path' => 'annotations/'.$operation.'-'.$point.'.png']);
                $disk->put($image->path, 'surviving original');
                $disk->put($image->annotated_path, 'surviving drawing');
                $before = array_merge($disk->allFiles('images'), $disk->allFiles('annotations'));
                $rotating = str_starts_with($operation, 'rotate');
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/interrupted-private-write.php'), $directory, (string) ($rotating ? $user->id : $image->id), $operation, $point]);

                $process->run();
                $this->assertSame(73, $process->getExitCode(), $operation.'/'.$point.': '.$process->getErrorOutput());
                if ($rotating) {
                    if ($disk->exists('credentials/.uploadiny-agent-token.recovery.json')) {
                        $this->assertSame(0600, fileperms($disk->path('credentials/.uploadiny-agent-token.recovery.json')) & 0777);
                    } else {
                        $this->assertSame('after-commit', $point);
                    }
                }
                // The child is confirmed stopped; avoid waiting for its cache lease in this fixture.
                Cache::lock('uploadiny:agent-access')->forceRelease();
                Cache::lock('uploadiny:upload-sequence')->forceRelease();
                app(WorkspaceDeletion::class)->cleanupPending();

                if ($rotating) {
                    $current = $access->current($user);
                    $this->assertNotNull($current, $point);
                    if ($point === 'after-commit') {
                        $this->assertNotSame($previous, $current);
                        $this->assertNull(PersonalAccessToken::findToken($previous));
                    } else {
                        $this->assertSame($previous, $current, $point);
                    }
                    $this->assertSame(1, $user->tokens()->where('name', AgentAccess::TOKEN_NAME)->count());
                } else {
                    if ($point !== 'after-commit') {
                        $after = array_merge($disk->allFiles('images'), $disk->allFiles('annotations'));
                        sort($before);
                        sort($after);
                        $this->assertSame($before, $after, $operation.'/'.$point);
                    } else {
                        $created = array_diff(array_merge($disk->allFiles('images'), $disk->allFiles('annotations')), $before);
                        $this->assertCount($operation === 'transfer' ? 2 : 1, $created);
                    }
                    foreach (array_diff(array_merge($disk->allFiles('images'), $disk->allFiles('annotations')), $before) as $path) {
                        $this->assertTrue(UploadImage::where('path', $path)->orWhere('annotated_path', $path)->exists(), $operation.'/'.$point.' left an orphan');
                    }
                    $this->assertSame('surviving original', $disk->get($image->path));
                    if ($operation !== 'drawing' || $point !== 'after-commit') {
                        $this->assertSame('surviving drawing', $disk->get($image->annotated_path));
                        $this->assertSame('', $image->fresh()->comments);
                    } else {
                        $this->assertSame('committed replacement', $image->fresh()->comments);
                        $disk->assertExists($image->fresh()->annotated_path);
                    }
                }
                $this->assertSame([], $disk->allFiles('writing'));
                $disk->assertMissing('credentials/.uploadiny-agent-token.recovery.json');
                $disk->assertMissing('credentials/.uploadiny-agent-token.next');
                $this->assertSame('unowned original', $disk->get('images/unowned.png'));
                $this->assertSame($phone->accessToken->id, PersonalAccessToken::findToken($phone->plainTextToken)?->id);
            }
        } finally {
            DB::disconnect();
            File::deleteDirectory($directory);
        }
    }
}

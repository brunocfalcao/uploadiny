<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\WorkspaceDeletion;
use App\StagedFileDeletion;
use App\UploadImage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InterruptedDeletionTest extends TestCase
{
    public function test_process_interruption_restores_surviving_files_and_finishes_committed_cleanup(): void
    {
        $directory = sys_get_temp_dir().'/uploadiny-interruption-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory, 0700, true);
        touch($directory.'/database.sqlite');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $directory.'/database.sqlite', 'filesystems.disks.local.root' => $directory.'/private']);
        DB::purge();
        Storage::forgetDisk('local');
        try {
            Artisan::call('migrate', ['--force' => true]);
            $disk = Storage::disk('local');
            $disk->put('deleting/unowned/keep.png', 'unowned recovery bytes');
            foreach (['move-1', 'move-2', 'before-commit', 'after-commit', 'cleanup-file', 'cleanup-directory'] as $point) {
                $image = UploadImage::factory()->create(['path' => 'images/'.$point.'.png', 'annotated_path' => 'annotations/'.$point.'.png']);
                $disk->put($image->path, 'original '.$point);
                $disk->put($image->annotated_path, 'drawing '.$point);
                $this->assertSame('original '.$point, $disk->get($image->path));
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/interrupted-deletion.php'), $directory.'/database.sqlite', $directory.'/private', (string) $image->id, $point]);
                $process->run();
                $this->assertSame(73, $process->getExitCode(), $process->getErrorOutput());
                app(WorkspaceDeletion::class)->cleanupPending();
                if ($point === 'after-commit' || str_starts_with($point, 'cleanup-')) {
                    $this->assertNull($image->fresh());
                    $disk->assertMissing([$image->path, $image->annotated_path]);
                } else {
                    $this->assertSame($image->uuid, $image->fresh()->uuid);
                    $this->assertSame('original '.$point, $disk->get($image->path));
                    $this->assertSame('drawing '.$point, $disk->get($image->annotated_path));
                }
                $this->assertSame(['deleting/unowned/keep.png'], $disk->allFiles('deleting'));
                $this->assertSame('unowned recovery bytes', $disk->get('deleting/unowned/keep.png'));
                $this->assertSame(0, StagedFileDeletion::count());
            }
        } finally {
            DB::disconnect();
            File::deleteDirectory($directory);
        }
    }
}

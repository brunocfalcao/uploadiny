<?php

declare(strict_types=1);

use App\Services\WorkspaceDeletion;
use App\UploadImage;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$database, $root, $id, $point] = array_slice($argv, 1);
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database, 'filesystems.disks.local.root' => $root]);
app('db')->purge();
Storage::forgetDisk('local');
$disk = Storage::disk('local');
if (str_starts_with($point, 'move-') || str_starts_with($point, 'cleanup-')) {
    $proxy = Mockery::mock($disk);
    $moves = 0;
    $proxy->shouldReceive('move')->andReturnUsing(function ($from, $to) use ($disk, &$moves, $point) {
        $result = $disk->move($from, $to);
        if (++$moves === (int) substr($point, 5)) {
            exit(73);
        }

        return $result;
    });
    $proxy->shouldReceive('delete')->andReturnUsing(function ($path) use ($disk, $point) {
        $result = $disk->delete($path);
        if ($point === 'cleanup-file') {
            exit(73);
        }

        return $result;
    });
    $proxy->shouldReceive('deleteDirectory')->andReturnUsing(function ($path) use ($disk, $point) {
        $result = $disk->deleteDirectory($path);
        if ($point === 'cleanup-directory') {
            exit(73);
        }

        return $result;
    });
    Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
} elseif ($point === 'before-commit') {
    UploadImage::deleting(fn () => exit(73));
} else {
    Event::listen(TransactionCommitted::class, fn () => exit(73));
}
app(WorkspaceDeletion::class)->image(UploadImage::findOrFail((int) $id));

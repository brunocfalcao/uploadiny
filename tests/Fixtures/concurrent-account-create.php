<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$directory, $email, $name] = array_slice($argv, 1);

config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $directory.'/database.sqlite',
    'filesystems.disks.local.root' => $directory.'/private',
    'cache.default' => 'file',
    'cache.stores.file.path' => $directory.'/cache',
    'cache.stores.file.lock_path' => $directory.'/cache',
]);
DB::purge();
Storage::forgetDisk('local');

$disk = Storage::disk('local');
$proxy = Mockery::mock($disk);
$proxy->shouldReceive('path')->andReturnUsing(fn (string $path): string => $disk->path($path));
$proxy->shouldReceive('delete')->andReturnUsing(fn (string|array $paths): bool => $disk->delete($paths));
$proxy->shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use ($disk, $directory, $name): bool {
    if ($name === 'a' && $path === 'initial-login.txt') {
        touch($directory.'/ready');
        $deadline = microtime(true) + 5;
        while (! is_file($directory.'/resume') && microtime(true) < $deadline) {
            usleep(1000);
        }
        if (! is_file($directory.'/resume')) {
            exit(74);
        }
    }

    return $disk->put($path, $contents);
});
Storage::shouldReceive('disk')->with('local')->andReturn($proxy);

exit(Artisan::call('uploadiny:account', ['email' => $email, '--generate' => true]));

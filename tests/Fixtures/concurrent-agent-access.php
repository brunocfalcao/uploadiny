<?php

declare(strict_types=1);

use App\Services\AgentAccess;
use App\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$directory, $id, $name, $operation, $fail] = array_slice($argv, 1);
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $directory.'/database.sqlite', 'filesystems.disks.local.root' => $directory.'/private', 'cache.default' => 'file', 'cache.stores.file.path' => $directory.'/cache', 'cache.stores.file.lock_path' => $directory.'/cache']);
DB::purge();
Storage::forgetDisk('local');
$disk = Storage::disk('local');
$proxy = Mockery::mock($disk);
$proxy->shouldReceive('get')->andReturnUsing(function ($path) use ($disk, $directory, $name) {
    $value = $disk->get($path);
    if ($name === 'a' && $path === 'credentials/uploadiny-agent-token.txt') {
        touch($directory.'/ready');
        $deadline = microtime(true) + 5;
        while (! is_file($directory.'/resume') && microtime(true) < $deadline) {
            usleep(1000);
        }
        if (! is_file($directory.'/resume')) {
            exit(74);
        }
    }

    return $value;
});
$triggered = false;
$proxy->shouldReceive('move')->andReturnUsing(function ($from, $to) use ($disk, $fail, &$triggered) {
    $result = $disk->move($from, $to);
    if ($fail === 'yes' && ! $triggered) {
        $triggered = true;
        DB::statement("CREATE TRIGGER block_fixture_rotation BEFORE DELETE ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'controlled rotation failure'); END");
    }

    return $result;
});
Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
try {
    $access = app(AgentAccess::class);
    $user = User::findOrFail((int) $id);
    $operation === 'revoke' ? $access->revoke($user) : $access->rotate($user);
} catch (QueryException) {
    exit(75);
}

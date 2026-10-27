<?php

declare(strict_types=1);

use App\Services\AgentAccess;
use App\Services\AgentTokenStorage;
use App\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$directory, $id, $operation, $outcome] = array_slice($argv, 1);
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $directory.'/database.sqlite', 'filesystems.disks.local.root' => $directory.'/private', 'cache.default' => 'file', 'cache.stores.file.path' => $directory.'/cache', 'cache.stores.file.lock_path' => $directory.'/cache']);
DB::purge();
Storage::forgetDisk('local');
$access = app(AgentAccess::class);
$user = User::findOrFail((int) $id);
if ($operation === 'recover') {
    $access->recover(wait: false);
    echo json_encode(['journal' => app(AgentTokenStorage::class)->hasRecovery()], JSON_THROW_ON_ERROR);
    exit(0);
}
DB::beginTransaction();
if ($operation === 'rotate-savepoint') {
    DB::beginTransaction();
}
if (str_starts_with($operation, 'rotate')) {
    $access->rotate($user);
    // Separate service instances must share the same transaction ownership.
    app(AgentAccess::class)->rotate($user);
} else {
    $access->revoke($user);
}
if ($operation === 'rotate-savepoint') {
    DB::rollBack();
}
touch($directory.'/ready');
$deadline = microtime(true) + 8;
while (! is_file($directory.'/resume') && microtime(true) < $deadline) {
    usleep(1000);
}
if (! is_file($directory.'/resume')) {
    exit(74);
}
$outcome === 'commit' ? DB::commit() : DB::rollBack();

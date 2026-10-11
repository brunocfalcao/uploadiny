<?php

declare(strict_types=1);

use App\Http\Controllers\ImageController;
use App\Http\Requests\ImageFeedbackRequest;
use App\Services\AgentAccess;
use App\Services\ChunkStorage;
use App\Services\ChunkTransfer;
use App\Services\ImageDuplicator;
use App\Services\WorkspaceDeletion;
use App\UploadImage;
use App\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$directory, $id, $operation, $point] = array_slice($argv, 1);
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $directory.'/database.sqlite', 'filesystems.disks.local.root' => $directory.'/private', 'cache.default' => 'file', 'cache.stores.file.path' => $directory.'/cache', 'cache.stores.file.lock_path' => $directory.'/cache', 'queue.default' => 'sync', 'services.vision.api_key' => null]);
DB::purge();
Storage::forgetDisk('local');
$disk = Storage::disk('local');
$proxy = Mockery::mock($disk);
$writes = 0;
foreach (['put', 'putFileAs', 'copy', 'move'] as $method) {
    $proxy->shouldReceive($method)->andReturnUsing(function (...$arguments) use ($disk, $point, $method, $operation, &$writes) {
        $destination = match ($method) {
            'put' => $arguments[0],
            'putFileAs' => $arguments[0].'/'.$arguments[2],
            default => $arguments[1],
        };
        $owned = str_starts_with($destination, 'images/') || str_starts_with($destination, 'annotations/') || $destination === 'credentials/uploadiny-agent-token.txt';
        if ($owned) {
            $writes++;
        }
        $owned = $owned && ($operation !== 'rotate-nested' || $writes === 2);
        if ($owned && $point === 'before-write') {
            exit(73);
        }
        $result = $disk->$method(...$arguments);
        if ($owned && $point === 'after-write') {
            exit(73);
        }

        return $result;
    });
}
Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
if ($point === 'before-commit') {
    UploadImage::saved(fn () => exit(73));
    $deletions = 0;
    DB::listen(function ($query) use ($operation, &$deletions): void {
        if (str_starts_with(strtolower($query->sql), 'delete from "personal_access_tokens"')) {
            if (++$deletions === ($operation === 'rotate-nested' ? 2 : 1)) {
                exit(73);
            }
        }
    });
} elseif ($point === 'after-commit') {
    Event::listen(TransactionCommitted::class, function (): void {
        if (DB::transactionLevel() === 0) {
            exit(73);
        }
    });
}
if (str_starts_with($operation, 'rotate')) {
    $user = User::findOrFail((int) $id);
    if ($operation === 'rotate-nested') {
        DB::beginTransaction();
        app(AgentAccess::class)->rotate($user);
    }
    app(AgentAccess::class)->rotate($user);
    if ($operation === 'rotate-nested') {
        DB::commit();
    }
    exit(0);
}
$image = UploadImage::findOrFail((int) $id);
match ($operation) {
    'upload' => app(ChunkStorage::class)->store($image->project, [UploadedFile::fake()->image('interrupted.png')]),
    'duplicate' => app(ImageDuplicator::class)->duplicate($image),
    'transfer' => app(ChunkTransfer::class)->transfer($image, null, $image->project_id, 'copy'),
    'drawing' => app(ImageController::class)->update((function (): ImageFeedbackRequest {
        $request = new ImageFeedbackRequest;
        $request->setValidator(app('validator')->make([
            'annotations' => [['tool' => 'line', 'color' => '#ff0000', 'width' => 0.01, 'points' => [['x' => 0.1, 'y' => 0.1], ['x' => 0.9, 'y' => 0.9]]]],
            'comments' => 'committed replacement', 'revision' => 0,
            'annotated_image' => 'data:image/png;base64,'.base64_encode(UploadedFile::fake()->image('replacement.png')->getContent()),
        ], $request->rules()));

        return $request;
    })(), $image, app(WorkspaceDeletion::class)),
};

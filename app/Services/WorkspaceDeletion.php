<?php

declare(strict_types=1);

namespace App\Services;

use App\Project;
use App\StagedFileDeletion;
use App\UploadChunk;
use App\UploadImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class WorkspaceDeletion
{
    public function project(Project $project): void
    {
        $this->remove(function () use ($project): array {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            $images = $locked->images()->lockForUpdate()->get();

            return [$images, function () use ($locked, $images): void {
                $locked->images()->delete();
                UploadChunk::where(function ($query) use ($locked, $images): void {
                    $query->whereIn('id', $images->pluck('chunk_id'))->orWhere('upload_project_id', $locked->id);
                })->whereDoesntHave('images')->delete();
                $locked->delete();
            }];
        });
    }

    public function image(UploadImage $image): void
    {
        $this->remove(function () use ($image): array {
            $locked = UploadImage::query()->lockForUpdate()->findOrFail($image->id);

            return [collect([$locked]), function () use ($locked): void {
                $locked->delete();
                UploadChunk::whereKey($locked->chunk_id)->whereDoesntHave('images')->delete();
            }];
        });
    }

    public function chunk(UploadChunk $chunk): void
    {
        $this->remove(function () use ($chunk): array {
            $locked = UploadChunk::query()->lockForUpdate()->findOrFail($chunk->id);
            abort_unless($locked->status === 'uploading', 409, 'Completed chunks cannot be cancelled.');
            $images = $locked->images()->lockForUpdate()->get();

            return [$images, function () use ($locked): void {
                $locked->images()->delete();
                $locked->delete();
            }];
        });
    }

    /** @return array{deleted_asset_ids: list<string>, chunk_deleted: bool} */
    public function completedChunk(Project $project, UploadChunk $chunk, ?string $reviewToken = null): array
    {
        $deleted = [];
        $chunkDeleted = false;
        $this->remove(function () use ($project, $chunk, $reviewToken, &$deleted, &$chunkDeleted): array {
            $locked = UploadChunk::query()->lockForUpdate()->findOrFail($chunk->id);
            abort_unless($locked->status === 'complete', 409, 'Only completed feedback chunks can be deleted through MCP.');
            $images = $locked->images()->where('project_id', $project->id)->lockForUpdate()->orderBy('id')->get();
            abort_if($images->isEmpty(), 404, 'This chunk has no assets in the selected project.');
            if ($reviewToken !== null) {
                abort_unless(hash_equals($locked->reviewToken($project, $images), $reviewToken), 409, 'This chunk changed since review. Review its updated feedback before requesting cleanup again.');
            }
            $deleted = $images->pluck('uuid')->all();

            return [$images, function () use ($locked, $images, &$chunkDeleted): void {
                $locked->images()->whereKey($images->modelKeys())->delete();
                if (! $locked->images()->exists()) {
                    $locked->delete();
                    $chunkDeleted = true;
                }
            }];
        });

        return ['deleted_asset_ids' => $deleted, 'chunk_deleted' => $chunkDeleted];
    }

    private function remove(\Closure $operation): void
    {
        $disk = Storage::disk('local');
        $staged = [];
        $intent = null;
        try {
            DB::transaction(function () use ($operation, $disk, &$staged, &$intent): void {
                [$images, $deleteRecords] = $operation();
                foreach ($images as $image) {
                    foreach (array_filter([$image->path, $image->annotated_path]) as $path) {
                        if (! $disk->exists($path)) {
                            continue;
                        }
                        $temporaryPath = 'deleting/'.Str::uuid().'/'.basename($path);
                        if (! $disk->move($path, $temporaryPath)) {
                            throw new RuntimeException('The image files could not be removed. Nothing was deleted.');
                        }
                        $staged[$path] = $temporaryPath;
                    }
                }
                $deleteRecords();
                if ($staged !== []) {
                    $intent = StagedFileDeletion::create(['paths' => array_values($staged)]);
                }
            });
        } catch (Throwable $error) {
            foreach ($staged as $path => $temporaryPath) {
                if (! $disk->move($temporaryPath, $path)) {
                    throw new RuntimeException('Deletion failed and an image file could not be restored.', previous: $error);
                }
            }
            throw $error;
        }
        if ($intent !== null) {
            $this->cleanup($intent);
        }
    }

    public function cleanupPending(): void
    {
        StagedFileDeletion::query()->chunkById(100, function ($intents): void {
            foreach ($intents as $intent) {
                $this->cleanup($intent);
            }
        });
    }

    private function cleanup(StagedFileDeletion $intent): void
    {
        $disk = Storage::disk('local');
        try {
            foreach ($intent->paths as $temporaryPath) {
                if ($disk->exists($temporaryPath) && ! $disk->delete($temporaryPath)) {
                    throw new RuntimeException('Staged file cleanup needs another attempt.');
                }
                if (! $disk->deleteDirectory(dirname($temporaryPath))) {
                    throw new RuntimeException('Staged directory cleanup needs another attempt.');
                }
            }
            $intent->delete();
        } catch (Throwable $error) {
            Log::warning('Committed upload cleanup deferred.', ['cleanup_id' => $intent->id, 'category' => class_basename($error)]);
        }
    }
}

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
        $this->projectContents($project, true);
    }

    public function projectChunks(Project $project): void
    {
        $this->projectContents($project, false);
    }

    private function projectContents(Project $project, bool $deleteProject): void
    {
        $this->remove(function () use ($project, $deleteProject): array {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            $images = $locked->images()->lockForUpdate()->get();

            return [$images, function () use ($locked, $images, $deleteProject): void {
                $locked->images()->delete();
                UploadChunk::where(function ($query) use ($locked, $images): void {
                    $query->whereIn('id', $images->pluck('chunk_id'))->orWhere('upload_project_id', $locked->id);
                })->whereDoesntHave('images')->delete();
                if ($deleteProject) {
                    $locked->delete();
                }
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
        $journal = null;
        $lock = null;
        try {
            DB::transaction(function () use ($operation, $disk, &$staged, &$intent, &$journal, &$lock): void {
                [$images, $deleteRecords] = $operation();
                $directory = 'deleting/'.Str::uuid();
                $mapping = [];
                foreach ($images as $image) {
                    foreach (array_filter([$image->path, $image->annotated_path]) as $path) {
                        if ($disk->exists($path)) {
                            $mapping[$path] = $directory.'/'.count($mapping).'-'.basename($path);
                        }
                    }
                }
                if ($mapping !== []) {
                    $journal = $directory.'/journal.json';
                    if (! $disk->makeDirectory($directory)) {
                        throw new RuntimeException('Deletion recovery could not be prepared. Nothing was deleted.');
                    }
                    $lock = $this->journalLock($journal);
                    if ($lock === null || ! $disk->put($journal, json_encode($mapping, JSON_THROW_ON_ERROR))) {
                        throw new RuntimeException('Deletion recovery could not be saved. Nothing was deleted.');
                    }
                    foreach ($mapping as $path => $temporaryPath) {
                        // The durable map precedes every move, including a process-ending interruption.
                        if (! $disk->move($path, $temporaryPath)) {
                            throw new RuntimeException('The image files could not be removed. Nothing was deleted.');
                        }
                        $staged[$path] = $temporaryPath;
                    }
                }
                $deleteRecords();
                if ($staged !== []) {
                    $intent = StagedFileDeletion::create(['paths' => array_values($staged), 'journal_path' => $journal]);
                }
            });
        } catch (Throwable $error) {
            try {
                foreach ($staged as $path => $temporaryPath) {
                    if (! $disk->move($temporaryPath, $path)) {
                        throw new RuntimeException('Deletion failed and an image file could not be restored.', previous: $error);
                    }
                }
                if ($journal !== null) {
                    $disk->deleteDirectory(dirname($journal));
                }
            } finally {
                $this->releaseJournal($lock);
            }
            throw $error;
        }
        try {
            if ($intent !== null) {
                $this->cleanup($intent);
            }
        } finally {
            $this->releaseJournal($lock);
        }
    }

    public function supersededDrawing(string $path): void
    {
        $intent = StagedFileDeletion::create(['paths' => [$path]]);
        DB::afterCommit(fn () => $this->cleanup($intent));
    }

    public function cleanupPending(): void
    {
        StagedFileDeletion::query()->chunkById(100, function ($intents): void {
            foreach ($intents as $intent) {
                $lock = $intent->journal_path === null ? null : $this->journalLock($intent->journal_path);
                if ($intent->journal_path !== null && $lock === null && Storage::disk('local')->directoryExists(dirname($intent->journal_path))) {
                    continue;
                }
                try {
                    $this->cleanup($intent);
                } finally {
                    $this->releaseJournal($lock);
                }
            }
        });
        $disk = Storage::disk('local');
        foreach ($disk->directories('deleting') as $directory) {
            $journal = $directory.'/journal.json';
            if (! $disk->exists($journal)) {
                continue;
            }
            $lock = $this->journalLock($journal);
            if ($lock === null) {
                continue;
            }
            try {
                // Committed intents own cleanup; a map without one belongs to a rolled-back operation.
                if (! is_file($disk->path($journal)) || StagedFileDeletion::where('journal_path', $journal)->exists()) {
                    continue;
                }
                $mapping = json_decode($disk->get($journal), true, flags: JSON_THROW_ON_ERROR);
                foreach ($mapping as $original => $staged) {
                    if (! $disk->exists($staged)) {
                        continue;
                    }
                    if ($this->referenced($original)) {
                        if ($disk->exists($original) || ! $disk->move($staged, $original)) {
                            throw new RuntimeException('Interrupted deletion restoration needs another attempt.');
                        }
                    } elseif (! $disk->delete($staged)) {
                        throw new RuntimeException('Interrupted deletion cleanup needs another attempt.');
                    }
                }
                if (! $disk->deleteDirectory($directory)) {
                    throw new RuntimeException('Interrupted deletion journal cleanup needs another attempt.');
                }
            } catch (Throwable $error) {
                Log::warning('Interrupted upload deletion recovery deferred.', ['category' => class_basename($error)]);
            } finally {
                $this->releaseJournal($lock);
            }
        }
    }

    private function cleanup(StagedFileDeletion $intent): void
    {
        $disk = Storage::disk('local');
        try {
            $intent->update(['attempts' => $intent->attempts + 1, 'last_attempt_at' => now(), 'last_error' => null]);
            foreach ($intent->paths as $temporaryPath) {
                if ($this->referenced($temporaryPath)) {
                    throw new RuntimeException('An active image still owns this file.');
                }
                if ($disk->exists($temporaryPath) && ! $disk->delete($temporaryPath)) {
                    throw new RuntimeException('Staged file cleanup needs another attempt.');
                }
                if ($intent->journal_path === null && str_starts_with($temporaryPath, 'deleting/') && ! $disk->deleteDirectory(dirname($temporaryPath))) {
                    throw new RuntimeException('Staged directory cleanup needs another attempt.');
                }
            }
            if ($intent->journal_path !== null && ! $disk->deleteDirectory(dirname($intent->journal_path))) {
                throw new RuntimeException('Staged journal cleanup needs another attempt.');
            }
            $intent->delete();
        } catch (Throwable $error) {
            try {
                $intent->update(['last_error' => class_basename($error)]);
            } catch (Throwable) {
                // Keep the committed intent retryable even when diagnostic persistence is unavailable.
            }
            Log::warning('Committed upload cleanup deferred.', ['cleanup_id' => $intent->id, 'category' => class_basename($error)]);
        }
    }

    private function referenced(string $path): bool
    {
        return UploadImage::where('path', $path)->orWhere('annotated_path', $path)->exists();
    }

    /** @return resource|null */
    private function journalLock(string $journal)
    {
        $path = Storage::disk('local')->path(dirname($journal).'/.lock');
        if (! is_dir(dirname($path))) {
            return null;
        }
        $lock = fopen($path, 'c');
        if ($lock === false) {
            throw new RuntimeException('Deletion recovery lock could not be opened.');
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return null;
        }

        return $lock;
    }

    /** @param resource|null $lock */
    private function releaseJournal($lock): void
    {
        if ($lock !== null) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

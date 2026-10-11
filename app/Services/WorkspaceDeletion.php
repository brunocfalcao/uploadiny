<?php

declare(strict_types=1);

namespace App\Services;

use App\Project;
use App\StagedFileDeletion;
use App\UploadChunk;
use App\UploadImage;
use Illuminate\Support\Carbon;
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
                if ($journal !== null && $lock !== null) {
                    $this->removeRecoveryRecord($journal);
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
        app(PrivateFileWrites::class)->cleanupPending();
        try {
            app(AgentAccess::class)->recover(wait: false);
        } catch (Throwable $error) {
            Log::warning('Agent access recovery deferred.', ['category' => class_basename($error)]);
        }
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
            if (! $disk->exists($journal) && ! $disk->exists($directory.'/.lock')) {
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
                $progress = $this->recoveryProgress($journal);
                $progress['attempts']++;
                $progress['last_attempt_at'] = now()->toIso8601String();
                $progress['last_error'] = null;
                $this->saveRecoveryProgress($journal, $progress);
                $mapping = json_decode($disk->get($journal), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($mapping)) {
                    throw new RuntimeException('Interrupted deletion recovery record is invalid.');
                }
                foreach ($mapping as $original => $staged) {
                    if (! is_string($original) || ! is_string($staged) || dirname($staged) !== $directory || str_contains($staged, '..')) {
                        throw new RuntimeException('Interrupted deletion recovery record is invalid.');
                    }
                    if ($this->referenced($staged)) {
                        throw new RuntimeException('An active image still owns this staged file.');
                    }
                }
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
                $this->removeRecoveryRecord($journal);
            } catch (Throwable $error) {
                if (isset($progress) && $disk->exists($journal)) {
                    try {
                        $progress['last_error'] = class_basename($error);
                        $this->saveRecoveryProgress($journal, $progress);
                    } catch (Throwable) {
                        // A later attempt retains the journal even if progress cannot be saved.
                    }
                }
                Log::warning('Interrupted upload deletion recovery deferred.', ['category' => class_basename($error)]);
            } finally {
                unset($progress);
                $this->releaseJournal($lock);
            }
        }
    }

    private function cleanup(StagedFileDeletion $intent): void
    {
        $disk = Storage::disk('local');
        try {
            $intent->update(['attempts' => $intent->attempts + 1, 'last_attempt_at' => now(), 'last_error' => null]);
            // Validate the complete ownership set before removing any sibling.
            foreach ($intent->paths as $temporaryPath) {
                if ($this->referenced($temporaryPath)) {
                    throw new RuntimeException('An active image still owns this file.');
                }
            }
            foreach ($intent->paths as $temporaryPath) {
                if ($disk->exists($temporaryPath) && ! $disk->delete($temporaryPath)) {
                    throw new RuntimeException('Staged file cleanup needs another attempt.');
                }
                if ($intent->journal_path === null && str_starts_with($temporaryPath, 'deleting/')) {
                    $this->removeEmptyDirectory(dirname($temporaryPath));
                }
            }
            if ($intent->journal_path !== null) {
                $this->removeRecoveryRecord($intent->journal_path);
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

    private function removeRecoveryRecord(string $journal): void
    {
        $directory = dirname($journal);
        $disk = Storage::disk('local');
        if (! $disk->delete([$directory.'/recovery.json', $directory.'/recovery.next']) || ! $disk->delete($journal)) {
            throw new RuntimeException('Deletion recovery record cleanup needs another attempt.');
        }
    }

    private function removeEmptyDirectory(string $directory): void
    {
        $disk = Storage::disk('local');
        // rmdir is atomic: a sibling arriving during cleanup cannot be removed.
        if (! @rmdir($disk->path($directory)) && $disk->directoryExists($directory) && $disk->allFiles($directory) === [] && $disk->directories($directory) === []) {
            throw new RuntimeException('Empty recovery directory cleanup needs another attempt.');
        }
    }

    /** @return array{created_at: string, attempts: int, last_attempt_at: ?string, last_error: ?string} */
    private function recoveryProgress(string $journal): array
    {
        $disk = Storage::disk('local');
        $path = dirname($journal).'/recovery.json';
        if ($disk->exists($path)) {
            $saved = json_decode($disk->get($path) ?? '', true);
        }

        return [
            'created_at' => $saved['created_at'] ?? Carbon::createFromTimestamp($disk->lastModified($journal))->toIso8601String(),
            'attempts' => (int) ($saved['attempts'] ?? 0),
            'last_attempt_at' => $saved['last_attempt_at'] ?? null,
            'last_error' => $saved['last_error'] ?? null,
        ];
    }

    /** @param array{created_at: string, attempts: int, last_attempt_at: ?string, last_error: ?string} $progress */
    private function saveRecoveryProgress(string $journal, array $progress): void
    {
        $disk = Storage::disk('local');
        $temporary = dirname($journal).'/recovery.next';
        if (! $disk->put($temporary, json_encode($progress, JSON_THROW_ON_ERROR)) || ! $disk->move($temporary, dirname($journal).'/recovery.json')) {
            throw new RuntimeException('Deletion recovery progress could not be saved.');
        }
    }

    /** @return array{pending: int, oldest_pending_seconds: ?int, attempts: int, deferred: int, last_attempt_at: ?string} */
    public function orphanRecoveryStatus(): array
    {
        $disk = Storage::disk('local');
        $status = ['pending' => 0, 'oldest_pending_seconds' => null, 'attempts' => 0, 'deferred' => 0, 'last_attempt_at' => null];
        foreach ($disk->directories('deleting') as $directory) {
            $journal = $directory.'/journal.json';
            if (! $disk->exists($journal) || StagedFileDeletion::where('journal_path', $journal)->exists()) {
                continue;
            }
            $progress = $this->recoveryProgress($journal);
            $status['pending']++;
            $age = max(0, (int) Carbon::parse($progress['created_at'])->diffInSeconds(now()));
            $status['oldest_pending_seconds'] = max($status['oldest_pending_seconds'] ?? 0, $age);
            $status['attempts'] += $progress['attempts'];
            $status['deferred'] += (int) ($progress['last_error'] !== null);
            if ($progress['last_attempt_at'] !== null && ($status['last_attempt_at'] === null || $progress['last_attempt_at'] > $status['last_attempt_at'])) {
                $status['last_attempt_at'] = $progress['last_attempt_at'];
            }
        }

        return $status;
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
        clearstatcache(true, $path);
        $current = @stat($path);
        if ($current === false || fstat($lock)['ino'] !== $current['ino']) {
            flock($lock, LOCK_UN);
            fclose($lock);

            return null;
        }

        return $lock;
    }

    /** @param resource|null $lock */
    private function releaseJournal($lock): void
    {
        if ($lock !== null) {
            $path = stream_get_meta_data($lock)['uri'];
            $inode = fstat($lock)['ino'];
            flock($lock, LOCK_UN);
            fclose($lock);
            clearstatcache(true, $path);
            $current = @stat($path);
            if ($current !== false && $current['ino'] === $inode && ! is_file(dirname($path).'/journal.json') && ! is_file(dirname($path).'/recovery.json') && ! is_file(dirname($path).'/recovery.next')) {
                @unlink($path);
                @rmdir(dirname($path));
            }
        }
    }
}

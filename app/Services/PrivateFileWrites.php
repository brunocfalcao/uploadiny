<?php

declare(strict_types=1);

namespace App\Services;

use App\UploadImage;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Durable ownership of new private files until their database references commit. */
final class PrivateFileWrites
{
    private ?string $directory = null;

    /** @var list<string> */
    private array $paths = [];

    /** @var resource|null */
    private $lock = null;

    public function transaction(Closure $operation): mixed
    {
        $journal = new self;
        try {
            $result = DB::transaction(fn () => $operation($journal));
        } catch (Throwable $error) {
            $journal->finish();
            throw $error;
        }
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $journal->finish());
        } else {
            $journal->finish();
        }

        return $result;
    }

    /** Register the fresh, unique destination before the first write or copy. */
    public function track(string $path): void
    {
        $disk = Storage::disk('local');
        if ($this->directory === null) {
            $this->directory = 'writing/'.Str::uuid();
            throw_unless($disk->makeDirectory($this->directory), RuntimeException::class, 'Private file recovery could not be prepared.');
            $this->lock = $this->acquire($this->directory);
            throw_if($this->lock === null, RuntimeException::class, 'Private file recovery is already active.');
            try {
                $this->saveRecoveryProgress($this->directory, [
                    'created_at' => now()->toIso8601String(),
                    'attempts' => 0,
                    'last_attempt_at' => null,
                    'last_error' => null,
                ]);
            } catch (Throwable $error) {
                // Progress is diagnostic only; the durable manifest still owns the write.
                Log::debug('Private file recovery progress deferred.', ['category' => class_basename($error)]);
            }
        }
        $this->paths[] = $path;
        $temporary = $this->directory.'/journal.next';
        throw_unless($disk->put($temporary, json_encode($this->paths, JSON_THROW_ON_ERROR)) && $disk->move($temporary, $this->directory.'/journal.json'), RuntimeException::class, 'Private file recovery could not be saved.');
    }

    public function cleanupPending(): void
    {
        $disk = Storage::disk('local');
        foreach ($disk->directories('writing') as $directory) {
            if (! $disk->exists($directory.'/journal.json') && ! $disk->exists($directory.'/.lock') && ! $disk->exists($directory.'/recovery.json') && ! $disk->exists($directory.'/recovery.next') && ! $disk->exists($directory.'/journal.next')) {
                continue;
            }
            $lock = $this->acquire($directory);
            if ($lock === null) {
                continue;
            }
            $progress = null;
            try {
                $progress = $this->markAttempt($directory);
                $this->reconcile($directory);
            } catch (Throwable $error) {
                $this->markFailure($directory, $error, $progress);
                Log::warning('Interrupted private file write recovery deferred.', ['category' => class_basename($error)]);
            } finally {
                $this->release($lock);
            }
        }
    }

    /** @return array{pending: int, oldest_pending_seconds: ?int, attempts: int, deferred: int, active: int, last_attempt_at: ?string} */
    public function recoveryStatus(): array
    {
        $disk = Storage::disk('local');
        $status = ['pending' => 0, 'oldest_pending_seconds' => null, 'attempts' => 0, 'deferred' => 0, 'active' => 0, 'last_attempt_at' => null];
        foreach ($disk->directories('writing') as $directory) {
            $active = $this->hasActiveLease($directory);
            $status['active'] += (int) $active;
            $journal = $directory.'/journal.json';
            if (! $disk->exists($journal)) {
                continue;
            }
            try {
                $progress = $this->recoveryProgress($directory);
                if (! $disk->exists($journal)) {
                    continue;
                }
            } catch (Throwable $error) {
                // Status is a read-only sample. A retired journal or unavailable metadata is not a cleanup result.
                Log::debug('Private file recovery status sample skipped.', ['category' => class_basename($error)]);

                continue;
            }
            $status['pending']++;
            $age = max(0, (int) Carbon::parse($progress['created_at'])->diffInSeconds(now()));
            $status['oldest_pending_seconds'] = max($status['oldest_pending_seconds'] ?? 0, $age);
            $status['attempts'] += $progress['attempts'];
            $status['deferred'] += (int) ($progress['last_error'] !== null && ! $active);
            if ($progress['last_attempt_at'] !== null && ($status['last_attempt_at'] === null || $progress['last_attempt_at'] > $status['last_attempt_at'])) {
                $status['last_attempt_at'] = $progress['last_attempt_at'];
            }
        }

        return $status;
    }

    private function finish(): void
    {
        $progress = null;
        try {
            if ($this->directory !== null) {
                if ($this->lock === null) {
                    $this->lock = $this->acquire($this->directory);
                    if ($this->lock === null) {
                        return;
                    }
                }
                $progress = $this->markAttempt($this->directory);
                $this->reconcile($this->directory);
            }
        } catch (Throwable $error) {
            // The durable journal retains ownership for the scheduler's next attempt.
            if ($this->directory !== null) {
                $this->markFailure($this->directory, $error, $progress);
            }
            Log::warning('Private file write recovery deferred.', ['category' => class_basename($error)]);
        } finally {
            $this->release($this->lock);
            $this->lock = null;
        }
    }

    private function reconcile(string $directory): void
    {
        $disk = Storage::disk('local');
        $journal = $directory.'/journal.json';
        if (! $disk->exists($journal)) {
            // A destination is never written until the first manifest is published.
            $this->deleteIfPresent($disk, $directory.'/recovery.next');
            $this->deleteIfPresent($disk, $directory.'/recovery.json');
            $this->deleteIfPresent($disk, $directory.'/journal.next');

            return;
        }
        $paths = json_decode($disk->get($journal) ?? '', true, flags: JSON_THROW_ON_ERROR);
        throw_unless(is_array($paths) && array_is_list($paths), RuntimeException::class, 'Private file recovery record is invalid.');
        foreach ($paths as $path) {
            throw_unless(is_string($path) && preg_match('~^(images|annotations)/[^/]+$~D', $path), RuntimeException::class, 'Private file recovery record is invalid.');
        }
        foreach ($paths as $path) {
            if (! UploadImage::where('path', $path)->orWhere('annotated_path', $path)->exists() && $disk->exists($path)) {
                throw_unless($disk->delete($path), RuntimeException::class, 'Interrupted private file cleanup needs another attempt.');
            }
        }
        // Retire progress metadata before the manifest, while the inode lock remains held.
        $this->deleteIfPresent($disk, $directory.'/recovery.next');
        $this->deleteIfPresent($disk, $directory.'/recovery.json');
        $this->deleteIfPresent($disk, $directory.'/journal.next');
        $this->deleteIfPresent($disk, $journal);
    }

    /** @return array{created_at: string, attempts: int, last_attempt_at: ?string, last_error: ?string} */
    private function recoveryProgress(string $directory): array
    {
        $disk = Storage::disk('local');
        $saved = [];
        $path = $directory.'/recovery.json';
        if ($disk->exists($path)) {
            try {
                $candidate = json_decode($disk->get($path) ?? '', true, flags: JSON_THROW_ON_ERROR);
                $saved = is_array($candidate) ? $candidate : [];
            } catch (Throwable) {
                $saved = [];
            }
        }
        $createdAt = $this->recoveryTimestamp($saved['created_at'] ?? null);
        if ($createdAt === null) {
            $journal = $directory.'/journal.json';
            if ($disk->exists($journal)) {
                $modified = $disk->lastModified($journal);
                if (! is_int($modified) && ! is_numeric($modified)) {
                    throw new RuntimeException('Private file recovery journal metadata is unavailable.');
                }
                $createdAt = Carbon::createFromTimestamp((int) $modified)->toIso8601String();
            } else {
                $createdAt = now()->toIso8601String();
            }
        }

        return [
            'created_at' => $createdAt,
            'attempts' => is_numeric($saved['attempts'] ?? null) ? max(0, (int) $saved['attempts']) : 0,
            'last_attempt_at' => $this->recoveryTimestamp($saved['last_attempt_at'] ?? null),
            'last_error' => is_string($saved['last_error'] ?? null) && $saved['last_error'] !== '' ? $saved['last_error'] : null,
        ];
    }

    private function recoveryTimestamp(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array{created_at: string, attempts: int, last_attempt_at: ?string, last_error: ?string}|null $progress */
    private function markAttempt(string $directory): ?array
    {
        try {
            $progress = $this->recoveryProgress($directory);
            $progress['attempts']++;
            $progress['last_attempt_at'] = now()->toIso8601String();
            $progress['last_error'] = null;
            $this->saveRecoveryProgress($directory, $progress);

            return $progress;
        } catch (Throwable $error) {
            // Recovery still owns the journal if diagnostic persistence is unavailable.
            Log::debug('Private file recovery progress deferred.', ['category' => class_basename($error)]);

            return null;
        }
    }

    /** @param array{created_at: string, attempts: int, last_attempt_at: ?string, last_error: ?string}|null $progress */
    private function markFailure(string $directory, Throwable $error, ?array $progress): void
    {
        try {
            $progress ??= $this->recoveryProgress($directory);
            $progress['last_error'] = class_basename($error);
            $this->saveRecoveryProgress($directory, $progress);
        } catch (Throwable $diagnosticError) {
            Log::debug('Private file recovery failure status unavailable.', ['category' => class_basename($diagnosticError)]);
        }
    }

    private function saveRecoveryProgress(string $directory, array $progress): void
    {
        $disk = Storage::disk('local');
        $temporary = $directory.'/recovery.next';
        throw_unless($disk->put($temporary, json_encode($progress, JSON_THROW_ON_ERROR)) && $disk->move($temporary, $directory.'/recovery.json'), RuntimeException::class, 'Private file recovery progress could not be saved.');
    }

    private function deleteIfPresent($disk, string $path): void
    {
        if ($disk->exists($path)) {
            throw_unless($disk->delete($path), RuntimeException::class, 'Private file recovery record cleanup needs another attempt.');
        }
    }

    private function hasActiveLease(string $directory): bool
    {
        $path = Storage::disk('local')->path($directory.'/.lock');
        if (! is_file($path)) {
            return false;
        }
        $lock = @fopen($path, 'r+');
        if ($lock === false) {
            return true;
        }
        if (! @flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return true;
        }
        clearstatcache(true, $path);
        $descriptor = @fstat($lock);
        $current = @stat($path);
        $active = $descriptor === false || $current === false || ($descriptor['ino'] ?? null) !== ($current['ino'] ?? null);
        flock($lock, LOCK_UN);
        fclose($lock);

        return $active;
    }

    /** @return resource|null */
    private function acquire(string $directory)
    {
        $path = Storage::disk('local')->path($directory.'/.lock');
        if (! is_dir(dirname($path))) {
            return null;
        }
        $lock = fopen($path, 'c');
        throw_if($lock === false, RuntimeException::class, 'Private file recovery lock could not be opened.');
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
    private function release($lock): void
    {
        if ($lock !== null) {
            $path = stream_get_meta_data($lock)['uri'];
            $inode = fstat($lock)['ino'];
            flock($lock, LOCK_UN);
            fclose($lock);
            // Retire the lock only after releasing it and publishing completion.
            clearstatcache(true, $path);
            $current = @stat($path);
            if ($current !== false && $current['ino'] === $inode && ! is_file(dirname($path).'/journal.json') && ! is_file(dirname($path).'/journal.next') && ! is_file(dirname($path).'/recovery.json') && ! is_file(dirname($path).'/recovery.next')) {
                @unlink($path);
                @rmdir(dirname($path));
            }
        }
    }

    public function __destruct()
    {
        $this->release($this->lock);
    }
}

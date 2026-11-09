<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/** Shared metadata and inode-lock mechanics for private recovery journals. */
final class RecoveryJournal
{
    /**
     * @return array{created_at: string, attempts: int, last_attempt_at: ?string, last_error: ?string}
     */
    public function progress(string $journal, bool $allowMissingJournal, string $errorMessage): array
    {
        $disk = Storage::disk('local');
        $directory = dirname($journal);
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
        $createdAt = $this->timestamp($saved['created_at'] ?? null);
        if ($createdAt === null) {
            if ($allowMissingJournal && ! $disk->exists($journal)) {
                $createdAt = now()->toIso8601String();
            } else {
                $modified = $disk->lastModified($journal);
                if (! is_int($modified) && ! is_numeric($modified)) {
                    throw new RuntimeException($errorMessage);
                }
                $createdAt = Carbon::createFromTimestamp((int) $modified)->toIso8601String();
            }
        }

        return [
            'created_at' => $createdAt,
            'attempts' => is_numeric($saved['attempts'] ?? null) ? max(0, (int) $saved['attempts']) : 0,
            'last_attempt_at' => $this->timestamp($saved['last_attempt_at'] ?? null),
            'last_error' => is_string($saved['last_error'] ?? null) && $saved['last_error'] !== '' ? $saved['last_error'] : null,
        ];
    }

    /** @param array{created_at: string, attempts: int, last_attempt_at: ?string, last_error: ?string} $progress */
    public function saveProgress(string $journal, array $progress, string $errorMessage): void
    {
        $disk = Storage::disk('local');
        $directory = dirname($journal);
        $temporary = $directory.'/recovery.next';
        if (! $disk->put($temporary, json_encode($progress, JSON_THROW_ON_ERROR)) || ! $disk->move($temporary, $directory.'/recovery.json')) {
            throw new RuntimeException($errorMessage);
        }
    }

    /** @return resource|null */
    public function acquire(string $directory, string $errorMessage)
    {
        $path = Storage::disk('local')->path($directory.'/.lock');
        if (! is_dir(dirname($path))) {
            return null;
        }
        $lock = fopen($path, 'c');
        if ($lock === false) {
            throw new RuntimeException($errorMessage);
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
    public function release($lock, array $retainedFiles): void
    {
        if ($lock === null) {
            return;
        }

        $path = stream_get_meta_data($lock)['uri'];
        $inode = fstat($lock)['ino'];
        flock($lock, LOCK_UN);
        fclose($lock);
        clearstatcache(true, $path);
        $current = @stat($path);
        $directory = dirname($path);
        $occupied = false;
        foreach ($retainedFiles as $file) {
            $occupied = $occupied || is_file($directory.'/'.$file);
        }
        if ($current !== false && $current['ino'] === $inode && ! $occupied) {
            @unlink($path);
            @rmdir($directory);
        }
    }

    private function timestamp(mixed $value): ?string
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
}

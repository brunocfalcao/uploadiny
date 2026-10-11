<?php

declare(strict_types=1);

namespace App\Services;

use App\UploadImage;
use Closure;
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
        }
        $this->paths[] = $path;
        $temporary = $this->directory.'/journal.next';
        throw_unless($disk->put($temporary, json_encode($this->paths, JSON_THROW_ON_ERROR)) && $disk->move($temporary, $this->directory.'/journal.json'), RuntimeException::class, 'Private file recovery could not be saved.');
    }

    public function cleanupPending(): void
    {
        $disk = Storage::disk('local');
        foreach ($disk->directories('writing') as $directory) {
            if (! $disk->exists($directory.'/journal.json') && ! $disk->exists($directory.'/.lock')) {
                continue;
            }
            $lock = $this->acquire($directory);
            if ($lock === null) {
                continue;
            }
            try {
                $this->reconcile($directory);
            } catch (Throwable $error) {
                Log::warning('Interrupted private file write recovery deferred.', ['category' => class_basename($error)]);
            } finally {
                $this->release($lock);
            }
        }
    }

    private function finish(): void
    {
        try {
            if ($this->directory !== null) {
                if ($this->lock === null) {
                    $this->lock = $this->acquire($this->directory);
                    if ($this->lock === null) {
                        return;
                    }
                }
                $this->reconcile($this->directory);
            }
        } catch (Throwable $error) {
            // The durable journal retains ownership for the scheduler's next attempt.
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
            throw_unless($disk->delete($directory.'/journal.next'), RuntimeException::class, 'Private file preparation cleanup needs another attempt.');

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
        throw_unless($disk->delete($directory.'/journal.next') && $disk->delete($journal), RuntimeException::class, 'Private file recovery record cleanup needs another attempt.');
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
            if ($current !== false && $current['ino'] === $inode && ! is_file(dirname($path).'/journal.json') && ! is_file(dirname($path).'/journal.next')) {
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

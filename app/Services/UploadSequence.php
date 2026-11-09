<?php

declare(strict_types=1);

namespace App\Services;

use App\UploadImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class UploadSequence
{
    private const PATH = '.uploadiny-sequence';

    private const TEMPORARY_PATH = '.uploadiny-sequence.next';

    /**
     * Return the greatest upload number that has already been issued or persisted.
     *
     * A missing counter recovers surviving issued names and row IDs. Deleted
     * historical numbers require restoring the durable counter from trusted evidence. A present counter must
     * remain a valid canonical non-negative integer: resetting a damaged counter
     * could reuse a number whose row has since been deleted.
     */
    public function current(): int
    {
        $disk = Storage::disk('local');

        if (! $disk->exists(self::PATH)) {
            $counter = 0;
            foreach (UploadImage::query()->select('id', 'name')->lazyById() as $image) {
                if (preg_match('/\Aupload-([1-9][0-9]*)(?:\.[^\/]+)?\z/D', $image->name, $match) === 1) {
                    $issued = filter_var($match[1], FILTER_VALIDATE_INT);
                    if ($issued === false || $issued === PHP_INT_MAX) {
                        throw new RuntimeException($this->invalidCounterMessage());
                    }
                    $counter = max($counter, $issued);
                }
            }
        } else {
            try {
                $raw = $disk->get(self::PATH);
            } catch (Throwable $error) {
                throw new RuntimeException($this->invalidCounterMessage(), previous: $error);
            }

            if (! is_string($raw) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $raw) !== 1) {
                throw new RuntimeException($this->invalidCounterMessage());
            }

            $counter = filter_var($raw, FILTER_VALIDATE_INT);
            if ($counter === false || $counter < 0) {
                throw new RuntimeException($this->invalidCounterMessage());
            }
        }

        return max($counter, (int) (UploadImage::query()->max('id') ?? 0));
    }

    /**
     * Publish the high-water mark without exposing a partial live counter.
     */
    public function publish(int $sequence): void
    {
        $disk = Storage::disk('local');
        $value = (string) $sequence;

        try {
            if (! $disk->put(self::TEMPORARY_PATH, $value)) {
                throw new RuntimeException($this->saveFailureMessage());
            }

            try {
                $written = $disk->get(self::TEMPORARY_PATH);
            } catch (Throwable $error) {
                throw new RuntimeException($this->saveFailureMessage(), previous: $error);
            }

            if ($written !== $value || ! $disk->move(self::TEMPORARY_PATH, self::PATH)) {
                throw new RuntimeException($this->saveFailureMessage());
            }
        } finally {
            $disk->delete(self::TEMPORARY_PATH);
        }
    }

    private function invalidCounterMessage(): string
    {
        return 'The saved upload numbering needs recovery before another upload. Contact support before retrying.';
    }

    private function saveFailureMessage(): string
    {
        return 'The upload sequence could not be safely saved. The previous counter was kept; retry the operation.';
    }
}

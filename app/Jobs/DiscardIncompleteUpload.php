<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\WorkspaceDeletion;
use App\UploadChunk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class DiscardIncompleteUpload implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $chunkId) {}

    public function handle(WorkspaceDeletion $deletion): void
    {
        $chunk = UploadChunk::find($this->chunkId);
        if (! $chunk || $chunk->status !== 'uploading') {
            return;
        }
        if ($chunk->created_at->addDay()->isFuture()) {
            $this->release(86400);

            return;
        }
        try {
            $deletion->chunk($chunk);
        } catch (HttpException $error) {
            if ($error->getStatusCode() !== 409) {
                throw $error;
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}

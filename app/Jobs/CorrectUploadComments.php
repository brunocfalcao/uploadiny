<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\CommentCorrection;
use App\UploadImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CorrectUploadComments implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int $imageId, public string $originalComments, public int $feedbackRevision) {}

    public static function schedule(UploadImage $image): void
    {
        try {
            // The same-database queue row commits with its image. Materialize
            // dispatch here so enqueue failures cannot escape through a destructor.
            $pending = self::dispatch($image->id, $image->comments ?? '', (int) $image->feedback_revision)->beforeCommit();
            unset($pending);
        } catch (Throwable $error) {
            Log::warning('Comment correction enqueue failed.', ['image_id' => $image->id, 'category' => class_basename($error)]);
        }
    }

    public function handle(CommentCorrection $correction): void
    {
        $image = UploadImage::with('project')->find($this->imageId);
        if (! $image || trim($this->originalComments) === '' || ($image->comments ?? '') !== $this->originalComments || (int) $image->feedback_revision !== $this->feedbackRevision) {
            return;
        }
        try {
            $corrected = $correction->correct($this->originalComments, $image->project?->name, $image->project?->description);
        } catch (Throwable $error) {
            Log::warning('Comment correction failed.', ['image_id' => $image->id, 'category' => class_basename($error)]);

            return;
        }
        if ($corrected === trim($this->originalComments)) {
            return;
        }
        // Compare-and-replace under the same guard the website editor uses, so an
        // edit made after queueing always wins and bumps the revision for open editors.
        DB::transaction(function () use ($corrected): void {
            $locked = UploadImage::query()->lockForUpdate()->find($this->imageId);
            if (! $locked || ($locked->comments ?? '') !== $this->originalComments || (int) $locked->feedback_revision !== $this->feedbackRevision) {
                return;
            }
            $locked->update(['comments' => $corrected, 'feedback_revision' => $locked->feedback_revision + 1, 'feedback_updated_at' => now()]);
        });
    }
}

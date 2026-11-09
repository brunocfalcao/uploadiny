<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\VisionDescription;
use App\Services\VisionFailure;
use App\UploadImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DescribeUploadImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public ?string $attemptId = null;

    public function __construct(public int $imageId, ?string $attemptId = null)
    {
        $this->attemptId = $attemptId;
    }

    public static function schedule(int $imageId): void
    {
        $attemptId = (string) Str::uuid();
        if (! UploadImage::whereKey($imageId)->where('description_status', 'pending')->update(['description_attempt' => $attemptId])) {
            return;
        }
        try {
            // The same-database queue row commits with its image. Materialize
            // dispatch here so enqueue failures cannot escape through a destructor.
            $pending = self::dispatch($imageId, $attemptId)->beforeCommit();
            unset($pending);
        } catch (Throwable $error) {
            UploadImage::whereKey($imageId)->where('description_attempt', $attemptId)->where('description_status', 'pending')->update([
                'description_status' => 'failed',
                'description_error' => 'The description could not be queued. Try again later.',
            ]);
            Log::warning('Image description enqueue failed.', ['image_id' => $imageId, 'category' => class_basename($error)]);
        }
    }

    public function handle(VisionDescription $vision): void
    {
        $image = UploadImage::find($this->imageId);
        if (! $image || ! $image->isStillImage() || $image->description_status !== 'pending') {
            return;
        }
        $claimed = UploadImage::whereKey($image->id)->where('description_attempt', $this->attemptId)->where('description_status', 'pending')->update(['description_status' => 'processing', 'description_error' => null]);
        if (! $claimed) {
            return;
        }
        try {
            $description = $vision->describe($image);
            UploadImage::whereKey($image->id)->where('description_attempt', $this->attemptId)->where('description_status', 'processing')->update(['description' => $description, 'description_status' => 'ready', 'description_error' => null, 'description_model' => config('services.uploadiny.vision_model')]);
        } catch (Throwable $error) {
            Log::warning('Image description failed.', ['image_id' => $image->id, 'category' => $error instanceof VisionFailure ? $error->category : class_basename($error), 'http_status' => $error->getCode() >= 100 && $error->getCode() <= 599 ? $error->getCode() : null]);
            UploadImage::whereKey($image->id)->where('description_attempt', $this->attemptId)->where('description_status', 'processing')->update(['description_status' => 'failed', 'description_error' => $error instanceof VisionFailure ? $error->getMessage() : 'The vision service is unavailable. Try again later.']);
        }
    }

    public function failed(?Throwable $error): void
    {
        UploadImage::whereKey($this->imageId)->where('description_attempt', $this->attemptId)->whereIn('description_status', ['pending', 'processing'])->update(['description_status' => 'failed', 'description_error' => 'The description could not finish. Try again later.']);
    }
}

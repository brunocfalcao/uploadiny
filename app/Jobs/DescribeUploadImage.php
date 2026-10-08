<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\VisionDescription;
use App\Services\VisionFailure;
use App\UploadImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class DescribeUploadImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int $imageId) {}

    public static function schedule(int $imageId): void
    {
        try {
            // Materialize dispatch here so enqueue failures cannot escape through a destructor.
            $pending = self::dispatch($imageId)->afterCommit();
            unset($pending);
        } catch (Throwable $error) {
            UploadImage::whereKey($imageId)->where('description_status', 'pending')->update([
                'description_status' => 'failed',
                'description_error' => 'The description could not be queued. Try again later.',
            ]);
            Log::warning('Image description enqueue failed.', ['image_id' => $imageId, 'category' => class_basename($error)]);
        }
    }

    public function handle(VisionDescription $vision): void
    {
        $image = UploadImage::find($this->imageId);
        if (! $image || $image->isVideo() || $image->description_status !== 'pending') {
            return;
        }
        $claimed = UploadImage::whereKey($image->id)->where('description_status', 'pending')->update(['description_status' => 'processing', 'description_error' => null]);
        if (! $claimed) {
            return;
        }
        try {
            $description = $vision->describe($image);
            $image->update(['description' => $description, 'description_status' => 'ready', 'description_error' => null, 'description_model' => config('services.uploadiny.vision_model')]);
        } catch (Throwable $error) {
            Log::warning('Image description failed.', ['image_id' => $image->id, 'category' => $error instanceof VisionFailure ? $error->category : class_basename($error), 'http_status' => $error->getCode() >= 100 && $error->getCode() <= 599 ? $error->getCode() : null]);
            $image->update(['description_status' => 'failed', 'description_error' => $error instanceof VisionFailure ? $error->getMessage() : 'The vision service is unavailable. Try again later.']);
        }
    }

    public function failed(?Throwable $error): void
    {
        UploadImage::whereKey($this->imageId)->update(['description_status' => 'failed', 'description_error' => 'The description could not finish. Try again later.']);
    }
}

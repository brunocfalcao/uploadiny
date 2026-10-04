<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\VisionDescription;
use App\UploadImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DescribeUploadImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int $imageId) {}

    public function handle(VisionDescription $vision): void
    {
        $image = UploadImage::find($this->imageId);
        if (! $image || $image->isVideo() || $image->description_status !== 'pending') {
            return;
        }
        $image->update(['description_status' => 'processing', 'description_error' => null]);
        try {
            $description = $vision->describe($image);
            $image->update(['description' => $description, 'description_status' => 'ready', 'description_error' => null, 'description_model' => config('services.uploadiny.vision_model')]);
        } catch (Throwable $error) {
            $image->update(['description_status' => 'failed', 'description_error' => $error instanceof \RuntimeException ? $error->getMessage() : 'The vision service is unavailable. Try again later.']);
        }
    }

    public function failed(?Throwable $error): void
    {
        UploadImage::whereKey($this->imageId)->update(['description_status' => 'failed', 'description_error' => 'The description could not finish. Try again later.']);
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DescribeUploadImage;
use App\Project;
use App\UploadChunk;
use App\UploadImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ImageDuplicator
{
    public function __construct(private ImageCopy $copies) {}

    /** Copies the untouched original screenshot or privacy report into the same chunk as the last file, with no feedback. */
    public function duplicate(UploadImage $image): UploadImage
    {
        abort_if($image->isVideo(), 422, 'Only still images and privacy reports can be duplicated.');

        return Cache::lock('uploadiny:upload-sequence', 600)->block(30, function () use ($image): UploadImage {
            $copy = app(PrivateFileWrites::class)->transaction(function (PrivateFileWrites $writes) use ($image): UploadImage {
                Project::query()->lockForUpdate()->findOrFail($image->project_id);
                $source = UploadImage::query()->lockForUpdate()->findOrFail($image->id);
                $chunk = UploadChunk::query()->lockForUpdate()->findOrFail($source->chunk_id);
                abort_unless($chunk->status === 'complete', 409, 'This upload chunk is not complete.');
                abort_unless(Storage::disk('local')->exists($source->path), 404, 'The original file is missing.');

                $copy = $this->copies->intoChunk($source, $chunk, $source->project_id, $writes, clean: true);
                if ($copy->description_status === 'pending') {
                    DescribeUploadImage::schedule($copy->id);
                }

                return $copy;
            });

            return $copy;
        });
    }
}

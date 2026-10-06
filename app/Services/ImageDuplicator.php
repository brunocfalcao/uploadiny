<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DescribeUploadImage;
use App\Project;
use App\UploadChunk;
use App\UploadImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImageDuplicator
{
    public function __construct(private ImageCopy $copies) {}

    /** Copies the untouched original into the same chunk as the last file, with no feedback. */
    public function duplicate(UploadImage $image): UploadImage
    {
        abort_if($image->isVideo(), 422, 'Only still images can be duplicated.');

        return Cache::lock('uploadiny:upload-sequence', 600)->block(30, function () use ($image): UploadImage {
            $paths = [];
            try {
                $copy = DB::transaction(function () use ($image, &$paths): UploadImage {
                    Project::query()->lockForUpdate()->findOrFail($image->project_id);
                    $source = UploadImage::query()->lockForUpdate()->findOrFail($image->id);
                    $chunk = UploadChunk::query()->lockForUpdate()->findOrFail($source->chunk_id);
                    abort_unless($chunk->status === 'complete', 409, 'This upload chunk is not complete.');
                    abort_unless(Storage::disk('local')->exists($source->path), 404, 'The original file is missing.');

                    return $this->copies->intoChunk($source, $chunk, $source->project_id, $paths, clean: true);
                });
            } catch (Throwable $error) {
                Storage::disk('local')->delete($paths);
                throw $error;
            }
            if ($copy->description_status === 'pending') {
                DescribeUploadImage::dispatch($copy->id)->afterCommit();
            }

            return $copy;
        });
    }
}

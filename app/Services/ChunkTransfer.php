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

class ChunkTransfer
{
    public function __construct(private ImageCopy $copies) {}

    public function transfer(UploadImage $image, ?string $chunkUuid, int $projectId, string $action): UploadImage
    {
        return Cache::lock('uploadiny:upload-sequence', 600)->block(30, function () use ($image, $chunkUuid, $projectId, $action): UploadImage {
            $paths = [];
            try {
                $result = DB::transaction(function () use ($image, $chunkUuid, $projectId, $action, &$paths): UploadImage {
                    Project::query()->lockForUpdate()->findOrFail($projectId);
                    if ($chunkUuid === null) {
                        $target = UploadChunk::create(['upload_project_id' => $projectId, 'status' => 'complete', 'expected_images' => 1, 'completed_at' => now()]);
                    } else {
                        $target = UploadChunk::query()->where('uuid', $chunkUuid)->lockForUpdate()->firstOrFail();
                        abort_unless($target->status === 'complete', 422, 'Choose a completed upload chunk.');
                        abort_unless($target->images()->where('project_id', $projectId)->exists(), 422, 'Choose an existing upload chunk in that project.');
                    }
                    $source = UploadImage::query()->lockForUpdate()->findOrFail($image->id);
                    if ($action === 'move') {
                        $originalChunkId = $source->chunk_id;
                        $source->update(['chunk_id' => $target->id, 'project_id' => $projectId]);
                        if ($originalChunkId !== $target->id) {
                            UploadChunk::whereKey($originalChunkId)->where('status', 'complete')->whereDoesntHave('images')->delete();
                        }

                        return $source;
                    }

                    return $this->copies->intoChunk($source, $target, $projectId, $paths);
                });
            } catch (Throwable $error) {
                Storage::disk('local')->delete($paths);
                throw $error;
            }
            if ($action === 'copy' && $result->description_status === 'pending') {
                DescribeUploadImage::dispatch($result->id)->afterCommit();
            }

            return $result;
        });
    }
}

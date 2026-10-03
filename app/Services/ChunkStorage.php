<?php

declare(strict_types=1);

namespace App\Services;

use App\Project;
use App\UploadChunk;
use App\UploadImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ChunkStorage
{
    /** @param array<int, UploadedFile> $files */
    public function store(Project $project, array $files, ?UploadChunk $draft = null): UploadChunk
    {
        return Cache::lock('uploadiny:upload-sequence', 600)->block(30, function () use ($project, $files, $draft): UploadChunk {
            $paths = [];
            try {
                return DB::transaction(function () use ($project, $files, $draft, &$paths): UploadChunk {
                    // Serialize project deletion and group creation around the same project record.
                    $project = Project::query()->lockForUpdate()->findOrFail($project->id);
                    $sequence = max((int) Storage::disk('local')->get('.uploadiny-sequence'), (int) UploadImage::query()->max('id'));
                    $chunk = $draft ? UploadChunk::query()->lockForUpdate()->findOrFail($draft->id) : UploadChunk::create(['completed_at' => now()]);
                    if ($draft) {
                        abort_unless($chunk->status === 'uploading' && $chunk->upload_project_id === $project->id, 409, 'This chunk is no longer accepting images.');
                        abort_if($chunk->images()->count() + count($files) > $chunk->expected_images, 409, 'This chunk already contains its expected images.');
                    }
                    foreach ($files as $file) {
                        $uuid = (string) Str::uuid();
                        $extension = strtolower($file->getClientOriginalExtension());
                        $extension = preg_replace('/[^a-z0-9]/', '', $extension);
                        $name = 'upload-'.(++$sequence).($extension ? '.'.$extension : '');
                        $path = $file->storeAs('images', $uuid.'__'.$name, 'local');
                        if (! is_string($path)) {
                            throw new RuntimeException('The image could not be stored.');
                        }
                        $paths[] = $path;
                        $chunk->images()->create([
                            'uuid' => $uuid, 'project_id' => $project->id, 'name' => $name,
                            'original_name' => $file->getClientOriginalName(), 'path' => $path,
                            'mime_type' => $file->getMimeType() ?? 'application/octet-stream', 'size' => $file->getSize(),
                            'annotations' => [], 'comments' => '', 'description_status' => 'pending',
                        ]);
                    }
                    if (! Storage::disk('local')->put('.uploadiny-sequence', (string) $sequence)) {
                        throw new RuntimeException('The upload sequence could not be saved.');
                    }

                    return $chunk->load('images');
                });
            } catch (Throwable $error) {
                Storage::disk('local')->delete($paths);
                throw $error;
            }
        });
    }
}

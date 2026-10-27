<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DescribeUploadImage;
use App\Project;
use App\UploadChunk;
use App\UploadImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class ChunkStorage
{
    /**
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, string>  $comments
     */
    public function store(Project $project, array $files, ?UploadChunk $draft = null, array $comments = []): UploadChunk
    {
        return Cache::lock('uploadiny:upload-sequence', 600)->block(30, function () use ($project, $files, $draft, $comments): UploadChunk {
            return app(PrivateFileWrites::class)->transaction(function (PrivateFileWrites $writes) use ($project, $files, $draft, $comments): UploadChunk {
                // Serialize project deletion and group creation around the same project record.
                $project = Project::query()->lockForUpdate()->findOrFail($project->id);
                $sequence = app(UploadSequence::class)->current();
                $chunk = $draft ? UploadChunk::query()->lockForUpdate()->findOrFail($draft->id) : UploadChunk::create(['completed_at' => now()]);
                if ($draft) {
                    abort_unless($chunk->status === 'uploading' && $chunk->upload_project_id === $project->id, 409, 'This chunk is no longer accepting images.');
                    abort_if($chunk->images()->count() + count($files) > $chunk->expected_images, 409, 'This chunk already contains its expected images.');
                }
                $reports = app(PrivacyReport::class);
                foreach ($files as $index => $file) {
                    $uuid = (string) Str::uuid();
                    $extension = strtolower($file->getClientOriginalExtension());
                    $extension = preg_replace('/[^a-z0-9]/', '', $extension);
                    $name = 'upload-'.(++$sequence).($extension ? '.'.$extension : '');
                    $path = 'images/'.$uuid.'__'.$name;
                    $writes->track($path);
                    $stored = $file->storeAs('images', $uuid.'__'.$name, 'local');
                    if (! is_string($stored)) {
                        throw new RuntimeException('The image could not be stored.');
                    }
                    $report = $reports->accepts($file);
                    $mimeType = $report ? UploadImage::PRIVACY_REPORT_MIME_TYPE : ($file->getMimeType() ?? 'application/octet-stream');
                    $chunk->images()->create([
                        'uuid' => $uuid, 'project_id' => $project->id, 'name' => $name,
                        'original_name' => $file->getClientOriginalName(), 'path' => $path,
                        'mime_type' => $mimeType, 'size' => $file->getSize(),
                        'annotations' => [], 'comments' => $comments[$index] ?? '',
                        'description_status' => $report || str_starts_with($mimeType, 'video/') ? 'not_applicable' : 'pending',
                    ]);
                }
                app(UploadSequence::class)->publish($sequence);

                if ($draft === null) {
                    foreach ($chunk->images->where('description_status', 'pending') as $image) {
                        DescribeUploadImage::schedule($image->id);
                    }
                }

                return $chunk->load('images');
            });
        });
    }
}

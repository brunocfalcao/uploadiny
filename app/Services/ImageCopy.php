<?php

declare(strict_types=1);

namespace App\Services;

use App\UploadChunk;
use App\UploadImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImageCopy
{
    /** @param array<int, string> $paths */
    public function intoChunk(UploadImage $source, UploadChunk $target, int $projectId, array &$paths, bool $clean = false): UploadImage
    {
        $disk = Storage::disk('local');
        $sequence = max((int) $disk->get('.uploadiny-sequence'), (int) UploadImage::query()->max('id')) + 1;
        $uuid = (string) Str::uuid();
        $name = 'upload-'.$sequence.'.'.pathinfo($source->name, PATHINFO_EXTENSION);
        $path = 'images/'.$uuid.'__'.$name;
        $annotated = $source->annotated_path && ! $clean ? 'annotations/'.$uuid.'-'.Str::uuid().'.png' : null;
        $copies = [$source->path => $path];
        if ($annotated) {
            $copies[$source->annotated_path] = $annotated;
        }
        foreach ($copies as $original => $destination) {
            $paths[] = $destination;
            throw_unless($disk->copy($original, $destination), RuntimeException::class, 'The file could not be copied. The original remains unchanged.');
        }
        $fresh = $clean ? ['annotations' => [], 'comments' => '', 'feedback_updated_at' => null] : [];
        $copy = $source->replicate()->fill($fresh + [
            'uuid' => $uuid, 'name' => $name, 'path' => $path, 'annotated_path' => $annotated,
            'chunk_id' => $target->id, 'project_id' => $projectId, 'feedback_revision' => 0,
            'description_status' => $source->description_status === 'processing' ? 'pending' : $source->description_status,
        ]);
        $copy->save();
        throw_unless($disk->put('.uploadiny-sequence', (string) $sequence), RuntimeException::class, 'The upload sequence could not be saved.');

        return $copy;
    }
}

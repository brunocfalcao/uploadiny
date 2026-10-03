<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Project;
use App\UploadChunk;
use App\UploadImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UploadImageFactory extends Factory
{
    protected $model = UploadImage::class;

    public function definition(): array
    {
        return ['uuid' => (string) Str::uuid(), 'project_id' => Project::factory(), 'chunk_id' => UploadChunk::factory(), 'name' => 'upload-1.png', 'original_name' => 'screenshot.png', 'path' => 'images/'.Str::uuid().'.png', 'mime_type' => 'image/png', 'size' => 10, 'annotations' => [], 'comments' => '', 'description_status' => 'pending'];
    }
}

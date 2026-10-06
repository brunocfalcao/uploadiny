<?php

declare(strict_types=1);

namespace App;

use Database\Factories\UploadImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property int $chunk_id
 * @property string $name
 * @property string $original_name
 * @property string $path
 * @property string $mime_type
 * @property int $size
 * @property int $feedback_revision
 * @property array<int, array<string, mixed>>|null $annotations
 * @property string|null $comments
 * @property Carbon|null $feedback_updated_at
 * @property string|null $annotated_path
 * @property string|null $description
 * @property string $description_status
 * @property string|null $description_error
 * @property string|null $description_model
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Project $project
 * @property-read UploadChunk $chunk
 */
class UploadImage extends Model
{
    /** @use HasFactory<UploadImageFactory> */
    use HasFactory;

    protected $fillable = ['uuid', 'project_id', 'chunk_id', 'name', 'original_name', 'path', 'mime_type', 'size', 'annotations', 'comments', 'description', 'description_status', 'description_error', 'description_model', 'annotated_path', 'feedback_revision', 'feedback_updated_at'];

    protected function casts(): array
    {
        return ['annotations' => 'array', 'size' => 'integer', 'feedback_revision' => 'integer', 'feedback_updated_at' => 'datetime'];
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<UploadChunk, $this> */
    public function chunk(): BelongsTo
    {
        return $this->belongsTo(UploadChunk::class, 'chunk_id');
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     original_name: string,
     *     mime_type: string,
     *     media_type: string,
     *     size: int,
     *     image_url: string,
     *     annotated_image_url: string|null,
     *     revision: int,
     *     annotations: array<int, array<string, mixed>>,
     *     comments: string,
     *     description: string|null,
     *     description_status: string,
     *     description_error: string|null,
     *     description_model: string|null,
     *     uploaded_at: string
     * }
     */
    public function agentData(): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'media_type' => $this->isVideo() ? 'video' : 'image',
            'size' => $this->size,
            'image_url' => route('api.images.download', $this),
            'annotated_image_url' => $this->annotated_path ? route('api.images.annotated', $this) : null,
            'revision' => $this->feedback_revision,
            'annotations' => $this->annotations ?? [],
            'comments' => $this->comments ?? '',
            'description' => $this->description,
            'description_status' => $this->description_status,
            'description_error' => $this->description_error,
            'description_model' => $this->description_model,
            'uploaded_at' => $this->created_at->toIso8601String(),
        ];
    }
}

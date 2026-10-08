<?php

declare(strict_types=1);

namespace App;

use Database\Factories\UploadChunkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $upload_project_id
 * @property string $status
 * @property int|null $expected_images
 * @property int|null $append_to_chunk_id
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, UploadImage> $images
 */
class UploadChunk extends Model
{
    /** @use HasFactory<UploadChunkFactory> */
    use HasFactory;

    protected $fillable = ['uuid', 'upload_project_id', 'append_to_chunk_id', 'status', 'expected_images', 'completed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'expected_images' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (UploadChunk $chunk): void {
            $chunk->uuid ??= (string) Str::uuid();
        });
    }

    /** @param Builder<UploadChunk> $query */
    public function scopeNewestFinishedFirst(Builder $query): void
    {
        $query->orderByRaw('COALESCE(completed_at, created_at) DESC')->orderByDesc('id');
    }

    /** @param \Illuminate\Support\Collection<int, UploadImage>|null $images */
    public function reviewToken(Project $project, ?\Illuminate\Support\Collection $images = null): string
    {
        $images ??= $this->images()->where('project_id', $project->id)->orderBy('id')->get();

        return hash('sha256', json_encode([
            $this->uuid, $project->canonical, $this->completed_at?->toIso8601String(),
            $images->sortBy('id')->map(fn (UploadImage $image): array => $image->only([
                'uuid', 'project_id', 'feedback_revision', 'comments', 'annotations',
                'description', 'description_status', 'description_error', 'updated_at',
            ]))->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function uploadedAt(): Carbon
    {
        return $this->completed_at ?? $this->created_at;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return HasMany<UploadImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(UploadImage::class, 'chunk_id');
    }
}

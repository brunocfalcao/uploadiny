<?php

declare(strict_types=1);

namespace App;

use Database\Factories\UploadChunkFactory;
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
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, UploadImage> $images
 */
class UploadChunk extends Model
{
    /** @use HasFactory<UploadChunkFactory> */
    use HasFactory;

    protected $fillable = ['uuid', 'upload_project_id', 'status', 'expected_images', 'completed_at'];

    protected static function booted(): void
    {
        static::creating(function (UploadChunk $chunk): void {
            $chunk->uuid ??= (string) Str::uuid();
        });
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

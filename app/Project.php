<?php

declare(strict_types=1);

namespace App;

use Database\Factories\ProjectFactory;
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
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, UploadImage> $images
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug', 'description', 'uuid'];

    protected static function booted(): void
    {
        static::creating(function (Project $project): void {
            $project->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<UploadImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(UploadImage::class);
    }

    /** @return Builder<UploadChunk> */
    public function chunks(): Builder
    {
        return UploadChunk::query()->where('status', 'complete')->whereHas('images', fn ($query) => $query->where('project_id', $this->id));
    }
}

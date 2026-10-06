<?php

declare(strict_types=1);

namespace App\Services;

use App\Project;
use App\UploadImage;

final class FeedbackReader
{
    /** @return array<string, mixed> */
    public function projects(): array
    {
        return ['projects' => Project::withCount(['images' => fn ($query) => $query->whereHas('chunk', fn ($chunk) => $chunk->where('status', 'complete'))])
            ->orderBy('name')->get()->map(fn (Project $project) => [
                'id' => $project->id, 'name' => $project->name, 'slug' => $project->slug,
                'canonical' => $project->canonical, 'description' => $project->description,
                'image_count' => $project->images_count,
            ])->all()];
    }

    /** @return array<string, mixed> */
    public function latest(Project $project): array
    {
        $chunk = $project->chunks()->with(['images' => fn ($query) => $query->where('project_id', $project->id)->orderBy('id')])->orderByDesc('id')->first();

        return [
            'project' => ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug, 'canonical' => $project->canonical],
            'chunk' => $chunk ? ['id' => $chunk->uuid, 'uploaded_at' => $chunk->created_at->toIso8601String(), 'images' => $chunk->images->map(static fn (UploadImage $image): array => $image->agentData())->values()->all()] : null,
        ];
    }
}

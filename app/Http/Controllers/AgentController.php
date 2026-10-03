<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Project;
use App\UploadImage;
use Illuminate\Http\JsonResponse;

class AgentController extends Controller
{
    public function projects(): JsonResponse
    {
        return response()->json(['projects' => Project::withCount(['images' => fn ($query) => $query->whereHas('chunk', fn ($chunk) => $chunk->where('status', 'complete'))])->orderBy('name')->get()->map(fn (Project $project) => ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug, 'description' => $project->description, 'image_count' => $project->images_count])]);
    }

    public function latest(Project $project): JsonResponse
    {
        $chunk = $project->chunks()->with(['images' => fn ($query) => $query->where('project_id', $project->id)->orderBy('id')])->orderByDesc('id')->first();

        return response()->json([
            'project' => ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug],
            'chunk' => $chunk ? ['id' => $chunk->uuid, 'uploaded_at' => $chunk->created_at->toIso8601String(), 'images' => $chunk->images->map(static fn (UploadImage $image): array => $image->agentData())->values()] : null,
        ]);
    }
}

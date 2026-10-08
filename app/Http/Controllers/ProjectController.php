<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Project;
use App\Services\WorkspaceDeletion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(): View
    {
        return view('workspace', ['projects' => Project::withCount(['images' => fn ($query) => $query->whereHas('chunk', fn ($chunk) => $chunk->where('status', 'complete'))])->orderBy('name')->get(), 'project' => null, 'chunks' => collect()]);
    }

    public function show(Request $request, Project $project): View
    {
        $chunks = $project->chunks()->with(['images' => fn ($query) => $query->where('project_id', $project->id)->orderBy('id')
            ->select(['id', 'uuid', 'chunk_id', 'project_id', 'name', 'mime_type'])
            ->selectRaw("CASE WHEN COALESCE(comments, '') <> '' OR (annotations IS NOT NULL AND annotations <> '[]' AND annotations <> 'null') THEN 1 ELSE 0 END AS has_feedback")])->newestFinishedFirst()->simplePaginate(24);
        $requested = $request->query('image');
        $openImage = is_string($requested) ? $project->images()->where('uuid', $requested)->whereHas('chunk', fn ($query) => $query->where('status', 'complete'))->first() : null;
        $openChunk = $openImage ? $project->chunks()->whereKey($openImage->chunk_id)->with(['images' => fn ($query) => $query->where('project_id', $project->id)->orderBy('id')->select(['id', 'uuid', 'chunk_id', 'project_id'])])->first() : null;
        $latestChunk = $project->chunks()->withCount(['images' => fn ($query) => $query->where('project_id', $project->id)])->newestFinishedFirst()->first();

        return view('workspace', ['projects' => Project::withCount(['images' => fn ($query) => $query->whereHas('chunk', fn ($chunk) => $chunk->where('status', 'complete'))])->orderBy('name')->get(), 'project' => $project, 'chunks' => $chunks, 'openImage' => $openImage, 'openChunk' => $openChunk, 'latestChunk' => $latestChunk]);
    }

    public function store(ProjectRequest $request): RedirectResponse
    {
        $project = Project::create($request->validated());

        return redirect()->route('projects.show', $project)->with('status', 'Project created.');
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());

        return redirect()->route('projects.show', $project)->with('status', 'Project saved.');
    }

    public function destroy(Project $project, WorkspaceDeletion $deletion): RedirectResponse
    {
        $deletion->project($project);

        return redirect()->route('projects.index')->with('status', 'Project and its images deleted.');
    }
}

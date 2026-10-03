<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Project;
use App\Services\WorkspaceDeletion;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(): View
    {
        return view('workspace', ['projects' => Project::withCount(['images' => fn ($query) => $query->whereHas('chunk', fn ($chunk) => $chunk->where('status', 'complete'))])->orderBy('name')->get(), 'project' => null, 'chunks' => collect()]);
    }

    public function show(Project $project): View
    {
        $chunks = $project->chunks()->with(['images' => fn ($query) => $query->where('project_id', $project->id)->orderBy('id')])->orderByDesc('id')->get();

        return view('workspace', ['projects' => Project::withCount(['images' => fn ($query) => $query->whereHas('chunk', fn ($chunk) => $chunk->where('status', 'complete'))])->orderBy('name')->get(), 'project' => $project, 'chunks' => $chunks]);
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

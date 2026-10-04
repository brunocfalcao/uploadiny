<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AppendChunkImageRequest;
use App\Http\Requests\ChunkUploadRequest;
use App\Http\Requests\StartChunkRequest;
use App\Jobs\DescribeUploadImage;
use App\Jobs\DiscardIncompleteUpload;
use App\Project;
use App\Services\ChunkStorage;
use App\Services\WorkspaceDeletion;
use App\UploadChunk;
use App\UploadImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ChunkController extends Controller
{
    public function index(): JsonResponse
    {
        $projects = Project::query()->pluck('name', 'id');
        $chunks = UploadChunk::query()->where('status', 'complete')->with('images:id,chunk_id,project_id')->orderByDesc('id')->get();
        $destinations = $chunks->flatMap(static fn (UploadChunk $chunk) => $chunk->images->pluck('project_id')->unique()->map(static fn (int $projectId): array => [
            'chunk_id' => $chunk->uuid,
            'project_id' => $projectId,
            'project_name' => $projects[$projectId],
            'uploaded_at' => $chunk->created_at->toIso8601String(),
            'file_count' => $chunk->images->where('project_id', $projectId)->count(),
        ]))->values();

        return response()->json(['destinations' => $destinations]);
    }

    public function store(ChunkUploadRequest $request, Project $project, ChunkStorage $storage): JsonResponse
    {
        $chunk = $storage->store($project, $request->file('files'));
        foreach ($chunk->images->where('description_status', 'pending') as $image) {
            DescribeUploadImage::dispatch($image->id)->afterCommit();
        }

        return response()->json(['id' => $chunk->uuid, 'uploaded_at' => $chunk->created_at->toIso8601String(), 'images' => $chunk->images->map(static fn (UploadImage $image): array => $image->agentData())->values()], 201);
    }

    public function start(StartChunkRequest $request, Project $project): JsonResponse
    {
        $chunk = UploadChunk::create(['upload_project_id' => $project->id, 'expected_images' => $request->integer('image_count'), 'status' => 'uploading']);

        DiscardIncompleteUpload::dispatch($chunk->id)->delay(now()->addDay())->afterCommit();

        return response()->json(['id' => $chunk->uuid], 201);
    }

    public function append(AppendChunkImageRequest $request, UploadChunk $chunk, ChunkStorage $storage): JsonResponse
    {
        abort_unless($chunk->status === 'uploading' && $chunk->upload_project_id, 409);
        $project = Project::findOrFail($chunk->upload_project_id);
        $storage->store($project, [$request->file('file')], $chunk);

        return response()->json(['received_images' => $chunk->images()->count()], 201);
    }

    public function complete(UploadChunk $chunk): JsonResponse
    {
        $completed = DB::transaction(function () use ($chunk): UploadChunk {
            $locked = UploadChunk::query()->lockForUpdate()->findOrFail($chunk->id);
            abort_unless($locked->status === 'uploading', 409, 'This chunk has already finished.');
            abort_unless($locked->images()->count() === $locked->expected_images, 409, 'Some images are missing. This chunk has not been published.');
            $locked->update(['status' => 'complete', 'completed_at' => now()]);

            return $locked->load('images');
        });
        foreach ($completed->images->where('description_status', 'pending') as $image) {
            DescribeUploadImage::dispatch($image->id)->afterCommit();
        }

        return response()->json(['id' => $completed->uuid, 'uploaded_at' => $completed->created_at->toIso8601String(), 'images' => $completed->images->map(static fn (UploadImage $image): array => $image->agentData())->values()]);
    }

    public function cancel(UploadChunk $chunk, WorkspaceDeletion $deletion): JsonResponse
    {
        $deletion->chunk($chunk);

        return response()->json(['message' => 'Incomplete upload discarded.']);
    }
}

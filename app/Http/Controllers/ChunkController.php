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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChunkController extends Controller
{
    public function index(): JsonResponse
    {
        $projects = Project::query()->pluck('name', 'id');
        $counts = UploadImage::query()
            ->selectRaw('chunk_id, project_id, count(*) as file_count')
            ->whereHas('chunk', static fn ($query) => $query->where('status', 'complete'))
            ->groupBy('chunk_id', 'project_id')
            ->orderBy('project_id')
            ->get()
            ->groupBy('chunk_id');
        $chunks = UploadChunk::query()->whereKey($counts->keys())->newestFinishedFirst()->get(['id', 'uuid', 'created_at', 'completed_at']);
        $destinations = $chunks->flatMap(static fn (UploadChunk $chunk) => $counts[$chunk->id]->map(static fn (UploadImage $row): array => [
            'chunk_id' => $chunk->uuid,
            'project_id' => $row->project_id,
            'project_name' => $projects[$row->project_id],
            'uploaded_at' => $chunk->uploadedAt()->toIso8601String(),
            'file_count' => (int) $row->getAttribute('file_count'),
        ]))->values();

        return response()->json(['destinations' => $destinations]);
    }

    public function last(Project $project): JsonResponse
    {
        $chunk = $project->chunks()->withCount(['images' => fn ($query) => $query->where('project_id', $project->id)])->newestFinishedFirst()->first();

        return response()->json(['chunk' => $chunk ? ['id' => $chunk->uuid, 'completed_at' => $chunk->completed_at?->toIso8601String() ?? $chunk->created_at->toIso8601String(), 'file_count' => $chunk->images_count] : null]);
    }

    public function store(ChunkUploadRequest $request, Project $project, ChunkStorage $storage): JsonResponse
    {
        $chunk = $storage->store($project, $request->file('files'));
        foreach ($chunk->images->where('description_status', 'pending') as $image) {
            DescribeUploadImage::schedule($image->id);
        }

        return response()->json(['id' => $chunk->uuid, 'uploaded_at' => $chunk->uploadedAt()->toIso8601String(), 'images' => $chunk->images->map(static fn (UploadImage $image): array => $image->agentData())->values()], 201);
    }

    public function start(StartChunkRequest $request, Project $project): JsonResponse
    {
        $target = $request->filled('append_to') ? $project->chunks()->where('uuid', $request->string('append_to')->toString())->first() : null;
        $chunk = UploadChunk::create(['upload_project_id' => $project->id, 'append_to_chunk_id' => $target?->id, 'expected_images' => $request->integer('image_count'), 'status' => 'uploading']);

        try {
            DiscardIncompleteUpload::dispatch($chunk->id)->delay(now()->addDay())->afterCommit();
        } catch (\Throwable $error) {
            $chunk->delete();
            throw $error;
        }

        return response()->json(['id' => $chunk->uuid], 201);
    }

    public function append(AppendChunkImageRequest $request, UploadChunk $chunk, ChunkStorage $storage): JsonResponse
    {
        abort_unless($chunk->status === 'uploading' && $chunk->upload_project_id, 409);
        $project = Project::findOrFail($chunk->upload_project_id);
        $stored = $storage->store($project, [$request->file('file')], $chunk, [$request->validated('comments') ?? '']);

        return response()->json([
            'received_images' => $stored->images->count(),
            'image' => $stored->images->sortBy('id')->last()->agentData(),
        ], 201);
    }

    public function complete(Request $request, UploadChunk $chunk): JsonResponse
    {
        $newImageIds = [];
        $completed = DB::transaction(function () use ($chunk, &$newImageIds): UploadChunk {
            if ($chunk->upload_project_id) {
                Project::query()->lockForUpdate()->find($chunk->upload_project_id);
            }
            $locked = UploadChunk::query()->lockForUpdate()->findOrFail($chunk->id);
            abort_unless($locked->status === 'uploading', 409, 'This chunk has already finished.');
            abort_unless($locked->images()->count() === $locked->expected_images, 409, 'Some images are missing. This chunk has not been published.');
            $target = $locked->append_to_chunk_id ? UploadChunk::query()->lockForUpdate()->whereKey($locked->append_to_chunk_id)->where('status', 'complete')->whereHas('images', fn ($query) => $query->where('project_id', $locked->upload_project_id))->first() : null;
            $newImageIds = $locked->images()->pluck('id')->all();
            if ($target) {
                $locked->images()->update(['chunk_id' => $target->id]);
                $target->update(['completed_at' => now()]);
                $locked->delete();

                return $target->load('images');
            }
            $locked->update(['status' => 'complete', 'completed_at' => now()]);

            return $locked->load('images');
        });
        foreach ($completed->images->whereIn('id', $newImageIds)->where('description_status', 'pending') as $image) {
            DescribeUploadImage::schedule($image->id);
        }

        $images = $request->routeIs('api.chunks.complete') ? $completed->images->whereIn('id', $newImageIds) : $completed->images;

        return response()->json(['id' => $completed->uuid, 'uploaded_at' => $completed->uploadedAt()->toIso8601String(), 'images' => $images->map(static fn (UploadImage $image): array => $image->agentData())->values()]);
    }

    public function cancel(UploadChunk $chunk, WorkspaceDeletion $deletion): JsonResponse
    {
        $deletion->chunk($chunk);

        return response()->json(['message' => 'Incomplete upload discarded.']);
    }
}

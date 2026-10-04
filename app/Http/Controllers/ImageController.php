<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ImageFeedbackRequest;
use App\Http\Requests\MoveImageRequest;
use App\Http\Requests\TransferChunkImageRequest;
use App\Jobs\DescribeUploadImage;
use App\Project;
use App\Services\ChunkTransfer;
use App\Services\WorkspaceDeletion;
use App\UploadImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImageController extends Controller
{
    public function show(UploadImage $image): JsonResponse
    {
        return response()->json($image->agentData() + ['preview_url' => route('images.preview', $image)]);
    }

    public function original(Request $request, UploadImage $image): BinaryFileResponse
    {
        $path = Storage::disk('local')->path($image->path);
        abort_unless(is_file($path), 404);
        $headers = ['Content-Type' => $image->mime_type, 'X-Content-Type-Options' => 'nosniff'];
        $response = $request->routeIs('images.preview') ? response()->file($path, $headers) : response()->download($path, $image->name, $headers);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function annotated(UploadImage $image): BinaryFileResponse
    {
        abort_unless($image->annotated_path && Storage::disk('local')->exists($image->annotated_path), 404);

        $response = response()->download(Storage::disk('local')->path($image->annotated_path), pathinfo($image->name, PATHINFO_FILENAME).'-annotated.png', ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff']);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function update(ImageFeedbackRequest $request, UploadImage $image): JsonResponse
    {
        $data = $request->validated();
        if ($image->isVideo() && ($data['annotations'] !== [] || isset($data['annotated_image']))) {
            throw ValidationException::withMessages(['annotations' => 'Use written feedback for recordings.']);
        }
        $raster = null;
        if (is_string($data['annotated_image'] ?? null)) {
            $prefix = 'data:image/png;base64,';
            if (! str_starts_with($data['annotated_image'], $prefix)) {
                throw ValidationException::withMessages(['annotated_image' => 'The annotated image must be a PNG.']);
            }
            $raster = base64_decode(substr($data['annotated_image'], strlen($prefix)), true);
            $dimensions = is_string($raster) ? @getimagesizefromstring($raster) : false;
            if (! $dimensions || $dimensions['mime'] !== 'image/png' || $dimensions[0] * $dimensions[1] > 24000000) {
                throw ValidationException::withMessages(['annotated_image' => 'The annotated image is invalid or too large.']);
            }
        }
        DB::transaction(function () use ($data, $raster, $image): void {
            $locked = UploadImage::query()->lockForUpdate()->findOrFail($image->id);
            abort_if((int) $locked->feedback_revision !== $data['revision'], 409, 'Feedback changed in another window. Reload before saving.');
            $retainDrawing = $data['annotations'] !== [] && $raster === null && $data['annotations'] === $locked->annotations && $locked->annotated_path;
            if ($data['annotations'] !== [] && $raster === null && ! $retainDrawing) {
                throw ValidationException::withMessages(['annotated_image' => 'Include the marked image when changing drawings.']);
            }
            $newPath = $retainDrawing ? $locked->annotated_path : ($data['annotations'] !== [] ? 'annotations/'.$image->uuid.'-'.Str::uuid().'.png' : null);
            if ($newPath && ! $retainDrawing && ! Storage::disk('local')->put($newPath, $raster)) {
                throw new \RuntimeException('The annotated image could not be saved.');
            }
            $oldPath = $locked->annotated_path;
            try {
                $locked->update(['annotations' => $data['annotations'], 'comments' => $data['comments'] ?? '', 'annotated_path' => $newPath, 'feedback_revision' => $locked->feedback_revision + 1]);
            } catch (\Throwable $error) {
                if ($newPath && ! $retainDrawing) {
                    Storage::disk('local')->delete($newPath);
                }
                throw $error;
            }
            DB::afterCommit(function () use ($oldPath, $newPath): void {
                if ($oldPath && $oldPath !== $newPath) {
                    Storage::disk('local')->delete($oldPath);
                }
            });
        });

        return response()->json($image->fresh()->agentData());
    }

    public function move(MoveImageRequest $request, UploadImage $image): JsonResponse
    {
        DB::transaction(function () use ($request, $image): void {
            $target = Project::query()->lockForUpdate()->findOrFail($request->integer('project_id'));
            $locked = UploadImage::query()->lockForUpdate()->findOrFail($image->id);
            $locked->update(['project_id' => $target->id]);
        });

        return response()->json(['project_url' => route('projects.show', $image->fresh()->project)]);
    }

    public function transferChunk(TransferChunkImageRequest $request, UploadImage $image, ChunkTransfer $transfer): JsonResponse
    {
        $result = $transfer->transfer($image, $request->string('chunk_id')->toString(), $request->integer('project_id'), $request->string('action')->toString());

        return response()->json(['image' => $result->agentData(), 'project_url' => route('projects.show', $result->project)]);
    }

    public function destroy(UploadImage $image, WorkspaceDeletion $deletion): JsonResponse
    {
        $deletion->image($image);

        return response()->json(['message' => 'Image deleted.']);
    }

    public function describe(UploadImage $image): JsonResponse
    {
        abort_if($image->isVideo(), 422, 'Image descriptions are available for still images.');
        $changed = UploadImage::whereKey($image->id)->whereIn('description_status', ['failed', 'ready'])->update(['description_status' => 'pending', 'description_error' => null]);
        if ($changed) {
            DescribeUploadImage::dispatch($image->id);
        }

        return response()->json(['description_status' => $image->fresh()->description_status]);
    }
}

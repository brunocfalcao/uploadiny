<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\RecordingFrames;
use App\UploadImage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[IsReadOnly]
#[IsOpenWorld(false)]
class GetRecordingFrames extends Tool
{
    protected string $name = 'get_recording_frames';

    protected string $description = 'Inspect a recording as JPEG frames at one to five timestamps in seconds. Defaults to the first frame (0 seconds). Returns timestamped images alongside exact asset feedback. Requires FFmpeg on the server.';

    public function handle(Request $request, RecordingFrames $extractor): Response|ResponseFactory
    {
        $data = $request->validate([
            'asset_id' => ['required', 'uuid'], 'timestamps' => ['sometimes', 'array', 'min:1', 'max:5'],
            'timestamps.*' => ['required', 'numeric', 'min:0'],
        ]);
        $image = UploadImage::query()->where('uuid', $data['asset_id'])->first();
        if ($image === null || ! $image->isVideo()) {
            return Response::error('Recording not found. Choose a video asset ID from get_feedback.');
        }
        if (! Storage::disk('local')->exists($image->path)) {
            return Response::error('The recording file is unavailable.');
        }
        $timestamps = array_map(static fn ($value): float => (float) $value, $data['timestamps'] ?? [0]);
        try {
            $frames = $extractor->extract($image, $timestamps);
        } catch (Throwable $error) {
            return Response::error($error->getCode() === 127
                ? 'FFmpeg is unavailable on the server. Configure UPLOADINY_FFMPEG_BINARY with its executable path.'
                : 'Could not extract recording frames. Verify FFmpeg is installed and choose timestamps within the recording.');
        }

        $metadata = ['asset' => $image->agentData(), 'frames' => array_map(static fn (array $frame): array => ['timestamp_seconds' => $frame['timestamp_seconds'], 'mime_type' => 'image/jpeg'], $frames)];
        $content = [Response::json($metadata)];
        foreach ($frames as $frame) {
            $content[] = Response::text('Recording frame at '.$frame['timestamp_seconds'].' seconds.');
            $content[] = Response::image($frame['data'], 'image/jpeg');
        }

        return Response::make($content)->withStructuredContent($metadata);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'asset_id' => $schema->string()->description('Recording UUID from get_feedback.')->required(),
            'timestamps' => $schema->array()->items($schema->number()->min(0))->min(1)->max(5)->description('Frame timestamps in seconds. Defaults to [0].'),
        ];
    }
}

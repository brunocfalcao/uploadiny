<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\FeedbackReader;
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

#[IsReadOnly]
#[IsOpenWorld(false)]
class GetAsset extends Tool
{
    protected string $name = 'get_asset';

    protected string $description = 'Inspect an asset by UUID. Screenshots return actual image content plus compact feedback (comments, marks with type, colour, area and position). Use variant annotated to see the drawn shapes. Choose original (default) or annotated. Original recordings return metadata and an authenticated download URL; use get_recording_frames for visual inspection.';

    public function handle(Request $request, FeedbackReader $feedback): Response|ResponseFactory
    {
        $data = $request->validate(['asset_id' => ['required', 'uuid'], 'variant' => ['sometimes', 'string', 'in:original,annotated']]);
        $image = UploadImage::query()->where('uuid', $data['asset_id'])->first();
        if ($image === null) {
            return Response::error('Asset not found. Retrieve its ID using get_feedback.');
        }

        $variant = $data['variant'] ?? 'original';
        $path = $variant === 'annotated' ? $image->annotated_path : $image->path;
        $disk = Storage::disk('local');
        if ($path === null || ! $disk->exists($path)) {
            return Response::error($variant === 'annotated' ? 'No annotated image is available for this asset. Try original.' : 'The original asset file is unavailable.');
        }

        $metadata = ['asset' => $feedback->image($image), 'variant' => $variant];
        if ($image->isVideo() && $variant === 'original') {
            return Response::structured($metadata + ['visual_inspection' => 'Use get_recording_frames with this asset ID and timestamps in seconds.']);
        }

        if ($disk->size($path) > 250 * 1024 * 1024) {
            return Response::error('This image is larger than 250 MB and cannot be sent to the agent. Ask the user to share a smaller export.');
        }

        return Response::make([Response::json($metadata), Response::image($disk->get($path), $variant === 'annotated' ? 'image/png' : $image->mime_type)])
            ->withStructuredContent($metadata);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'asset_id' => $schema->string()->description('Asset UUID from get_feedback.')->required(),
            'variant' => $schema->string()->enum(['original', 'annotated'])->description('Screenshot variant. Defaults to original.'),
        ];
    }
}

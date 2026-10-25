<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\AgentImageContent;
use App\Services\AgentReportText;
use App\Services\FeedbackReader;
use App\Services\PrivacyReport;
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
use RuntimeException;

#[IsReadOnly]
#[IsOpenWorld(false)]
class GetAsset extends Tool
{
    protected string $name = 'get_asset';

    protected string $description = 'Inspect an asset by UUID. Screenshots return image content plus compact feedback. Choose original (default) or annotated. Set image_width to resize without upscaling, and include_descriptions false to omit AI context. Original recordings return metadata and an authenticated download URL; use get_recording_frames for visual inspection. App Privacy Reports return their text inline, up to 48 KiB, with an explicit note and download URL when the text was cut.';

    public function handle(Request $request, FeedbackReader $feedback, AgentImageContent $content, AgentReportText $reports): Response|ResponseFactory
    {
        $data = $request->validate([
            'asset_id' => ['required', 'uuid'], 'variant' => ['sometimes', 'string', 'in:original,annotated'],
            'image_width' => ['sometimes', 'integer', 'min:1', 'max:4096'], 'include_descriptions' => ['sometimes', 'boolean'],
        ]);
        $image = UploadImage::query()->where('uuid', $data['asset_id'])->first();
        if ($image === null) {
            return Response::error('Asset not found. Retrieve its ID using get_feedback.');
        }

        $variant = $data['variant'] ?? 'original';
        $metadata = ['asset' => $feedback->image($image, (bool) ($data['include_descriptions'] ?? true)), 'variant' => $variant];
        if ($image->isPrivacyReport()) {
            if ($variant === 'annotated') {
                return Response::make(Response::error('App Privacy Reports have no annotated variant. Request variant original to read the report text.'))->withStructuredContent($metadata);
            }
            $report = $reports->read($image, PrivacyReport::AGENT_TEXT_LIMIT_BYTES);
            if ($report['text'] === null) {
                return Response::make(Response::error($report['note']))->withStructuredContent($metadata);
            }
            $content = [Response::json($metadata), Response::text($report['note']), Response::text($report['text'])];
            if (isset($data['image_width'])) {
                $content[] = Response::text('image_width does not apply to App Privacy Reports; the text is unchanged.');
            }

            return Response::make($content)->withStructuredContent($metadata);
        }
        if ($image->isVideo() && $variant === 'original') {
            if (! Storage::disk('local')->exists($image->path)) {
                return Response::error('The original asset file is unavailable.');
            }

            return Response::structured($metadata + ['visual_inspection' => 'Use get_recording_frames with this asset ID and timestamps in seconds.']);
        }

        try {
            $bytes = $content->read($image, $variant, isset($data['image_width']) ? (int) $data['image_width'] : null);
        } catch (RuntimeException $error) {
            return Response::make(Response::error($error->getMessage()))->withStructuredContent($metadata);
        }

        return Response::make([Response::json($metadata), Response::image($bytes['data'], $bytes['mime_type'])])
            ->withStructuredContent($metadata);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'asset_id' => $schema->string()->description('Asset UUID from get_feedback.')->required(),
            'variant' => $schema->string()->enum(['original', 'annotated'])->description('Screenshot variant. Defaults to original.'),
            'image_width' => $schema->integer()->min(1)->max(4096)->description('Maximum screenshot width in pixels, e.g. 900. Preserves aspect ratio; never upscales. Omit for exact stored bytes.'),
            'include_descriptions' => $schema->boolean()->description('Include AI description and its status, error, and model. Defaults to true.'),
        ];
    }
}

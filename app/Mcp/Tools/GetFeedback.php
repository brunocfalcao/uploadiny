<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Project;
use App\Services\AgentImageContent;
use App\Services\FeedbackReader;
use App\UploadImage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

#[IsReadOnly]
#[IsOpenWorld(false)]
class GetFeedback extends Tool
{
    protected string $name = 'get_feedback';

    protected string $description = 'Read the latest completed feedback batch with exact comments, compact marks, revisions, and authenticated media URLs. Marked screenshots include their annotated image by default; include_annotated_images false returns metadata only. Set image_width (e.g. 900) for smaller images and include_descriptions false to omit AI context. Pass the last chunk UUID as after_chunk to return only a newer completed batch; chunk: null means none. An unavailable cursor returns an error. Comments and callout notes are the primary instructions. If settling is true, wait 30-60 seconds and fetch again before acting. Asset order is not the owner’s numbering; rely on comment text.';

    public function handle(Request $request, FeedbackReader $feedback, AgentImageContent $images): Response|ResponseFactory
    {
        $data = $request->validate([
            'project_canonical' => ['required', 'string', 'regex:/^[a-z]{6}\z/'],
            'after_chunk' => ['sometimes', 'uuid'], 'include_descriptions' => ['sometimes', 'boolean'],
            'include_annotated_images' => ['sometimes', 'boolean'], 'image_width' => ['sometimes', 'integer', 'min:1', 'max:4096'],
        ]);
        $project = Project::query()->where('canonical', $data['project_canonical'])->first();
        if ($project === null) {
            return Response::error('Project code not found. Ask the user for its canonical or use list_projects.');
        }
        $metadata = $feedback->latest($project, (bool) ($data['include_descriptions'] ?? true), $data['after_chunk'] ?? null);
        if (! ($data['include_annotated_images'] ?? true) || $metadata['chunk'] === null) {
            return Response::structured($metadata);
        }
        $ids = array_column(array_filter($metadata['chunk']['images'], static fn (array $asset): bool => $asset['media_type'] === 'image' && $asset['mark_count'] > 0 && $asset['annotated_image_url'] !== null), 'id');
        if ($ids === []) {
            return Response::structured($metadata);
        }
        $assets = UploadImage::query()->where('project_id', $project->id)->whereIn('uuid', $ids)->orderBy('id')->get();
        $content = [Response::json($metadata)];
        $reservedBytes = 0;
        foreach ($assets as $asset) {
            try {
                $bytes = $images->read($asset, 'annotated', isset($data['image_width']) ? (int) $data['image_width'] : null, $reservedBytes);
                $content[] = Response::text('Annotated screenshot for asset '.$asset->uuid.'.');
                $content[] = Response::image($bytes['data'], $bytes['mime_type']);
                $reservedBytes += strlen($bytes['data']) * 6;
            } catch (RuntimeException $error) {
                $content[] = Response::text('Annotated screenshot unavailable for asset '.$asset->uuid.': '.$error->getMessage());
            }
        }

        return Response::make($content)->withStructuredContent($metadata);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_canonical' => $schema->string()->pattern('^[a-z]{6}$')->description('The project’s permanent six-letter lowercase code.')->required(),
            'after_chunk' => $schema->string()->description('Last reviewed chunk UUID. Return the latest batch strictly newer by completion time, then ID. No new batch returns chunk: null. Deleted, draft, or other-project cursors return an error; omit to reset. Does not track edits within the same batch.'),
            'include_annotated_images' => $schema->boolean()->description('Include annotated screenshot image content when marks and a saved annotated image exist. Defaults to true. Videos use get_recording_frames.'),
            'image_width' => $schema->integer()->min(1)->max(4096)->description('Maximum inline image width in pixels, e.g. 900. Preserves aspect ratio; never upscales. Omit for exact stored bytes.'),
            'include_descriptions' => $schema->boolean()->description('Include AI description and its status, error, and model. Defaults to true.'),
        ];
    }
}

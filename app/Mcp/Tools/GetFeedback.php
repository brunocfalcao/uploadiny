<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Project;
use App\Services\FeedbackReader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsOpenWorld(false)]
class GetFeedback extends Tool
{
    protected string $name = 'get_feedback';

    protected string $description = 'Read the latest completed feedback batch for a project canonical, including exact per-asset comments, compact marks (callout notes, tool, colour, area, position; no raw drawing points), revisions, AI descriptions, and authenticated media URLs. Comments and callout notes are the primary instructions; view get_asset variant annotated to see the shapes. If settling is true the owner may still be editing: wait 30-60 seconds and fetch again before acting. Asset order is not the numbering the owner sees; rely on comment text. An empty project returns chunk: null.';

    public function handle(Request $request, FeedbackReader $feedback): Response|ResponseFactory
    {
        $data = $request->validate(['project_canonical' => ['required', 'string', 'regex:/^[a-z]{6}\z/']]);
        $project = Project::query()->where('canonical', $data['project_canonical'])->first();

        return $project === null ? Response::error('Project code not found. Ask the user for its canonical or use list_projects.') : Response::structured($feedback->latest($project));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['project_canonical' => $schema->string()->pattern('^[a-z]{6}$')->description('The project’s permanent six-letter lowercase code.')->required()];
    }
}

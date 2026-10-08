<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Project;
use App\Services\WorkspaceDeletion;
use App\UploadChunk;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

#[IsReadOnly(false)]
#[IsDestructive]
#[IsOpenWorld(false)]
class DeleteChunk extends Tool
{
    protected string $name = 'delete_chunk';

    protected string $description = 'Permanently delete a completed feedback chunk from the selected project, including its currently assigned assets, comments, annotations, and private files. Use only after the user explicitly requests deletion. Supply the exact reviewed chunk UUID and review_token from get_feedback; changed feedback requires review again, never a newly resolved latest chunk. Assets moved to another project or chunk are preserved. The same agent bearer key authorizes this tool.';

    public function handle(Request $request, WorkspaceDeletion $deletion): Response|ResponseFactory
    {
        $data = $request->validate([
            'project_canonical' => ['required', 'string', 'regex:/^[a-z]{6}\z/'],
            'chunk_id' => ['required', 'uuid'],
            'review_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}\z/'],
        ]);
        $project = Project::query()->where('canonical', $data['project_canonical'])->first();
        $chunk = UploadChunk::query()->where('uuid', $data['chunk_id'])->first();
        if ($project === null || $chunk === null) {
            return Response::error('Project or chunk not found. Verify the project canonical and the exact reviewed chunk ID.');
        }

        try {
            $result = $deletion->completedChunk($project, $chunk, $data['review_token']);
        } catch (HttpExceptionInterface $error) {
            return Response::error($error->getMessage());
        } catch (Throwable $error) {
            report($error);

            return Response::error('Deletion could not finish. Refresh feedback to verify the remaining assets before retrying.');
        }

        return Response::structured([
            'project_canonical' => $project->canonical,
            'chunk_id' => $chunk->uuid,
            ...$result,
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_canonical' => $schema->string()->pattern('^[a-z]{6}$')->description('Permanent project code used when reviewing this chunk.')->required(),
            'review_token' => $schema->string()->description('Exact review_token retained from get_feedback. Refuses changed chunk contents.')->required(),
            'chunk_id' => $schema->string()->description('Exact completed chunk UUID retained from get_feedback.')->required(),
        ];
    }
}

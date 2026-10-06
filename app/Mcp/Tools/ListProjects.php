<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\FeedbackReader;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsOpenWorld(false)]
class ListProjects extends Tool
{
    protected string $name = 'list_projects';

    protected string $description = 'List workspace projects with their names, descriptions, six-letter canonicals, and completed asset counts.';

    public function handle(FeedbackReader $feedback): ResponseFactory
    {
        return Response::structured($feedback->projects());
    }
}

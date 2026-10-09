<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AgentFeedbackRequest;
use App\Project;
use App\Services\FeedbackReader;
use Illuminate\Http\JsonResponse;

class AgentController extends Controller
{
    public function projects(FeedbackReader $feedback): JsonResponse
    {
        return response()->json($feedback->projects());
    }

    public function latest(Project $project, AgentFeedbackRequest $request, FeedbackReader $feedback): JsonResponse
    {
        $data = $request->validated();

        return response()->json($feedback->latest($project, (bool) ($data['include_descriptions'] ?? true), $data['after_chunk'] ?? null));
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AgentAccess;
use App\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AgentAccessController extends Controller
{
    public function show(Request $request, AgentAccess $access): Response
    {
        /** @var User $user */
        $user = $request->user();

        return response()->view('agent-access', ['apiKey' => $access->current($user)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, AgentAccess $access): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $access->rotate($user)) {
            return redirect()->route('agent-access.show')->withErrors(['api_key' => 'The API key could not be saved. Your previous key still works. Try again.']);
        }

        return redirect()->route('agent-access.show')->with('status', 'API key generated. Previous keys were revoked. Update the key in Claude and Codex.');
    }

    public function destroy(Request $request, AgentAccess $access): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $access->revoke($user);

        return redirect()->route('agent-access.show')->with('status', 'Agent API access revoked. Your iPhone stays connected.');
    }
}

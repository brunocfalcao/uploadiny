<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class DeviceAccessController extends Controller
{
    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->tokens()->where('name', DeviceTokenController::TOKEN_NAME)->delete();

        return redirect()
            ->route('projects.index')
            ->with('status', 'iPhone access was revoked. The next Share Sheet upload will ask you to sign in.');
    }
}

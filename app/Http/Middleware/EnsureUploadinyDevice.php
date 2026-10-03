<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUploadinyDevice
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = config('services.uploadiny.upload_token');
        $providedToken = $request->bearerToken();

        if (! is_string($configuredToken)
            || $configuredToken === ''
            || ! is_string($providedToken)
            || ! hash_equals($configuredToken, $providedToken)) {
            return new JsonResponse(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}

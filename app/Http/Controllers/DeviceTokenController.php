<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DeviceTokenRequest;
use App\UploadinyTokenAbility;
use App\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeviceTokenController extends Controller
{
    public const TOKEN_NAME = 'iphone-share-extension';

    public function store(DeviceTokenRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (! Auth::guard('web')->validate($credentials)) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }

        $user = User::query()->where('email', $credentials['email'])->sole();
        $token = DB::transaction(function () use ($user) {
            $user->tokens()->where('name', self::TOKEN_NAME)->delete();

            return $user->createToken(
                self::TOKEN_NAME,
                UploadinyTokenAbility::phone(),
                now()->addDays(config('services.uploadiny.device_token_expiration_days')),
            );
        });

        return response()
            ->json([
                'token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            ], 201)
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\UploadinyTokenAbility;
use App\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

final class AgentAccess
{
    public const TOKEN_NAME = 'coding-agent';

    public function __construct(private AgentTokenStorage $storage) {}

    public function current(User $user): ?string
    {
        $value = $this->storage->read();
        $token = $value === null ? null : PersonalAccessToken::findToken($value);
        if ($token === null || $token->tokenable_type !== $user->getMorphClass() || $token->tokenable_id !== $user->getKey()
            || $token->name !== self::TOKEN_NAME || $token->abilities !== UploadinyTokenAbility::agent()
            || ($token->expires_at !== null && $token->expires_at->isPast())) {
            return null;
        }

        return $value;
    }

    public function rotate(User $user, ?DateTimeInterface $expiresAt = null): bool
    {
        return DB::transaction(function () use ($user, $expiresAt): bool {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $token = $user->createToken(self::TOKEN_NAME, UploadinyTokenAbility::agent(), $expiresAt);
            if (! $this->storage->replace($token->plainTextToken)) {
                $token->accessToken->delete();

                return false;
            }

            $user->tokens()->where('name', self::TOKEN_NAME)->whereKeyNot($token->accessToken->getKey())->delete();

            return true;
        });
    }

    public function revoke(User $user): void
    {
        $user->tokens()->where('name', self::TOKEN_NAME)->delete();
        $this->storage->forget();
    }
}

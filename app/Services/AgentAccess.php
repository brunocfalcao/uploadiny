<?php

declare(strict_types=1);

namespace App\Services;

use App\UploadinyTokenAbility;
use App\User;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

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
        return Cache::lock('uploadiny:agent-access', 600)->block(30, fn (): bool => $this->rotateLocked($user, $expiresAt));
    }

    private function rotateLocked(User $user, ?DateTimeInterface $expiresAt): bool
    {
        $previous = $this->storage->read();
        $written = false;
        try {
            return DB::transaction(function () use ($user, $expiresAt, &$written): bool {
                $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
                $token = $user->createToken(self::TOKEN_NAME, UploadinyTokenAbility::agent(), $expiresAt);
                if (! $this->storage->replace($token->plainTextToken)) {
                    $token->accessToken->delete();

                    return false;
                }
                $written = true;

                $user->tokens()->where('name', self::TOKEN_NAME)->whereKeyNot($token->accessToken->getKey())->delete();

                return true;
            });
        } catch (Throwable $error) {
            if ($written) {
                $restored = $previous === null ? $this->storage->forget() : $this->storage->replace($previous);
                if (! $restored) {
                    throw new \RuntimeException('Agent access could not be restored. Rotate the key again from Agent access.', previous: $error);
                }
            }
            throw $error;
        }
    }

    public function revoke(User $user): void
    {
        Cache::lock('uploadiny:agent-access', 600)->block(30, function () use ($user): void {
            $user->tokens()->where('name', self::TOKEN_NAME)->delete();
            $this->storage->forget();
        });
    }
}

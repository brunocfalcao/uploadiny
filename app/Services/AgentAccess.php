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
        $this->recover();
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
        $this->recoverLocked();
        $previous = $this->storage->read();
        try {
            $result = DB::transaction(function () use ($user, $expiresAt, $previous): bool {
                $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
                $token = $user->createToken(self::TOKEN_NAME, UploadinyTokenAbility::agent(), $expiresAt);
                if (! $this->storage->prepareRecovery($previous, $token->plainTextToken) || ! $this->storage->replace($token->plainTextToken)) {
                    $token->accessToken->delete();

                    return false;
                }
                $user->tokens()->where('name', self::TOKEN_NAME)->whereKeyNot($token->accessToken->getKey())->delete();

                return true;
            });
        } catch (Throwable $error) {
            try {
                $this->recoverLocked();
            } catch (Throwable) {
                throw new \RuntimeException('Agent access could not be restored. Recovery will retry before another key operation.', previous: $error);
            }
            throw $error;
        }
        $this->recoverLocked();
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->recover(wait: false));
        }

        return $result;
    }

    public function recover(bool $wait = true): void
    {
        if ($this->storage->hasRecovery()) {
            $lock = Cache::lock('uploadiny:agent-access', 600);
            $wait ? $lock->block(30, fn () => $this->recoverLocked()) : $lock->get(fn () => $this->recoverLocked());
        }
    }

    private function recoverLocked(): void
    {
        $record = $this->storage->recovery();
        if ($record === null) {
            return;
        }
        $value = null;
        foreach ([$record['next'], ...array_reverse($record['candidates'] ?? []), $record['previous']] as $candidate) {
            $token = $candidate === null ? null : PersonalAccessToken::findToken($candidate);
            if ($token !== null && $token->name === self::TOKEN_NAME && $token->abilities === UploadinyTokenAbility::agent()) {
                $value = $candidate;
                break;
            }
        }
        if ($this->storage->read() !== $value && ! ($value === null ? $this->storage->forget() : $this->storage->replace($value))) {
            throw new \RuntimeException('Agent access recovery needs another attempt.');
        }
        if (DB::transactionLevel() === 0 && ! $this->storage->clearRecovery()) {
            throw new \RuntimeException('Agent access recovery record cleanup needs another attempt.');
        }
    }

    public function revoke(User $user): void
    {
        Cache::lock('uploadiny:agent-access', 600)->block(30, function () use ($user): void {
            $this->recoverLocked();
            if (! $this->storage->prepareRecovery($this->storage->read(), '')) {
                throw new \RuntimeException('Agent access revocation recovery could not be prepared.');
            }
            DB::transaction(function () use ($user): void {
                $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
                $user->tokens()->where('name', self::TOKEN_NAME)->delete();
            });
            $this->recoverLocked();
            if (DB::transactionLevel() > 0) {
                DB::afterCommit(fn () => $this->recover(wait: false));
            }
        });
    }
}

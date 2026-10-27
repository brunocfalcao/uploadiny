<?php

declare(strict_types=1);

namespace App\Services;

use App\UploadinyTokenAbility;
use App\User;
use Closure;
use DateTimeInterface;
use Illuminate\Cache\Lock;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;
use WeakMap;

final class AgentAccess
{
    public const TOKEN_NAME = 'coding-agent';

    /** @var WeakMap<Connection, Lock>|null */
    private static ?WeakMap $transactionLocks = null;

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
        return $this->withLock(fn (): bool => $this->rotateLocked($user, $expiresAt));
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

        return $result;
    }

    public function recover(bool $wait = true): void
    {
        if ($this->storage->hasRecovery() || isset(self::$transactionLocks[DB::connection()])) {
            $this->withLock(fn () => $this->recoverLocked(), $wait);
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
        $this->withLock(function () use ($user): void {
            $this->recoverLocked();
            if (! $this->storage->prepareRecovery($this->storage->read(), '')) {
                throw new \RuntimeException('Agent access revocation recovery could not be prepared.');
            }
            DB::transaction(function () use ($user): void {
                $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
                $user->tokens()->where('name', self::TOKEN_NAME)->delete();
            });
            $this->recoverLocked();
        });
    }

    private function withLock(Closure $operation, bool $wait = true): mixed
    {
        $connection = DB::connection();
        self::$transactionLocks ??= new WeakMap;
        if (isset(self::$transactionLocks[$connection])) {
            $owned = self::$transactionLocks[$connection];
            if ($connection->transactionLevel() === 0) {
                if ($connection->getPdo()->inTransaction()) {
                    throw new \RuntimeException('The database transaction must be resolved before agent access recovery can continue.');
                }
                $this->finishLock($connection, $owned);
            } elseif ($owned->isOwnedByCurrentProcess()) {
                return $operation();
            } else {
                throw new \RuntimeException('The agent access lease expired. Resolve the database transaction before retrying.');
            }
        }

        $lock = Cache::lock('uploadiny:agent-access', 600);
        if ($wait) {
            $lock->block(30);
        } elseif (! $lock->get()) {
            return null;
        }

        $retained = false;
        try {
            if ($connection->transactionLevel() > 0) {
                // The outer record owns the lease, including savepoint rollbacks.
                $transaction = app('db.transactions')->getPendingTransactions()->first(
                    fn (DatabaseTransactionRecord $record): bool => $record->connection === $connection->getName() && $record->level === 1,
                );
                if ($transaction === null) {
                    throw new \RuntimeException('Agent access transaction ownership could not be established.');
                }

                self::$transactionLocks[$connection] = $lock;
                $finish = fn () => $this->finishLock($connection, $lock);
                $transaction->addCallback($finish);
                $transaction->addCallbackForRollback($finish);
                $retained = true;
            }

            return $operation();
        } finally {
            if (! $retained) {
                $lock->release();
            }
        }
    }

    private function finishLock(Connection $connection, Lock $lock): void
    {
        if ((self::$transactionLocks[$connection] ?? null) !== $lock) {
            return;
        }
        try {
            if ($lock->isOwnedByCurrentProcess()) {
                $this->recoverLocked();
            }
        } finally {
            unset(self::$transactionLocks[$connection]);
            $lock->release();
        }
    }
}

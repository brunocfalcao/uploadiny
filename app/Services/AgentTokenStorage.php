<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Storage;

final class AgentTokenStorage
{
    private const PATH = 'credentials/uploadiny-agent-token.txt';

    private const RECOVERY = 'credentials/.uploadiny-agent-token.recovery.json';

    public function hasRecovery(): bool
    {
        $disk = Storage::disk('local');

        return $disk->exists(self::RECOVERY) || $disk->exists('credentials/.uploadiny-agent-token.recovery.next');
    }

    /** @return array{previous: string|null, next: string, candidates?: list<string>}|null */
    public function recovery(): ?array
    {
        if (! $this->hasRecovery()) {
            return null;
        }
        $disk = Storage::disk('local');
        $path = $disk->exists(self::RECOVERY) ? self::RECOVERY : 'credentials/.uploadiny-agent-token.recovery.next';
        $record = json_decode($disk->get($path) ?? '', true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($record) || ! array_key_exists('previous', $record) || (! is_string($record['previous']) && $record['previous'] !== null) || ! is_string($record['next'] ?? null)) {
            throw new \RuntimeException('Agent access recovery could not be read.');
        }
        if (isset($record['candidates']) && (! is_array($record['candidates']) || ! array_is_list($record['candidates']) || array_filter($record['candidates'], fn ($value) => ! is_string($value)) !== [])) {
            throw new \RuntimeException('Agent access recovery could not be read.');
        }

        return $record;
    }

    public function prepareRecovery(?string $previous, string $next): bool
    {
        $pending = $this->recovery();
        $record = ['previous' => $pending === null ? $previous : $pending['previous'], 'next' => $next];
        if ($pending !== null) {
            $record['candidates'] = array_values(array_unique([...($pending['candidates'] ?? []), $pending['next']]));
        }

        return $this->atomicWrite(self::RECOVERY, json_encode($record, JSON_THROW_ON_ERROR), 'credentials/.uploadiny-agent-token.recovery.next');
    }

    public function clearRecovery(): bool
    {
        $disk = Storage::disk('local');

        return $disk->delete(['credentials/.uploadiny-agent-token.recovery.next', 'credentials/.uploadiny-agent-token.next']) && $disk->delete(self::RECOVERY);
    }

    public function read(): ?string
    {
        $disk = Storage::disk('local');

        return $disk->exists(self::PATH) ? $disk->get(self::PATH) : null;
    }

    public function forget(): bool
    {
        $disk = Storage::disk('local');

        return ! $disk->exists(self::PATH) || $disk->delete(self::PATH);
    }

    public function replace(string $token): bool
    {
        return $this->atomicWrite(self::PATH, $token, 'credentials/.uploadiny-agent-token.next');
    }

    private function atomicWrite(string $path, string $value, string $temporaryPath): bool
    {
        $disk = Storage::disk('local');
        $originalUmask = umask(0077);
        try {
            $stored = $disk->put($temporaryPath, $value);
            if (! $stored || ! chmod($disk->path($temporaryPath), 0600) || ! $disk->move($temporaryPath, $path)) {
                return false;
            }
        } finally {
            try {
                $disk->delete($temporaryPath);
            } finally {
                umask($originalUmask);
            }
        }

        return true;
    }
}

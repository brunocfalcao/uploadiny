<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Storage;

final class AgentTokenStorage
{
    private const PATH = 'credentials/uploadiny-agent-token.txt';

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
        $disk = Storage::disk('local');
        $path = self::PATH;
        $temporaryPath = 'credentials/.uploadiny-agent-token.next';
        $originalUmask = umask(0077);
        try {
            $stored = $disk->put($temporaryPath, $token);
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

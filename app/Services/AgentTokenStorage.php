<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Storage;

final class AgentTokenStorage
{
    public function replace(string $token): bool
    {
        $disk = Storage::disk('local');
        $path = 'credentials/uploadiny-agent-token.txt';
        $temporaryPath = 'credentials/.uploadiny-agent-token.next';
        $originalUmask = umask(0077);
        try {
            $stored = $disk->put($temporaryPath, $token);
        } finally {
            umask($originalUmask);
        }

        if (! $stored || ! chmod($disk->path($temporaryPath), 0600) || ! $disk->move($temporaryPath, $path)) {
            $disk->delete($temporaryPath);

            return false;
        }

        return true;
    }
}

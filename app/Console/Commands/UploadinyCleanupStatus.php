<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\StagedFileDeletion;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

final class UploadinyCleanupStatus extends Command
{
    /** @var string */
    protected $signature = 'uploadiny:cleanup-status';

    /** @var string */
    protected $description = 'Show private upload cleanup backlog age and retry progress without file details';

    public function handle(): int
    {
        $oldest = StagedFileDeletion::min('created_at');
        $lastAttempt = StagedFileDeletion::max('last_attempt_at');
        $disk = Storage::disk('local');
        $journals = 0;
        foreach ($disk->directories('deleting') as $directory) {
            $journals += (int) $disk->exists($directory.'/journal.json');
        }
        $this->line(json_encode([
            'pending' => StagedFileDeletion::count(),
            'oldest_pending_seconds' => $oldest === null ? null : max(0, (int) Carbon::parse($oldest)->diffInSeconds(now())),
            'attempts' => (int) StagedFileDeletion::sum('attempts'),
            'deferred' => StagedFileDeletion::whereNotNull('last_error')->count(),
            'last_attempt_at' => $lastAttempt === null ? null : Carbon::parse($lastAttempt)->toIso8601String(),
            'recovery_journals' => $journals,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}

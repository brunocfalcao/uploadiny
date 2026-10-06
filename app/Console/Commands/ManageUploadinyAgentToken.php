<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgentAccess;
use App\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\MultipleRecordsFoundException;

final class ManageUploadinyAgentToken extends Command
{
    public const TOKEN_NAME = AgentAccess::TOKEN_NAME;

    /** @var string */
    protected $signature = 'uploadiny:agent-token {--revoke : Revoke the coding-agent credential without issuing another} {--expires=90 : Credential lifetime in days}';

    /** @var string */
    protected $description = 'Issue or revoke the private least-privilege credential for the Uploadiny feedback command';

    public function __construct(private AgentAccess $access)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $user = User::query()->sole();
        } catch (ModelNotFoundException) {
            $this->error('Create the personal Uploadiny account first.');

            return self::FAILURE;
        } catch (MultipleRecordsFoundException) {
            $this->error('Uploadiny must have exactly one personal account.');

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            return $this->revoke($user);
        }

        $days = filter_var($this->option('expires'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
        if (! is_int($days)) {
            $this->error('The expiration must be between 1 and 365 days.');

            return self::FAILURE;
        }

        if (! $this->access->rotate($user, now()->addDays($days))) {
            $this->error('The private coding-agent credential could not be stored.');

            return self::FAILURE;
        }

        $this->info('A new coding-agent credential was written to protected private storage.');

        return self::SUCCESS;
    }

    private function revoke(User $user): int
    {
        $this->access->revoke($user);
        $this->info('Coding-agent access was revoked.');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class RecoverProjectCanonicals extends Command
{
    private const MIGRATION = '2026_10_06_144233_add_canonical_to_projects_table';

    protected $signature = 'uploadiny:recover-canonicals';

    protected $description = 'Finish an interrupted SQLite project-code migration without changing existing codes';

    public function handle(): int
    {
        try {
            // SQLite can roll back DDL here even though Laravel's migrator does
            // not automatically wrap SQLite schema migrations in transactions.
            throw_unless(DB::getDriverName() === 'sqlite', RuntimeException::class, 'Canonical recovery requires the Uploadiny SQLite database.');
            DB::transaction(function (): void {
                $repository = app('migration.repository');
                if (in_array(self::MIGRATION, $repository->getRan(), true)) {
                    return;
                }
                throw_unless(Schema::hasColumn('projects', 'canonical'), RuntimeException::class, 'No interrupted canonical migration exists. Run the normal migrations first.');
                if (! Schema::hasIndex('projects', ['canonical'], 'unique')) {
                    Schema::table('projects', fn (Blueprint $table) => $table->unique('canonical'));
                }
                DB::table('projects')->select('id')->whereNull('canonical')->orderBy('id')->chunkById(100, function ($projects): void {
                    foreach ($projects as $project) {
                        do {
                            $canonical = strtolower(Str::password(6, numbers: false, symbols: false));
                        } while (DB::table('projects')->where('canonical', $canonical)->exists());
                        DB::table('projects')->where('id', $project->id)->update(['canonical' => $canonical]);
                    }
                });
                // Record only after the original migration's complete outcome
                // exists. A later normal migrate then skips the immutable file.
                $repository->log(self::MIGRATION, $repository->getNextBatchNumber());
            });
        } catch (Throwable $error) {
            Log::warning('Project code recovery deferred.', ['category' => class_basename($error)]);
            $this->error('Project code recovery did not complete. Existing codes were kept; review the database before retrying.');

            return self::FAILURE;
        }

        $this->info('Project codes are complete. Continue with the normal migrations.');

        return self::SUCCESS;
    }
}

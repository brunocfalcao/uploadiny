<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AgentAccess;
use App\Services\AgentTokenStorage;
use App\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class AgentAccessCommitFailureTest extends TestCase
{
    public function test_recovery_does_not_retire_a_failed_commit_while_pdo_still_owns_its_transaction(): void
    {
        [$directory, $access, $user] = $this->failedCommit();
        try {
            $before = hash('sha256', Storage::disk('local')->get('credentials/.uploadiny-agent-token.recovery.json'));
            $caught = null;
            try {
                $access->recover(wait: false);
            } catch (RuntimeException $error) {
                $caught = $error;
            }
            $this->assertInstanceOf(RuntimeException::class, $caught);
            $this->assertStringContainsString('database transaction must be resolved', $caught->getMessage());
            $this->assertTrue(app(AgentTokenStorage::class)->hasRecovery());
            $this->assertSame($before, hash('sha256', Storage::disk('local')->get('credentials/.uploadiny-agent-token.recovery.json')));
        } finally {
            $this->finishFixture($directory);
        }
    }

    public function test_recovery_finalizes_its_lease_after_a_failed_commit_is_physically_rolled_back(): void
    {
        [$directory, $access, $user] = $this->failedCommit();
        try {
            DB::connection()->getPdo()->rollBack();
            $this->assertTrue(app(AgentTokenStorage::class)->hasRecovery());
            $access->recover(wait: false);
            $this->assertFalse(app(AgentTokenStorage::class)->hasRecovery());
            $probe = Cache::lock('uploadiny:agent-access', 600);
            try {
                $this->assertTrue($probe->get(), 'Recovery must release the ended transaction lease.');
            } finally {
                $probe->release();
            }
            $this->assertNotNull($access->current($user));
        } finally {
            $this->finishFixture($directory);
        }
    }

    public function test_recovery_releases_an_ended_transaction_lease_without_a_prepared_journal(): void
    {
        [$directory, $access] = $this->failedCommit(false);
        try {
            DB::connection()->getPdo()->rollBack();
            $this->assertFalse(app(AgentTokenStorage::class)->hasRecovery());
            $access->recover(wait: false);
            $probe = Cache::lock('uploadiny:agent-access', 600);
            try {
                $this->assertTrue($probe->get(), 'A failed preparation still needs to release its ended transaction lease.');
            } finally {
                $probe->release();
            }
        } finally {
            $this->finishFixture($directory);
        }
    }

    private function failedCommit(bool $withJournal = true): array
    {
        $directory = sys_get_temp_dir().'/uploadiny-commit-test-'.bin2hex(random_bytes(6));
        File::makeDirectory($directory, 0700, true);
        Storage::fake('local');
        config(['cache.default' => 'file', 'cache.stores.file.path' => $directory, 'cache.stores.file.lock_path' => $directory]);
        app('cache')->purge('file');
        Artisan::call('migrate', ['--force' => true]);
        $user = User::factory()->create(['email' => 'commit-test-'.bin2hex(random_bytes(4)).'@example.test']);
        $access = app(AgentAccess::class);
        $this->assertTrue($access->rotate($user));
        if (! $withJournal) {
            $disk = Storage::disk('local');
            $proxy = \Mockery::mock($disk);
            $proxy->shouldReceive('put')->andReturnUsing(fn ($path, $value) => str_contains($path, '.recovery.next') ? false : $disk->put($path, $value));
            Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        }
        DB::statement('PRAGMA foreign_keys = ON');
        DB::statement('CREATE TABLE fixture_parent (id INTEGER PRIMARY KEY)');
        DB::statement('CREATE TABLE fixture_child (id INTEGER PRIMARY KEY, parent_id INTEGER, FOREIGN KEY(parent_id) REFERENCES fixture_parent(id) DEFERRABLE INITIALLY DEFERRED)');
        $rotationResult = null;
        try {
            DB::transaction(function () use ($access, $user, &$rotationResult): void {
                DB::table('fixture_child')->insert(['id' => 1, 'parent_id' => 999]);
                $rotationResult = $access->rotate($user);
            });
            $this->fail('The deferred constraint must fail at the outer commit.');
        } catch (PDOException) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $this->assertSame($withJournal, $rotationResult);
            $this->assertSame($withJournal, app(AgentTokenStorage::class)->hasRecovery());
        }

        return [$directory, $access, $user];
    }

    private function finishFixture(string $directory): void
    {
        if (DB::connection()->getPdo()->inTransaction()) {
            DB::connection()->getPdo()->rollBack();
        }
        app('db.transactions')->rollback(DB::connection()->getName(), 0);
        File::deleteDirectory($directory);
    }
}

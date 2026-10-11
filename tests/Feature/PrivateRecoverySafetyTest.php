<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Project;
use App\Services\AgentAccess;
use App\Services\ChunkStorage;
use App\Services\WorkspaceDeletion;
use App\StagedFileDeletion;
use App\UploadImage;
use App\UploadinyTokenAbility;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PrivateRecoverySafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_preserves_referenced_and_unlisted_siblings_for_legacy_and_journal_intents(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        foreach ([false, true] as $journalled) {
            $directory = 'deleting/sibling-'.(int) $journalled;
            $owned = $directory.'/remove.png';
            $active = $directory.'/active.png';
            $unlisted = $directory.'/unlisted.png';
            $image = UploadImage::factory()->create(['path' => $active]);
            $disk->put($owned, 'remove');
            $disk->put($active, 'surviving original');
            $disk->put($unlisted, 'unlisted private bytes');
            $journal = $journalled ? $directory.'/journal.json' : null;
            if ($journal !== null) {
                $disk->put($journal, json_encode(['images/former.png' => $owned, 'images/active.png' => $active], JSON_THROW_ON_ERROR));
            }
            $intent = StagedFileDeletion::create(['paths' => [$owned, $active], 'journal_path' => $journal]);
            $this->assertSame('surviving original', $disk->get($image->path));

            app(WorkspaceDeletion::class)->cleanupPending();

            $this->assertSame('surviving original', $disk->get($image->fresh()->path));
            $this->assertSame('unlisted private bytes', $disk->get($unlisted));
            $this->assertSame(1, $intent->fresh()->attempts);
            $image->delete();
            app(WorkspaceDeletion::class)->cleanupPending();
            $this->assertNull($intent->fresh());
            $disk->assertMissing([$owned, $active]);
            $this->assertSame('unlisted private bytes', $disk->get($unlisted));
        }
    }

    public function test_orphan_recovery_preserves_unlisted_files_and_reports_retries_without_private_details(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('deleting/orphan/journal.json', 'invalid private journal');
        $disk->put('deleting/orphan/unlisted.png', 'unlisted bytes');
        app(WorkspaceDeletion::class)->cleanupPending();
        $this->assertSame(0, Artisan::call('uploadiny:cleanup-status'));
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $output['orphan_recovery']['pending']);
        $this->assertSame(1, $output['orphan_recovery']['attempts']);
        $this->assertSame(1, $output['orphan_recovery']['deferred']);
        $this->assertStringNotContainsString('orphan/', json_encode($output));
        $this->assertStringNotContainsString('private journal', json_encode($output));
        $disk->put('deleting/orphan/journal.json', '{}');

        app(WorkspaceDeletion::class)->cleanupPending();

        $disk->assertMissing('deleting/orphan/journal.json');
        $this->assertSame('unlisted bytes', $disk->get('deleting/orphan/unlisted.png'));
    }

    public function test_failed_journal_preparation_removes_only_its_temporary_records(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('writing/unowned/keep.png', 'unowned bytes');
        $project = Project::factory()->create(['slug' => 'journal-failure']);
        $proxy = Mockery::mock($disk);
        $proxy->shouldReceive('put')->andReturnUsing(fn ($path, $bytes) => str_ends_with($path, '/journal.next') ? false : $disk->put($path, $bytes));
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);

        try {
            app(ChunkStorage::class)->store($project, [UploadedFile::fake()->image('journal-failure.png')]);
            $this->fail('The upload must fail before writing a private image.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('recovery could not be saved', $error->getMessage());
        }
        app(WorkspaceDeletion::class)->cleanupPending();

        $this->assertSame(['writing/unowned/keep.png'], $disk->allFiles('writing'));
        $this->assertSame(['writing/unowned'], $disk->directories('writing'));
        $this->assertSame([], $disk->allFiles('images'));
        $this->assertFalse($project->images()->exists());
    }

    public function test_reconciliation_skips_an_active_private_write_until_its_reference_commits(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $project = Project::factory()->create(['slug' => 'active-private-write']);
        $proxy = Mockery::mock($disk);
        $proxy->shouldReceive('putFileAs')->andReturnUsing(function (...$arguments) use ($disk) {
            $path = $disk->putFileAs(...$arguments);
            app(WorkspaceDeletion::class)->cleanupPending();
            $disk->assertExists($path);

            return $path;
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);

        $chunk = app(ChunkStorage::class)->store($project, [UploadedFile::fake()->image('active-write.png')]);

        $disk->assertExists($chunk->images->sole()->path);
        $this->assertSame('active-write.png', $chunk->images->sole()->original_name);
    }

    public function test_a_sibling_arriving_after_empty_directory_inspection_survives_cleanup(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('deleting/late-sibling/remove.png', 'owned bytes');
        $intent = StagedFileDeletion::create(['paths' => ['deleting/late-sibling/remove.png']]);
        $proxy = Mockery::mock($disk);
        $added = false;
        $proxy->shouldReceive('path')->andReturnUsing(function ($directory) use ($disk, &$added) {
            $path = $disk->path($directory);
            if ($directory === 'deleting/late-sibling' && ! $added) {
                $added = true;
                $disk->put($directory.'/keep.png', 'late unlisted bytes');
            }

            return $path;
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);

        app(WorkspaceDeletion::class)->cleanupPending();

        $this->assertNull($intent->fresh());
        $this->assertSame('late unlisted bytes', $disk->get('deleting/late-sibling/keep.png'));
    }

    public function test_revocation_keeps_durable_cleanup_when_removing_the_key_file_fails(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'revoke-recovery@example.test']);
        $phone = $user->createToken('phone', UploadinyTokenAbility::phone());
        $access = app(AgentAccess::class);
        $this->assertTrue($access->rotate($user));
        $previous = $access->current($user);
        $disk = Storage::disk('local');
        $proxy = Mockery::mock($disk);
        $failure = true;
        $proxy->shouldReceive('delete')->andReturnUsing(function ($path) use (&$failure, $disk) {
            return $failure && $path === 'credentials/uploadiny-agent-token.txt' ? false : $disk->delete($path);
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);

        try {
            $access->revoke($user);
            $this->fail('A failed credential cleanup must remain observable and retryable.');
        } catch (RuntimeException $error) {
            $this->assertSame('Agent access recovery needs another attempt.', $error->getMessage());
        }
        $this->assertNull(PersonalAccessToken::findToken($previous));
        $this->assertSame($previous, $disk->get('credentials/uploadiny-agent-token.txt'));
        $disk->assertExists('credentials/.uploadiny-agent-token.recovery.json');
        $failure = false;
        app(WorkspaceDeletion::class)->cleanupPending();

        $disk->assertMissing('credentials/uploadiny-agent-token.txt');
        $this->assertSame($phone->accessToken->id, PersonalAccessToken::findToken($phone->plainTextToken)?->id);
    }
}

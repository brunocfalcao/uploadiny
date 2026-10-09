<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DescribeUploadImage;
use App\Jobs\DiscardIncompleteUpload;
use App\Project;
use App\Services\WorkspaceDeletion;
use App\StagedFileDeletion;
use App\UploadChunk;
use App\UploadImage;
use App\UploadinyTokenAbility;
use App\User;
use Illuminate\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OvernightFindingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_cleanup_intent_registration_rolls_back_feedback_and_removes_the_new_raster(): void
    {
        Storage::fake('local');
        $image = UploadImage::factory()->create(['annotated_path' => 'annotations/registration-old.png', 'comments' => 'old note', 'feedback_revision' => 0]);
        $disk = Storage::disk('local');
        $disk->put($image->path, 'original bytes');
        $disk->put($image->annotated_path, 'old raster');
        $before = $disk->allFiles('annotations');
        $stroke = ['tool' => 'line', 'color' => '#ff0000', 'width' => 0.01, 'points' => [['x' => 0.1, 'y' => 0.1], ['x' => 0.9, 'y' => 0.9]]];
        DB::statement("CREATE TRIGGER block_cleanup_registration BEFORE INSERT ON staged_file_deletions BEGIN SELECT RAISE(ABORT, 'controlled cleanup failure'); END");
        $this->actingAs(User::factory()->create(['email' => 'cleanup-registration@example.test']))
            ->patchJson(route('images.update', $image), ['annotations' => [$stroke], 'comments' => 'new note', 'revision' => 0, 'annotated_image' => 'data:image/png;base64,'.base64_encode(UploadedFile::fake()->image('replacement.png')->getContent())])->assertStatus(500);
        $this->assertSame('old note', $image->fresh()->comments);
        $this->assertSame(0, $image->fresh()->feedback_revision);
        $this->assertSame($before, $disk->allFiles('annotations'));
        $this->assertSame('old raster', $disk->get($image->annotated_path));
        $this->assertSame('original bytes', $disk->get($image->path));
    }

    public function test_old_drawing_cleanup_preserves_the_replacement_and_its_saved_feedback(): void
    {
        Storage::fake('local');
        $image = UploadImage::factory()->create(['annotated_path' => 'annotations/replacement-old.png', 'feedback_revision' => 0]);
        $disk = Storage::disk('local');
        $disk->put($image->path, 'original bytes');
        $disk->put($image->annotated_path, 'old raster');
        $stroke = ['tool' => 'line', 'color' => '#ff0000', 'width' => 0.01, 'points' => [['x' => 0.1, 'y' => 0.1], ['x' => 0.9, 'y' => 0.9]]];
        $raster = UploadedFile::fake()->image('replacement.png')->getContent();
        $this->actingAs(User::factory()->create(['email' => 'cleanup-replacement@example.test']))
            ->patchJson(route('images.update', $image), ['annotations' => [$stroke], 'comments' => 'replacement note', 'revision' => 0, 'annotated_image' => 'data:image/png;base64,'.base64_encode($raster)])->assertOk();
        $replacement = $image->fresh()->annotated_path;
        $this->assertNotSame($image->annotated_path, $replacement);
        app(WorkspaceDeletion::class)->cleanupPending();
        $disk->assertMissing($image->annotated_path);
        $this->assertSame($raster, $disk->get($replacement));
        $this->assertSame('replacement note', $image->fresh()->comments);
        $this->assertSame([$stroke], $image->fresh()->annotations);
        $this->assertSame('original bytes', $disk->get($image->path));
    }

    public function test_recovery_migration_preserves_legacy_cleanup_intents_and_their_retry_behavior(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('deleting/legacy/remove.png', 'legacy cleanup bytes');
        $disk->put('deleting/unowned/keep.png', 'keep unowned bytes');
        $intent = StagedFileDeletion::create(['paths' => ['deleting/legacy/remove.png']]);
        $migration = require database_path('migrations/2026_10_09_220430_add_recovery_details_to_staged_file_deletions_table.php');
        $migration->down();
        $this->assertSame(['deleting/legacy/remove.png'], $intent->fresh()->paths);
        $migration->up();
        $this->assertSame(0, $intent->fresh()->attempts);
        $this->assertNull($intent->fresh()->journal_path);
        app(WorkspaceDeletion::class)->cleanupPending();
        $this->assertNull($intent->fresh());
        $disk->assertMissing('deleting/legacy/remove.png');
        $this->assertSame('keep unowned bytes', $disk->get('deleting/unowned/keep.png'));
    }

    public function test_reconciliation_cannot_restore_files_during_an_active_deletion(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $image = UploadImage::factory()->create(['path' => 'images/active-deletion.png', 'annotated_path' => 'annotations/active-deletion.png']);
        $disk->put($image->path, 'original bytes');
        $disk->put($image->annotated_path, 'drawing bytes');
        $proxy = Mockery::mock($disk);
        $proxy->shouldReceive('move')->andReturnUsing(function ($from, $to) use ($disk, $image) {
            $result = $disk->move($from, $to);
            if ($from === $image->path) {
                $disk->assertMissing($image->path);
                app(WorkspaceDeletion::class)->cleanupPending();
                $disk->assertMissing($image->path);
                $this->assertSame($image->uuid, $image->fresh()->uuid);
            }

            return $result;
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        app(WorkspaceDeletion::class)->image($image);
        $this->assertNull($image->fresh());
        $this->assertSame([], $disk->allFiles('deleting'));
    }

    public function test_cleanup_status_reports_age_and_retry_progress_without_private_paths(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-10-10 01:00:00');
        $intent = StagedFileDeletion::create(['paths' => ['annotations/PRIVATE-feedback.png']]);
        $this->travel(75)->seconds();
        $this->artisan('uploadiny:cleanup-status')->expectsOutput(json_encode([
            'pending' => 1, 'oldest_pending_seconds' => 75, 'attempts' => 0, 'deferred' => 0, 'last_attempt_at' => null, 'recovery_journals' => 0,
        ], JSON_THROW_ON_ERROR))->assertSuccessful();
        $this->assertSame(0, $intent->fresh()->attempts);
        $disk = Storage::disk('local');
        $disk->put('annotations/PRIVATE-feedback.png', 'old private bytes');
        $proxy = Mockery::mock($disk);
        $fails = true;
        $proxy->shouldReceive('delete')->andReturnUsing(function ($path) use (&$fails, $disk) {
            return $fails ? false : $disk->delete($path);
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        app(WorkspaceDeletion::class)->cleanupPending();
        $this->artisan('uploadiny:cleanup-status')->expectsOutput(json_encode([
            'pending' => 1, 'oldest_pending_seconds' => 75, 'attempts' => 1, 'deferred' => 1, 'last_attempt_at' => '2026-10-10T01:01:15+00:00', 'recovery_journals' => 0,
        ], JSON_THROW_ON_ERROR))->assertSuccessful();
        $this->assertSame('old private bytes', $disk->get('annotations/PRIVATE-feedback.png'));
        $fails = false;
        app(WorkspaceDeletion::class)->cleanupPending();
        $this->artisan('uploadiny:cleanup-status')->expectsOutput(json_encode([
            'pending' => 0, 'oldest_pending_seconds' => null, 'attempts' => 0, 'deferred' => 0, 'last_attempt_at' => null, 'recovery_journals' => 0,
        ], JSON_THROW_ON_ERROR))->assertSuccessful();
        $disk->assertMissing('annotations/PRIVATE-feedback.png');
    }

    public function test_failed_superseded_drawing_cleanup_retries_without_reverting_feedback(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $image = UploadImage::factory()->create(['path' => 'images/cleanup-original.png', 'annotated_path' => 'annotations/cleanup-old.png', 'feedback_revision' => 0]);
        $disk->put($image->path, 'keep original');
        $disk->put($image->annotated_path, 'old drawing');
        $disk->put('annotations/unrelated.png', 'keep unrelated');
        $proxy = Mockery::mock($disk);
        $failure = 'false';
        $proxy->shouldReceive('delete')->andReturnUsing(function ($path) use (&$failure, $disk) {
            return match ($failure) {
                'false' => false,
                'throw' => throw new RuntimeException('private cleanup details'),
                default => $disk->delete($path),
            };
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        $this->assertSame('old drawing', $disk->get($image->annotated_path));
        $this->actingAs(User::factory()->create(['email' => 'cleanup-retry@example.test']))
            ->patchJson(route('images.update', $image), ['annotations' => [], 'comments' => 'saved note', 'revision' => 0])->assertOk()->assertJsonPath('revision', 1);
        $this->assertNull($image->fresh()->annotated_path);
        $this->assertSame('old drawing', $disk->get($image->annotated_path));
        $failure = 'throw';
        app(WorkspaceDeletion::class)->cleanupPending();
        $disk->assertExists($image->annotated_path);
        $failure = 'none';
        app(WorkspaceDeletion::class)->cleanupPending();
        $disk->assertMissing($image->annotated_path);
        $this->assertSame('saved note', $image->fresh()->comments);
        $this->assertSame('keep original', $disk->get($image->path));
        $this->assertSame('keep unrelated', $disk->get('annotations/unrelated.png'));
    }

    public function test_phone_completion_acknowledges_only_new_assets_and_keeps_full_feedback_private(): void
    {
        Storage::fake('local');
        Queue::fake([DescribeUploadImage::class, DiscardIncompleteUpload::class]);
        $project = Project::factory()->create(['slug' => 'phone-private-feedback']);
        $target = UploadChunk::factory()->create();
        $old = UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $target->id, 'comments' => 'private old note', 'annotations' => [['private' => 'old drawing']]]);
        $other = UploadImage::factory()->create(['chunk_id' => $target->id, 'comments' => 'private other project']);
        $draft = UploadChunk::factory()->create(['status' => 'uploading', 'upload_project_id' => $project->id, 'append_to_chunk_id' => $target->id, 'expected_images' => 1]);
        $new = UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $draft->id, 'comments' => 'new saved note']);
        $user = User::factory()->create(['email' => 'phone-private-feedback@example.test']);
        $phone = $user->createToken('phone', UploadinyTokenAbility::phone())->plainTextToken;
        $this->assertSame('private old note', $old->fresh()->comments);
        $response = $this->withToken($phone)->postJson(route('api.chunks.complete', $draft))->assertOk()->assertJsonPath('id', $target->uuid)->assertJsonCount(1, 'images')->assertJsonPath('images.0.id', $new->uuid)->assertJsonPath('images.0.comments', 'new saved note');
        $response->assertDontSee('private old note')->assertDontSee('private other project')->assertDontSee('old drawing');
        $this->getJson(route('api.projects.latest', $project))->assertForbidden();
        $this->getJson(route('api.images.download', $old))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken($user->createToken('agent', UploadinyTokenAbility::agent())->plainTextToken)
            ->getJson(route('api.projects.latest', $project))->assertOk()->assertJsonCount(2, 'chunk.images')->assertSee('private old note');
        $this->assertSame('private other project', $other->fresh()->comments);
    }

    public function test_start_enqueue_failure_removes_only_its_unpublished_empty_draft(): void
    {
        $project = Project::factory()->create(['slug' => 'enqueue-failure']);
        $published = UploadChunk::factory()->create(['upload_project_id' => $project->id]);
        $this->actingAs(User::factory()->create(['email' => 'enqueue-failure@example.test']));
        $this->assertSame([$published->id], UploadChunk::where('upload_project_id', $project->id)->pluck('id')->all());
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('controlled queue outage'));
        $this->postJson(route('chunks.start', $project), ['image_count' => 1])->assertStatus(500);
        $this->assertSame([$published->id], UploadChunk::where('upload_project_id', $project->id)->pluck('id')->all());
        Bus::swap(app(Dispatcher::class));
        Queue::fake([DiscardIncompleteUpload::class]);
        $retry = $this->postJson(route('chunks.start', $project), ['image_count' => 1])->assertCreated()->json('id');
        $this->assertSame('uploading', UploadChunk::where('uuid', $retry)->sole()->status);
        Queue::assertPushed(DiscardIncompleteUpload::class, 1);
        $this->assertSame('complete', $published->fresh()->status);
    }
}

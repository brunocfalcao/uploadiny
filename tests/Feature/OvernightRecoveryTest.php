<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DescribeUploadImage;
use App\Jobs\DiscardIncompleteUpload;
use App\Project;
use App\Services\AgentAccess;
use App\Services\FeedbackReader;
use App\Services\VisionDescription;
use App\Services\WorkspaceDeletion;
use App\UploadChunk;
use App\UploadImage;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OvernightRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_completion_uses_publication_time_and_does_not_rejoin_a_departed_project(): void
    {
        Storage::fake('local');
        Queue::fake([DescribeUploadImage::class, DiscardIncompleteUpload::class]);
        $this->travelTo('2026-10-08 08:00:00');
        $project = Project::factory()->create(['slug' => 'overnight-completion']);
        $other = Project::factory()->create(['slug' => 'overnight-other']);
        $target = UploadChunk::factory()->create(['completed_at' => now()]);
        $old = UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $target->id]);
        $this->actingAs(User::factory()->create(['email' => 'overnight-completion@example.test']));
        $start = $this->postJson(route('chunks.start', $project), ['image_count' => 1, 'append_to' => $target->uuid])->assertCreated()->json('id');
        $old->update(['project_id' => $other->id]);
        $this->assertFalse($project->chunks()->whereKey($target->id)->exists());
        $this->postJson(route('chunks.append', $start), ['file' => UploadedFile::fake()->image('overnight-new.png')])->assertCreated();
        $this->travelTo('2026-10-08 10:00:00');
        $this->postJson(route('chunks.complete', $start))->assertOk()->assertJsonPath('id', $start)->assertJsonPath('uploaded_at', '2026-10-08T10:00:00+00:00');
        $this->assertSame('2026-10-08T08:00:00+00:00', $target->fresh()->completed_at->toIso8601String());
        $this->assertSame($other->id, $old->fresh()->project_id);
        $this->assertSame('2026-10-08T10:00:00+00:00', app(FeedbackReader::class)->latest($project)['chunk']['uploaded_at']);
    }

    public function test_integer_string_revisions_save_but_stale_revisions_still_conflict(): void
    {
        $image = UploadImage::factory()->create(['comments' => '', 'feedback_revision' => 0]);
        $this->actingAs(User::factory()->create(['email' => 'overnight-revision@example.test']));
        $this->assertSame(0, $image->feedback_revision);
        $this->patchJson(route('images.update', $image), ['comments' => 'Exact note', 'annotations' => [], 'revision' => '0'])->assertOk()->assertJsonPath('revision', 1);
        $this->patchJson(route('images.update', $image), ['comments' => 'Stale note', 'annotations' => [], 'revision' => '0'])->assertConflict();
        $this->assertSame('Exact note', $image->fresh()->comments);
    }

    public function test_optional_queue_failure_preserves_published_upload_and_makes_description_retryable(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['email' => 'overnight-queue@example.test']));
        $project = Project::factory()->create(['slug' => 'overnight-queue']);
        Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('private queue details'));
        $response = $this->postJson(route('chunks.store', $project), ['files' => [UploadedFile::fake()->image('overnight-queue.png')]])->assertCreated();
        $chunk = UploadChunk::query()->where('uuid', $response->json('id'))->firstOrFail();
        $this->assertSame('complete', $chunk->status);
        $image = $chunk->images()->firstOrFail();
        $this->assertSame('failed', $image->description_status);
        Storage::disk('local')->assertExists($image->path);
        $this->postJson(route('images.describe', $image))->assertOk()->assertJsonPath('description_status', 'failed');
        $this->assertStringNotContainsString('private queue details', $image->fresh()->description_error);
    }

    public function test_synchronous_description_retry_returns_the_text_and_a_duplicate_job_cannot_reclaim_it(): void
    {
        $image = UploadImage::factory()->create(['description_status' => 'failed', 'description' => null]);
        $this->actingAs(User::factory()->create(['email' => 'overnight-description@example.test']));
        $vision = Mockery::mock(VisionDescription::class);
        $vision->shouldReceive('describe')->once()->andReturn('Exact description');
        $this->app->instance(VisionDescription::class, $vision);
        $this->postJson(route('images.describe', $image))->assertOk()->assertJsonPath('description_status', 'ready')->assertJsonPath('description', 'Exact description');
        (new DescribeUploadImage($image->id))->handle($vision);
        $this->assertSame('Exact description', $image->fresh()->description);
    }

    public function test_vision_failures_have_sanitized_diagnostics_without_private_provider_or_transport_details(): void
    {
        Storage::fake('local');
        config(['services.uploadiny.vision_key' => 'fixture-key']);
        $path = UploadedFile::fake()->image('diagnostics.png')->store('images', 'local');
        foreach ([[429, 'provider_refusal'], [500, 'provider_refusal'], [200, 'malformed_response'], [0, 'ConnectionException']] as [$status, $category]) {
            Log::swap(Mockery::spy(LogManager::class));
            Http::swap(new Factory);
            Http::fake(fn () => $status === 0 ? throw new ConnectionException('PRIVATE transport details') : Http::response(['error' => ['message' => 'PRIVATE provider details']], $status));
            $image = UploadImage::factory()->create(['path' => $path, 'description_status' => 'pending', 'comments' => 'PRIVATE owner feedback']);
            (new DescribeUploadImage($image->id))->handle(app(VisionDescription::class));
            $this->assertSame('failed', $image->fresh()->description_status);
            $this->assertStringNotContainsString('PRIVATE', $image->fresh()->description_error);
            Log::shouldHaveReceived('warning')->with('Image description failed.', ['image_id' => $image->id, 'category' => $category, 'http_status' => $status >= 400 ? $status : null])->once();
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_failed_jobs_can_be_recorded_and_listed_on_a_fresh_install(): void
    {
        $uuid = app('queue.failer')->log('database', 'default', '{"uuid":"00000000-0000-4000-8000-000000000008","displayName":"overnight-fixture","job":"overnight-fixture"}', new RuntimeException('controlled fixture'));
        $this->assertIsString($uuid);
        $this->assertSame($uuid, app('queue.failer')->find($uuid)->id);
        $this->artisan('queue:failed')->expectsOutputToContain('overnight-fixture')->assertSuccessful();
        app('queue.failer')->forget($uuid);
        $this->assertNull(app('queue.failer')->find($uuid));
    }

    public function test_a_second_worker_cannot_claim_a_description_after_a_stale_read(): void
    {
        $image = UploadImage::factory()->create(['description_status' => 'pending', 'description' => null]);
        $claimed = false;
        DB::listen(function ($query) use ($image, &$claimed): void {
            if (! $claimed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'upload_images')) {
                $claimed = true;
                UploadImage::whereKey($image->id)->update(['description_status' => 'processing']);
            }
        });
        $vision = Mockery::mock(VisionDescription::class);
        $vision->shouldNotReceive('describe');
        (new DescribeUploadImage($image->id))->handle($vision);
        $this->assertSame('processing', $image->fresh()->description_status);
        $this->assertNull($image->fresh()->description);
    }

    public function test_committed_deletion_retains_retryable_file_cleanup_and_preserves_unrelated_files(): void
    {
        Storage::fake('local');
        $image = UploadImage::factory()->create(['path' => 'images/overnight-delete.png']);
        $disk = Storage::disk('local');
        $disk->put($image->path, 'remove these bytes');
        $disk->put('deleting/unowned/keep.png', 'rollback recovery bytes');
        $proxy = Mockery::mock($disk);
        $fails = true;
        $proxy->shouldReceive('delete')->andReturnUsing(function ($paths) use (&$fails, $disk) {
            return $fails ? false : $disk->delete($paths);
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        $this->actingAs(User::factory()->create(['email' => 'overnight-delete@example.test']))
            ->deleteJson(route('images.destroy', $image))->assertOk();
        $this->assertNull($image->fresh());
        $intent = DB::table('staged_file_deletions')->first();
        $this->assertNotNull($intent);
        $fails = false;
        app(WorkspaceDeletion::class)->cleanupPending();
        $this->assertDatabaseMissing('staged_file_deletions', ['id' => $intent->id]);
        $this->assertSame(['deleting/unowned/keep.png'], $disk->allFiles('deleting'));
    }

    public function test_permission_failure_removes_new_setup_file_and_leaves_retry_possible(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $proxy = Mockery::mock($disk);
        $proxy->shouldReceive('path')->with('initial-login.txt')->andReturn(sys_get_temp_dir().'/uploadiny-missing-'.uniqid().'/initial-login.txt');
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        try {
            $this->artisan('uploadiny:account', ['email' => 'overnight-permissions@example.test', '--generate' => true])->run();
        } catch (\Throwable) {
        }
        $disk->assertMissing('initial-login.txt');
        $this->assertFalse(User::where('email', 'overnight-permissions@example.test')->exists());
    }

    public function test_failed_agent_key_restoration_reports_recovery_failure_and_keeps_the_previous_token(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'overnight-key-recovery@example.test']);
        $access = app(AgentAccess::class);
        $this->assertTrue($access->rotate($user));
        $disk = Storage::disk('local');
        $previous = $disk->get('credentials/uploadiny-agent-token.txt');
        $proxy = Mockery::mock($disk);
        $moves = 0;
        $proxy->shouldReceive('move')->andReturnUsing(function ($from, $to) use ($disk, &$moves) {
            return ++$moves === 1 ? $disk->move($from, $to) : false;
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        DB::statement("CREATE TRIGGER overnight_block_token BEFORE DELETE ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'controlled'); END");
        try {
            $access->rotate($user);
            $this->fail('Rotation must fail.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('restored', $error->getMessage());
            $this->assertNotNull($error->getPrevious());
        }
        $this->assertNotNull(PersonalAccessToken::findToken($previous));
        $this->assertNull($access->current($user));
    }

    public function test_project_discovery_uses_a_constant_query_count_and_preserves_shared_chunk_counts(): void
    {
        $empty = Project::factory()->create(['slug' => 'overnight-empty']);
        $projects = Project::factory()->count(10)->create();
        $shared = UploadChunk::factory()->create(['completed_at' => now()]);
        foreach ($projects as $project) {
            UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $shared->id]);
        }
        DB::enableQueryLog();
        $result = app(FeedbackReader::class)->projects()['projects'];
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(4, $queries);
        $byId = collect($result)->keyBy('id');
        $this->assertNull($byId[$empty->id]['latest_chunk']);
        foreach ($projects as $project) {
            $this->assertSame(['id' => $shared->uuid, 'completed_at' => $shared->completed_at->toIso8601String(), 'asset_count' => 1], $byId[$project->id]['latest_chunk']);
        }
    }

    public function test_the_gallery_bounds_history_and_an_old_file_remains_reachable_directly(): void
    {
        $project = Project::factory()->create(['slug' => 'overnight-history']);
        $chunks = UploadChunk::factory()->count(30)->create(['completed_at' => null]);
        foreach ($chunks as $chunk) {
            UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $chunk->id]);
        }
        $old = $chunks->first()->images()->firstOrFail();
        $this->actingAs(User::factory()->create(['email' => 'overnight-history@example.test']));
        $this->get(route('projects.show', $project))->assertViewHas('chunks', fn ($chunks) => $chunks->count() === 24)->assertSee('Older uploads');
        $this->get(route('projects.show', $project).'?page=2')->assertViewHas('chunks', fn ($chunks) => $chunks->count() === 6);
        $this->get(route('projects.show', $project).'?image='.$old->uuid)->assertViewHas('openImage', fn ($image) => $image->uuid === $old->uuid);
    }
}

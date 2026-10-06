<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Project;
use App\UploadChunk;
use App\UploadImage;
use App\UploadinyTokenAbility;
use App\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReleaseSafeguardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_feedback_is_the_chunk_that_finished_last_not_the_one_started_last(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'latest-order@example.test']);
        $project = Project::factory()->create(['name' => 'Ordering', 'slug' => 'ordering']);
        $startedFirst = UploadChunk::factory()->create(['status' => 'complete', 'completed_at' => now()]);
        $startedSecond = UploadChunk::factory()->create(['status' => 'complete', 'completed_at' => now()->subMinutes(10)]);
        UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $startedFirst->id]);
        UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $startedSecond->id]);
        $this->assertGreaterThan($startedFirst->id, $startedSecond->id);

        $this->withToken($user->createToken('agent', UploadinyTokenAbility::agent())->plainTextToken)
            ->getJson(route('api.feedback.latest', ['project' => $project->canonical]))
            ->assertOk()
            ->assertJsonPath('chunk.id', $startedFirst->uuid);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->actingAs($user)->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('data-latest="'.$startedFirst->uuid.'"', false);
        $destinations = $this->getJson(route('chunks.index'))->assertOk()->json('destinations');
        $this->assertSame([$startedFirst->uuid, $startedSecond->uuid], array_column($destinations, 'chunk_id'));
    }

    public function test_chunk_destinations_report_per_project_file_counts_for_completed_chunks_only(): void
    {
        $user = User::factory()->create(['email' => 'destinations@example.test']);
        $first = Project::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $second = Project::factory()->create(['name' => 'Beta', 'slug' => 'beta']);
        $shared = UploadChunk::factory()->create(['status' => 'complete', 'completed_at' => now()]);
        UploadImage::factory()->count(2)->create(['project_id' => $first->id, 'chunk_id' => $shared->id]);
        UploadImage::factory()->create(['project_id' => $second->id, 'chunk_id' => $shared->id]);
        $draft = UploadChunk::factory()->create(['status' => 'uploading', 'upload_project_id' => $first->id, 'expected_images' => 2]);
        UploadImage::factory()->create(['project_id' => $first->id, 'chunk_id' => $draft->id]);

        $this->actingAs($user)->getJson(route('chunks.index'))->assertOk()->assertExactJson(['destinations' => [
            ['chunk_id' => $shared->uuid, 'project_id' => $first->id, 'project_name' => 'Alpha', 'uploaded_at' => $shared->created_at->toIso8601String(), 'file_count' => 2],
            ['chunk_id' => $shared->uuid, 'project_id' => $second->id, 'project_name' => 'Beta', 'uploaded_at' => $shared->created_at->toIso8601String(), 'file_count' => 1],
        ]]);
    }

    public function test_files_over_95_megabytes_are_refused_before_they_join_a_chunk(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'size-limit@example.test']);
        $project = Project::factory()->create(['slug' => 'size-limit']);
        $chunk = UploadChunk::factory()->create(['status' => 'uploading', 'upload_project_id' => $project->id, 'expected_images' => 1]);

        $this->actingAs($user)->postJson(route('chunks.append', $chunk), ['file' => UploadedFile::fake()->create('long.mp4', 97281, 'video/mp4')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'must not be greater than 97280 kilobytes']);
        $this->postJson(route('chunks.store', $project), ['files' => [UploadedFile::fake()->create('long.mov', 97281, 'video/quicktime')]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files.0' => 'must not be greater than 97280 kilobytes']);

        $this->assertSame(0, UploadImage::query()->count());
        $this->assertSame('uploading', $chunk->fresh()?->status);
        $this->assertSame([], Storage::disk('local')->allFiles('images'));
    }

    public function test_moving_the_last_image_out_of_a_chunk_removes_the_empty_chunk(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'move-empty@example.test']);
        $project = Project::factory()->create(['slug' => 'move-empty']);
        $source = UploadChunk::factory()->create(['status' => 'complete']);
        $target = UploadChunk::factory()->create(['status' => 'complete']);
        $kept = UploadChunk::factory()->create(['status' => 'complete']);
        $moving = UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $source->id]);
        UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $target->id]);
        $staying = UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $kept->id]);
        $sibling = UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $kept->id]);

        $this->actingAs($user)->postJson(route('images.transfer-chunk', $moving), ['chunk_id' => $target->uuid, 'project_id' => $project->id, 'action' => 'move'])->assertOk();
        $this->postJson(route('images.transfer-chunk', $staying), ['chunk_id' => $target->uuid, 'project_id' => $project->id, 'action' => 'move'])->assertOk();

        $this->assertNull($source->fresh());
        $this->assertSame($target->id, $moving->fresh()?->chunk_id);
        $this->assertNotNull($kept->fresh());
        $this->assertSame($kept->id, $sibling->fresh()?->chunk_id);
        $this->assertSame(3, $target->images()->count());
    }

    public function test_moving_the_only_image_out_of_an_upload_in_progress_keeps_the_upload_open(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'move-draft@example.test']);
        $project = Project::factory()->create(['slug' => 'move-draft']);
        $draft = UploadChunk::factory()->create(['status' => 'uploading', 'upload_project_id' => $project->id, 'expected_images' => 2]);
        $target = UploadChunk::factory()->create(['status' => 'complete']);
        $moving = UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $draft->id]);
        UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $target->id]);

        $this->actingAs($user)->postJson(route('images.transfer-chunk', $moving), ['chunk_id' => $target->uuid, 'project_id' => $project->id, 'action' => 'move'])->assertOk();

        $this->assertSame('uploading', $draft->fresh()?->status);
        $this->postJson(route('chunks.append', $draft), ['file' => UploadedFile::fake()->image('next.png')])->assertCreated();
    }

    public function test_the_agent_gets_a_tool_error_instead_of_a_crash_for_screenshots_over_250_megabytes(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'huge-asset@example.test']);
        $image = UploadImage::factory()->create(['path' => 'images/huge.png', 'mime_type' => 'image/png']);
        Storage::disk('local')->put('images/huge.png', '');
        $handle = fopen(Storage::disk('local')->path('images/huge.png'), 'r+');
        $this->assertNotFalse($handle);
        ftruncate($handle, 250 * 1024 * 1024 + 1);
        fclose($handle);
        $this->withToken($user->createToken('agent', UploadinyTokenAbility::agent())->plainTextToken);

        $response = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_asset', 'arguments' => ['asset_id' => $image->uuid]]])
            ->assertOk()
            ->assertJsonPath('result.isError', true);

        $this->assertStringContainsString('larger than 250 MB', (string) $response->json('result.content.0.text'));
        $this->assertSame(250 * 1024 * 1024 + 1, Storage::disk('local')->size('images/huge.png'));
    }

    public function test_agent_token_command_explains_a_missing_or_ambiguous_account(): void
    {
        $this->artisan('uploadiny:agent-token')->expectsOutputToContain('Create the personal Uploadiny account first.')->assertFailed();

        User::factory()->count(2)->create();
        $this->artisan('uploadiny:agent-token')->expectsOutputToContain('Uploadiny must have exactly one personal account.')->assertFailed();
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_a_failed_account_creation_removes_the_generated_credentials_file_so_setup_can_be_retried(): void
    {
        Storage::fake('local');
        DB::statement("CREATE TRIGGER block_account BEFORE INSERT ON users BEGIN SELECT RAISE(ABORT, 'blocked'); END");

        try {
            $this->artisan('uploadiny:account', ['email' => 'retry@example.test', '--generate' => true])->run();
            $this->fail('Account creation should fail while inserts are blocked.');
        } catch (QueryException) {
        }

        Storage::disk('local')->assertMissing('initial-login.txt');
        DB::statement('DROP TRIGGER block_account');
        $this->artisan('uploadiny:account', ['email' => 'retry@example.test', '--generate' => true])->assertSuccessful();
        Storage::disk('local')->assertExists('initial-login.txt');
        $this->assertSame(1, User::query()->where('email', 'retry@example.test')->count());
    }
}

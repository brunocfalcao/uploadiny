<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Project;
use App\UploadChunk;
use App\UploadImage;
use App\UploadinyTokenAbility;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class McpChunkDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function reader(string $email): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => $email]);
        $this->withToken($user->createToken('mcp-delete-test', UploadinyTokenAbility::agent())->plainTextToken);
    }

    /** @param array<string, mixed> $arguments */
    private function tool(string $name, array $arguments): TestResponse
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => (object) $arguments],
        ]);
    }

    private function deleteChunk(Project $project, UploadChunk $chunk): TestResponse
    {
        return $this->tool('delete_chunk', ['project_canonical' => $project->canonical, 'chunk_id' => $chunk->uuid, 'review_token' => $chunk->reviewToken($project)]);
    }

    public function test_deletes_only_the_pinned_completed_chunk_and_exposes_the_surviving_latest_chunk(): void
    {
        $this->reader('mcp-delete-pinned@example.test');
        $project = Project::factory()->create(['slug' => 'mcp-delete-pinned']);
        $old = UploadImage::factory()->create(['project_id' => $project->id]);
        $reviewed = UploadImage::factory()->create(['project_id' => $project->id, 'annotated_path' => 'mcp-delete-pinned/marked.png', 'comments' => 'Fixed feedback.']);
        $second = UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $reviewed->chunk_id, 'mime_type' => 'video/mp4']);
        $new = UploadImage::factory()->create(['project_id' => $project->id, 'comments' => 'New unreviewed feedback.']);
        $chunk = UploadChunk::findOrFail($reviewed->chunk_id);
        foreach ([$old, $reviewed, $second, $new] as $image) {
            Storage::disk('local')->put($image->path, $image->uuid);
        }
        Storage::disk('local')->put($reviewed->annotated_path, 'marked bytes');
        $oldBefore = $old->fresh()->getAttributes();
        $newBefore = $new->fresh()->getAttributes();
        $this->assertSame([$reviewed->uuid, $second->uuid], $chunk->images()->orderBy('id')->pluck('uuid')->all());
        $this->tool('get_feedback', ['project_canonical' => $project->canonical])->assertJsonPath('result.structuredContent.chunk.id', $new->chunk->uuid);

        $this->deleteChunk($project, $chunk)->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent', [
                'project_canonical' => $project->canonical, 'chunk_id' => $chunk->uuid,
                'deleted_asset_ids' => [$reviewed->uuid, $second->uuid], 'chunk_deleted' => true,
            ]);

        $this->assertDatabaseMissing('upload_chunks', ['id' => $chunk->id]);
        $this->assertDatabaseMissing('upload_images', ['id' => $reviewed->id]);
        $this->assertDatabaseMissing('upload_images', ['id' => $second->id]);
        Storage::disk('local')->assertMissing([$reviewed->path, $reviewed->annotated_path, $second->path]);
        $this->assertSame($oldBefore, $old->fresh()->getAttributes());
        $this->assertSame($newBefore, $new->fresh()->getAttributes());
        $this->assertSame($old->uuid, Storage::disk('local')->get($old->path));
        $this->assertSame($new->uuid, Storage::disk('local')->get($new->path));
        $this->assertSame([], Storage::disk('local')->allFiles('deleting'));
        $this->tool('get_feedback', ['project_canonical' => $project->canonical])->assertJsonPath('result.structuredContent.chunk.id', $new->chunk->uuid);
        $this->deleteChunk($project, $chunk)->assertOk()->assertJsonPath('result.isError', true);
        $this->assertSame($newBefore, $new->fresh()->getAttributes());
    }

    public function test_deleting_the_latest_single_asset_chunk_reveals_older_feedback(): void
    {
        $this->reader('mcp-delete-latest@example.test');
        $project = Project::factory()->create(['slug' => 'mcp-delete-latest']);
        $old = UploadImage::factory()->create(['project_id' => $project->id, 'comments' => 'Older feedback.']);
        $latest = UploadImage::factory()->create(['project_id' => $project->id]);
        $this->tool('get_feedback', ['project_canonical' => $project->canonical])->assertJsonPath('result.structuredContent.chunk.id', $latest->chunk->uuid);

        $this->deleteChunk($project, $latest->chunk)->assertOk()->assertJsonPath('result.structuredContent.deleted_asset_ids', [$latest->uuid]);

        $this->assertDatabaseMissing('upload_images', ['id' => $latest->id]);
        $this->assertDatabaseHas('upload_images', ['id' => $old->id, 'comments' => 'Older feedback.']);
        $this->tool('get_feedback', ['project_canonical' => $project->canonical])->assertJsonPath('result.structuredContent.chunk.id', $old->chunk->uuid);
    }

    public function test_preserves_assets_in_other_projects_and_assets_moved_to_another_chunk(): void
    {
        $this->reader('mcp-delete-moved@example.test');
        $project = Project::factory()->create(['slug' => 'mcp-delete-moved']);
        $other = Project::factory()->create(['slug' => 'mcp-delete-other']);
        $image = UploadImage::factory()->create(['project_id' => $project->id]);
        $elsewhere = UploadImage::factory()->create(['project_id' => $other->id, 'chunk_id' => $image->chunk_id, 'comments' => 'Keep elsewhere.']);
        $moved = UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $image->chunk_id]);
        $target = UploadChunk::factory()->create();
        $moved->update(['chunk_id' => $target->id]);
        $elsewhereBefore = $elsewhere->fresh()->getAttributes();
        $movedBefore = $moved->fresh()->getAttributes();
        foreach ([$image, $elsewhere, $moved] as $asset) {
            Storage::disk('local')->put($asset->path, $asset->uuid);
        }
        $this->assertSame(2, $image->chunk->images()->count());

        $this->deleteChunk($project, $image->chunk)->assertOk()->assertJsonPath('result.structuredContent.deleted_asset_ids', [$image->uuid])->assertJsonPath('result.structuredContent.chunk_deleted', false);

        $this->assertDatabaseMissing('upload_images', ['id' => $image->id]);
        $this->assertDatabaseHas('upload_chunks', ['id' => $image->chunk_id]);
        $this->assertSame($elsewhereBefore, $elsewhere->fresh()->getAttributes());
        $this->assertSame($movedBefore, $moved->fresh()->getAttributes());
        $this->assertSame($elsewhere->uuid, Storage::disk('local')->get($elsewhere->path));
        $this->assertSame($moved->uuid, Storage::disk('local')->get($moved->path));
        Storage::disk('local')->assertMissing($image->path);
    }

    public function test_unknown_project_wrong_project_unknown_chunk_and_drafts_leave_feedback_untouched(): void
    {
        $this->reader('mcp-delete-refused@example.test');
        $image = UploadImage::factory()->create();
        $other = Project::factory()->create(['slug' => 'mcp-delete-wrong']);
        $draft = UploadChunk::factory()->create(['status' => 'uploading', 'upload_project_id' => $image->project_id]);
        $partial = UploadImage::factory()->create(['project_id' => $image->project_id, 'chunk_id' => $draft->id]);
        Storage::disk('local')->put($image->path, 'keep complete');
        Storage::disk('local')->put($partial->path, 'keep partial');
        $before = $image->fresh()->getAttributes();
        $partialBefore = $partial->fresh()->getAttributes();

        $this->deleteChunk($other, $image->chunk)->assertOk()->assertJsonPath('result.isError', true);
        $this->tool('delete_chunk', ['project_canonical' => 'zzzzzz', 'chunk_id' => $image->chunk->uuid])->assertOk()->assertJsonPath('result.isError', true);
        $this->tool('delete_chunk', ['project_canonical' => $image->project->canonical, 'chunk_id' => '00000000-0000-4000-8000-000000000001'])->assertOk()->assertJsonPath('result.isError', true);
        $this->deleteChunk($image->project, $draft)->assertOk()->assertJsonPath('result.isError', true);

        $this->assertSame($before, $image->fresh()->getAttributes());
        $this->assertSame($partialBefore, $partial->fresh()->getAttributes());
        $this->assertSame('uploading', $draft->fresh()->status);
        $this->assertSame('keep complete', Storage::disk('local')->get($image->path));
        $this->assertSame('keep partial', Storage::disk('local')->get($partial->path));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidArguments(): array
    {
        return [
            'missing fields' => [[]],
            'invalid project code' => [['project_canonical' => '../taxiny', 'chunk_id' => '00000000-0000-4000-8000-000000000001']],
            'invalid chunk ID' => [['project_canonical' => 'abcdef', 'chunk_id' => 'latest']],
            'null chunk ID' => [['project_canonical' => 'abcdef', 'chunk_id' => null]],
        ];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidArguments')]
    public function test_invalid_deletion_arguments_do_not_change_records_or_files(array $arguments): void
    {
        $this->reader('mcp-delete-invalid@example.test');
        $image = UploadImage::factory()->create();
        Storage::disk('local')->put($image->path, 'keep invalid input');
        $before = $image->fresh()->getAttributes();

        $this->tool('delete_chunk', $arguments)->assertOk()->assertJsonPath('result.isError', true);

        $this->assertSame($before, $image->fresh()->getAttributes());
        $this->assertSame('keep invalid input', Storage::disk('local')->get($image->path));
    }

    public function test_phone_browser_missing_invalid_and_read_partial_tokens_cannot_delete(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'mcp-delete-auth@example.test']);
        $image = UploadImage::factory()->create();
        Storage::disk('local')->put($image->path, 'keep unauthorized');
        $before = $image->fresh()->getAttributes();
        $project = $image->project;
        $chunk = $image->chunk;

        $this->deleteChunk($project, $chunk)->assertUnauthorized();
        $this->withToken('invalid-delete-key')->deleteChunk($project, $chunk)->assertUnauthorized();
        $this->withToken($user->createToken('delete-phone', UploadinyTokenAbility::phone())->plainTextToken)->deleteChunk($project, $chunk)->assertForbidden();
        $this->withToken($user->createToken('delete-partial', [UploadinyTokenAbility::FEEDBACK_READ])->plainTextToken)->deleteChunk($project, $chunk)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->actingAs($user)->deleteChunk($project, $chunk)->assertUnauthorized();

        $this->assertSame($before, $image->fresh()->getAttributes());
        $this->assertSame('keep unauthorized', Storage::disk('local')->get($image->path));
    }

    public function test_database_failure_restores_files_and_records_and_returns_a_safe_tool_error(): void
    {
        $this->reader('mcp-delete-rollback@example.test');
        $image = UploadImage::factory()->create(['annotated_path' => 'mcp-delete-rollback/marked.png']);
        Storage::disk('local')->put($image->path, 'original rollback bytes');
        Storage::disk('local')->put($image->annotated_path, 'marked rollback bytes');
        $before = $image->fresh()->getAttributes();
        $chunk = $image->chunk;
        UploadChunk::deleting(function (UploadChunk $candidate) use ($chunk): void {
            if ($candidate->id === $chunk->id) {
                throw new \RuntimeException('Private database failure /private/path');
            }
        });

        $this->deleteChunk($image->project, $chunk)->assertOk()->assertJsonPath('result.isError', true)->assertDontSee('/private/path');

        $this->assertDatabaseHas('upload_chunks', ['id' => $chunk->id]);
        $this->assertSame($before, $image->fresh()->getAttributes());
        $this->assertSame('original rollback bytes', Storage::disk('local')->get($image->path));
        $this->assertSame('marked rollback bytes', Storage::disk('local')->get($image->annotated_path));
        $this->assertSame([], Storage::disk('local')->allFiles('deleting'));
    }

    public function test_file_staging_failure_restores_already_staged_files_without_deleting_records(): void
    {
        $this->reader('mcp-delete-files@example.test');
        $image = UploadImage::factory()->create(['annotated_path' => 'mcp-delete-files/marked.png']);
        $disk = Storage::disk('local');
        $disk->put($image->path, 'original staged bytes');
        $disk->put($image->annotated_path, 'marked staged bytes');
        $before = $image->fresh()->getAttributes();
        $proxy = \Mockery::mock($disk);
        $proxy->shouldReceive('move')->andReturnUsing(fn (string $from, string $to): bool => $from === $image->annotated_path ? false : $disk->move($from, $to));
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);

        $this->deleteChunk($image->project, $image->chunk)->assertOk()->assertJsonPath('result.isError', true);

        $this->assertSame($before, $image->fresh()->getAttributes());
        $this->assertDatabaseHas('upload_chunks', ['id' => $image->chunk_id]);
        $this->assertSame('original staged bytes', $disk->get($image->path));
        $this->assertSame('marked staged bytes', $disk->get($image->annotated_path));
        $this->assertSame([], $disk->allFiles('deleting'));
    }

    public function test_final_file_cleanup_failure_retains_a_durable_cleanup_intent_after_records_are_removed(): void
    {
        $this->reader('mcp-delete-cleanup@example.test');
        $image = UploadImage::factory()->create();
        $disk = Storage::disk('local');
        $disk->put($image->path, 'staged cleanup bytes');
        $this->assertDatabaseHas('upload_images', ['id' => $image->id]);
        $proxy = \Mockery::mock($disk);
        $proxy->shouldReceive('delete')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);

        $this->deleteChunk($image->project, $image->chunk)->assertOk()->assertJsonPath('result.isError', false);
        $this->assertDatabaseHas('staged_file_deletions', ['paths' => json_encode($disk->allFiles('deleting'))]);

        $this->assertDatabaseMissing('upload_images', ['id' => $image->id]);
        $this->assertDatabaseMissing('upload_chunks', ['id' => $image->chunk_id]);
        $disk->assertMissing($image->path);
        $files = $disk->allFiles('deleting');
        $this->assertCount(1, $files);
        $this->assertSame('staged cleanup bytes', $disk->get($files[0]));
    }

    public function test_cleanup_refuses_appended_assets_and_later_feedback_until_reviewed_again(): void
    {
        $this->reader('overnight-stale-review@example.test');
        $project = Project::factory()->create(['slug' => 'overnight-stale-review']);
        $image = UploadImage::factory()->create(['project_id' => $project->id, 'comments' => 'reviewed']);
        $chunk = $image->chunk;
        $review = $this->tool('get_feedback', ['project_canonical' => $project->canonical])->assertOk()->json('result.structuredContent.chunk.review_token');
        $this->assertIsString($review);
        $later = UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $chunk->id, 'comments' => 'unreviewed']);
        $arguments = ['project_canonical' => $project->canonical, 'chunk_id' => $chunk->uuid, 'review_token' => $review];
        $this->tool('delete_chunk', $arguments)->assertJsonPath('result.isError', true)->assertSee('changed');
        $this->assertSame('unreviewed', $later->fresh()->comments);
        $review = $this->tool('get_feedback', ['project_canonical' => $project->canonical])->json('result.structuredContent.chunk.review_token');
        $image->update(['comments' => 'later edit', 'feedback_revision' => 1]);
        $arguments['review_token'] = $review;
        $this->tool('delete_chunk', $arguments)->assertJsonPath('result.isError', true);
        $this->assertSame('later edit', $image->fresh()->comments);
        $arguments['review_token'] = $this->tool('get_feedback', ['project_canonical' => $project->canonical])->json('result.structuredContent.chunk.review_token');
        $this->tool('delete_chunk', $arguments)->assertJsonPath('result.isError', false);
        $this->assertNull($image->fresh());
        $this->assertNull($later->fresh());
    }
}

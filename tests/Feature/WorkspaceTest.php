<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DescribeUploadImage;
use App\Jobs\DiscardIncompleteUpload;
use App\Project;
use App\Services\VisionDescription;
use App\Services\WorkspaceDeletion;
use App\UploadChunk;
use App\UploadImage;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.uploadiny.upload_token' => 'workspace-test-token', 'services.uploadiny.vision_key' => 'test-vision-key']);
    }

    private function prepare(): User
    {
        Storage::fake('local');
        Queue::fake([DescribeUploadImage::class, DiscardIncompleteUpload::class]);
        $user = User::factory()->create(['email' => 'workspace-owner@example.test']);
        $this->actingAs($user);

        return $user;
    }

    private function upload(Project $project, int $count = 1): TestResponse
    {
        $files = [];
        for ($i = 0; $i < $count; $i++) {
            $files[] = UploadedFile::fake()->image("feedback-{$i}.png", 30, 20);
        }

        return $this->postJson(route('chunks.store', $project), ['files' => $files]);
    }

    public function test_all_private_surfaces_reject_guests_and_invalid_api_tokens(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create(['slug' => 'private-taxiny']);
        $image = UploadImage::factory()->create(['project_id' => $project->id]);
        Storage::disk('local')->put($image->path, 'private');
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('projects.show', $project))->assertRedirect(route('login'));
        $this->get(route('images.preview', $image))->assertRedirect(route('login'));
        $this->get(route('images.download', $image))->assertRedirect(route('login'));
        $this->patchJson(route('images.update', $image), [])->assertUnauthorized();
        $this->getJson('/api/projects')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->withHeader('Authorization', 'Bearer wrong-token')->getJson(route('api.images.download', $image))->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer workspace-test-token')->getJson(route('api.images.download', $image))->assertDownload($image->name);
        $this->assertSame('private', Storage::disk('local')->get($image->path));
    }

    public function test_login_rejects_bad_credentials_and_logout_removes_access(): void
    {
        $user = User::factory()->create(['email' => 'login-owner@example.test']);
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'test-password-123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
    }

    public function test_projects_can_be_created_edited_and_rendered_without_executing_names(): void
    {
        $this->prepare();
        $this->assertDatabaseMissing('projects', ['slug' => 'taxiny']);
        $this->post('/projects', ['name' => '<script>alert(1)</script>', 'slug' => 'taxiny', 'description' => 'Receipt feedback'])->assertRedirect('/projects/taxiny');
        $this->assertDatabaseHas('projects', ['slug' => 'taxiny', 'description' => 'Receipt feedback']);
        $this->get('/projects/taxiny')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->patch('/projects/taxiny', ['name' => 'Taxiny', 'slug' => 'taxiny-new', 'description' => 'Changed'])->assertRedirect('/projects/taxiny-new');
        $this->assertDatabaseHas('projects', ['slug' => 'taxiny-new', 'name' => 'Taxiny', 'description' => 'Changed']);
        $this->postJson('/projects', ['name' => 'Duplicate', 'slug' => 'taxiny-new'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        Queue::assertNotPushed(DescribeUploadImage::class);
    }

    public function test_latest_chunk_contains_the_whole_latest_group_and_one_image_is_also_a_chunk(): void
    {
        $this->prepare();
        $project = Project::factory()->create(['slug' => 'chunk-taxiny']);
        $other = Project::factory()->create(['slug' => 'chunk-other']);
        $this->withHeader('Authorization', 'Bearer workspace-test-token')->getJson(route('api.projects.latest', $project))->assertJsonPath('chunk', null);
        $first = $this->upload($project, 2)->assertCreated()->assertJsonCount(2, 'images');
        $this->upload($other, 3)->assertCreated();
        $latest = $this->upload($project, 3)->assertCreated()->assertJsonCount(3, 'images');
        $this->getJson(route('api.projects.latest', $project))->assertOk()->assertJsonPath('chunk.id', $latest->json('id'))->assertJsonCount(3, 'chunk.images');
        $this->assertNotSame($first->json('id'), $latest->json('id'));
        $single = $this->upload($project)->assertCreated();
        $this->getJson(route('api.projects.latest', $project))->assertJsonPath('chunk.id', $single->json('id'))->assertJsonCount(1, 'chunk.images')->assertJsonPath('chunk.images.0.name', 'upload-9.png');
        foreach ($latest->json('images') as $image) {
            $this->assertDatabaseHas('upload_images', ['uuid' => $image['id'], 'project_id' => $project->id]);
        }
        Queue::assertPushed(DescribeUploadImage::class, 9);
    }

    public function test_invalid_groups_are_rejected_before_any_file_or_chunk_is_saved(): void
    {
        $this->prepare();
        $project = Project::factory()->create(['slug' => 'invalid-group']);
        $this->assertSame([], Storage::disk('local')->allFiles('images'));
        $this->postJson(route('chunks.store', $project), ['files' => []])->assertUnprocessable();
        $this->postJson(route('chunks.store', $project), ['files' => [UploadedFile::fake()->image('valid.png'), UploadedFile::fake()->createWithContent('unsafe.php', '<?php echo 1;')]])->assertUnprocessable();
        $this->assertSame([], Storage::disk('local')->allFiles('images'));
        $this->assertSame(0, UploadChunk::query()->count());
        Queue::assertNotPushed(DescribeUploadImage::class);
    }

    public function test_saved_feedback_is_returned_to_the_agent_and_stale_edits_cannot_overwrite_it(): void
    {
        $this->prepare();
        $project = Project::factory()->create(['slug' => 'annotated-taxiny']);
        $uploaded = $this->upload($project)->assertCreated()->json('images.0');
        $image = UploadImage::where('uuid', $uploaded['id'])->sole();
        $this->assertSame([], $image->annotations);
        $stroke = ['tool' => 'arrow', 'color' => '#ef4444', 'width' => 0.003, 'points' => [['x' => 0.1, 'y' => 0.2], ['x' => 0.7, 'y' => 0.8]]];
        $png = UploadedFile::fake()->image('marked.png', 30, 20)->getContent();
        $payload = ['comments' => 'Fix the save button alignment', 'annotations' => [$stroke], 'revision' => 0, 'annotated_image' => 'data:image/png;base64,'.base64_encode($png)];
        $this->patchJson(route('images.update', $image), $payload)->assertOk()->assertJsonPath('revision', 1)->assertJsonPath('comments', 'Fix the save button alignment');
        $image->refresh();
        $this->assertSame([$stroke], $image->annotations);
        $this->assertSame($png, Storage::disk('local')->get($image->annotated_path));
        $this->patchJson(route('images.update', $image), $payload + [])->assertConflict();
        $this->assertSame('Fix the save button alignment', $image->fresh()->comments);
        $this->withHeader('Authorization', 'Bearer workspace-test-token')->getJson(route('api.projects.latest', $project))->assertJsonPath('chunk.images.0.annotations.0.tool', 'arrow')->assertJsonPath('chunk.images.0.comments', 'Fix the save button alignment');
        $this->getJson(route('api.images.annotated', $image))->assertDownload('upload-1-annotated.png');
        $invalid = $payload;
        $invalid['revision'] = 1;
        $invalid['annotations'][0]['points'][0]['x'] = 2;
        $this->patchJson(route('images.update', $image), $invalid)->assertUnprocessable();
        $this->assertSame(1, $image->fresh()->feedback_revision);
        $this->patchJson(route('images.update', $image), ['comments' => 'Changed comments with unavailable preview', 'annotations' => [$stroke], 'revision' => 1])->assertOk()->assertJsonPath('revision', 2);
        $this->assertSame($image->annotated_path, $image->fresh()->annotated_path);
        $this->assertSame($png, Storage::disk('local')->get($image->annotated_path));
        $changedStroke = $stroke;
        $changedStroke['color'] = '#000000';
        $this->patchJson(route('images.update', $image), ['comments' => 'Reject changed drawing without raster', 'annotations' => [$changedStroke], 'revision' => 2])->assertUnprocessable();
        $this->assertSame('Changed comments with unavailable preview', $image->fresh()->comments);
        $this->patchJson(route('images.update', $image), ['comments' => 'Keep text only', 'annotations' => [], 'revision' => 2])->assertOk()->assertJsonPath('annotated_image_url', null);
        Storage::disk('local')->assertMissing($image->annotated_path);
        Queue::assertPushed(DescribeUploadImage::class, 1);
    }

    public function test_abandoned_draft_cleanup_preserves_completed_chunks_and_never_reuses_names(): void
    {
        $this->prepare();
        $project = Project::factory()->create(['slug' => 'abandoned-draft-cleanup']);
        $complete = $this->upload($project)->assertCreated();
        $published = UploadChunk::where('uuid', $complete->json('id'))->sole();
        $draft = $this->postJson(route('chunks.start', $project), ['image_count' => 2])->assertCreated();
        $chunk = UploadChunk::where('uuid', $draft->json('id'))->sole();
        $this->postJson(route('chunks.append', $chunk), ['file' => UploadedFile::fake()->image('abandoned.png')])->assertCreated();
        $path = $chunk->images()->sole()->path;
        Storage::disk('local')->assertExists($path);
        Queue::assertPushed(DiscardIncompleteUpload::class, function ($job) use ($chunk): bool {
            return $job->chunkId === $chunk->id && $job->delay->diffInHours(now(), true) >= 23;
        });
        (new DiscardIncompleteUpload($chunk->id))->handle(app(WorkspaceDeletion::class));
        Storage::disk('local')->assertExists($path);
        $this->travel(1)->day();
        (new DiscardIncompleteUpload($chunk->id))->handle(app(WorkspaceDeletion::class));
        (new DiscardIncompleteUpload($published->id))->handle(app(WorkspaceDeletion::class));
        $this->assertDatabaseMissing('upload_chunks', ['id' => $chunk->id]);
        Storage::disk('local')->assertMissing($path);
        $this->assertSame('upload-1.png', $published->images()->sole()->name);
        Storage::disk('local')->assertExists($published->images()->sole()->path);
        $this->upload($project)->assertCreated()->assertJsonPath('images.0.name', 'upload-3.png');
    }

    public function test_moving_an_image_preserves_its_feedback_and_original_chunk_without_exposing_other_projects(): void
    {
        $this->prepare();
        $source = Project::factory()->create(['slug' => 'move-source']);
        $target = Project::factory()->create(['slug' => 'move-target']);
        $upload = $this->upload($source, 2)->assertCreated();
        $image = UploadImage::where('uuid', $upload->json('images.0.id'))->sole();
        $image->update(['comments' => 'Preserve this feedback']);
        $chunkId = $image->chunk_id;
        $this->patchJson(route('images.move', $image), ['project_id' => 99999])->assertUnprocessable();
        $this->assertSame($source->id, $image->fresh()->project_id);
        $this->patchJson(route('images.move', $image), ['project_id' => $target->id])->assertOk();
        $image->refresh();
        $this->assertSame($target->id, $image->project_id);
        $this->assertSame($chunkId, $image->chunk_id);
        $this->assertSame('Preserve this feedback', $image->comments);
        $this->withHeader('Authorization', 'Bearer workspace-test-token')->getJson(route('api.projects.latest', $target))->assertJsonPath('chunk.id', $upload->json('id'))->assertJsonCount(1, 'chunk.images');
        $this->getJson(route('api.projects.latest', $source))->assertJsonCount(1, 'chunk.images')->assertJsonPath('chunk.images.0.id', $upload->json('images.1.id'));
        Queue::assertPushed(DescribeUploadImage::class, 2);
    }

    public function test_deleting_a_project_removes_its_files_and_feedback_but_preserves_moved_images(): void
    {
        $this->prepare();
        $source = Project::factory()->create(['slug' => 'delete-source']);
        $target = Project::factory()->create(['slug' => 'delete-target']);
        $upload = $this->upload($source, 2)->assertCreated();
        $first = UploadImage::where('uuid', $upload->json('images.0.id'))->sole();
        $moved = UploadImage::where('uuid', $upload->json('images.1.id'))->sole();
        $moved->update(['project_id' => $target->id]);
        Storage::disk('local')->assertExists($first->path);
        Storage::disk('local')->assertExists($moved->path);
        $this->delete(route('projects.destroy', $source))->assertRedirect('/');
        $this->assertDatabaseMissing('projects', ['id' => $source->id]);
        $this->assertDatabaseMissing('upload_images', ['id' => $first->id]);
        Storage::disk('local')->assertMissing($first->path);
        Storage::disk('local')->assertExists($moved->path);
        $this->assertDatabaseHas('upload_images', ['id' => $moved->id, 'project_id' => $target->id]);
        $this->assertDatabaseHas('upload_chunks', ['id' => $first->chunk_id]);
        $this->delete(route('projects.destroy', $target))->assertRedirect('/');
        $this->assertDatabaseMissing('upload_chunks', ['id' => $first->chunk_id]);
        Storage::disk('local')->assertMissing($moved->path);
        Queue::assertPushed(DescribeUploadImage::class, 2);
    }

    public function test_fifty_images_publish_as_one_chunk_only_after_the_group_is_complete(): void
    {
        $this->prepare();
        $project = Project::factory()->create(['slug' => 'fifty-images']);
        $draft = $this->postJson(route('chunks.start', $project), ['image_count' => 50])->assertCreated();
        $chunk = UploadChunk::where('uuid', $draft->json('id'))->sole();
        $this->withHeader('Authorization', 'Bearer workspace-test-token')->getJson(route('api.projects.latest', $project))->assertJsonPath('chunk', null);
        $this->postJson(route('chunks.complete', $chunk))->assertConflict();
        for ($i = 0; $i < 50; $i++) {
            $this->postJson(route('chunks.append', $chunk), ['file' => UploadedFile::fake()->image("batch-{$i}.png", 10, 10)])->assertCreated()->assertJsonPath('received_images', $i + 1);
        }
        Queue::assertNotPushed(DescribeUploadImage::class);
        $this->getJson(route('api.projects.latest', $project))->assertJsonPath('chunk', null);
        $this->postJson(route('chunks.complete', $chunk))->assertOk()->assertJsonCount(50, 'images');
        $this->getJson(route('api.projects.latest', $project))->assertJsonPath('chunk.id', $chunk->uuid)->assertJsonCount(50, 'chunk.images');
        $this->postJson(route('chunks.complete', $chunk))->assertConflict();
        $this->deleteJson(route('chunks.cancel', $chunk))->assertConflict();
        $this->postJson(route('chunks.append', $chunk), ['file' => UploadedFile::fake()->image('extra.png')])->assertConflict();
        $this->assertSame(50, $chunk->images()->count());
        Queue::assertPushed(DescribeUploadImage::class, 50);
    }

    public function test_cancelled_drafts_remove_their_files_without_changing_the_latest_published_chunk(): void
    {
        $this->prepare();
        $project = Project::factory()->create(['slug' => 'cancel-draft']);
        $published = $this->upload($project)->assertCreated();
        $draft = $this->postJson(route('chunks.start', $project), ['image_count' => 2])->assertCreated();
        $chunk = UploadChunk::where('uuid', $draft->json('id'))->sole();
        $this->postJson(route('chunks.append', $chunk), ['file' => UploadedFile::fake()->image('partial.png')])->assertCreated();
        $image = $chunk->images()->sole();
        Storage::disk('local')->assertExists($image->path);
        $this->withHeader('Authorization', 'Bearer workspace-test-token')->getJson(route('api.projects.latest', $project))->assertJsonPath('chunk.id', $published->json('id'));
        $this->deleteJson(route('chunks.cancel', $chunk))->assertOk();
        $this->assertDatabaseMissing('upload_chunks', ['id' => $chunk->id]);
        $this->assertDatabaseMissing('upload_images', ['id' => $image->id]);
        Storage::disk('local')->assertMissing($image->path);
        $this->getJson(route('api.projects.latest', $project))->assertJsonPath('chunk.id', $published->json('id'));
        Queue::assertPushed(DescribeUploadImage::class, 1);
    }

    public function test_failed_project_deletion_restores_original_files_and_records(): void
    {
        $this->prepare();
        $project = Project::factory()->create(['slug' => 'deletion-rollback']);
        $response = $this->upload($project)->assertCreated();
        $image = UploadImage::where('uuid', $response->json('images.0.id'))->sole();
        $original = Storage::disk('local')->get($image->path);
        Project::deleting(function (Project $candidate) use ($project): void {
            if ($candidate->id === $project->id && $candidate->slug === 'deletion-rollback') {
                throw new \RuntimeException('Simulated database failure');
            }
        });
        $this->delete(route('projects.destroy', $project))->assertStatus(500);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('upload_images', ['id' => $image->id]);
        $this->assertSame($original, Storage::disk('local')->get($image->path));
        $this->assertSame([], Storage::disk('local')->allFiles('deleting'));
        Queue::assertPushed(DescribeUploadImage::class, 1);
    }

    public function test_vision_descriptions_are_saved_and_provider_errors_do_not_remove_images(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $image = UploadImage::factory()->create();
        $png = UploadedFile::fake()->image('vision.png', 20, 20)->getContent();
        Storage::disk('local')->put($image->path, $png);
        $this->assertNull($image->description);
        Http::fake(['https://api.openai.com/v1/chat/completions' => Http::sequence()->push([
            'choices' => [['message' => ['content' => 'A receipt screen with a blue Save button.']]],
        ])->push(['error' => ['message' => 'SECRET provider details']], 500)]);
        (new DescribeUploadImage($image->id))->handle(app(VisionDescription::class));
        $image->refresh();
        $this->assertSame('ready', $image->description_status);
        $this->assertSame('A receipt screen with a blue Save button.', $image->description);
        Http::assertSent(fn ($request) => $request['model'] === 'gpt-4.1-nano' && str_starts_with($request['messages'][0]['content'][1]['image_url']['url'], 'data:image/jpeg;base64,'));
        $image->update(['description_status' => 'pending']);
        (new DescribeUploadImage($image->id))->handle(app(VisionDescription::class));
        $image->refresh();
        $this->assertSame('failed', $image->description_status);
        $this->assertSame('The vision provider could not describe this image. Try again later.', $image->description_error);
        Storage::disk('local')->assertExists($image->path);
        $this->assertStringNotContainsString('SECRET', $image->description_error);
    }
}

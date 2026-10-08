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
use App\UploadinyTokenAbility;
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
        config(['services.uploadiny.vision_key' => 'test-vision-key']);
    }

    private function prepare(): User
    {
        Storage::fake('local');
        Queue::fake([DescribeUploadImage::class, DiscardIncompleteUpload::class]);
        $user = User::factory()->create(['email' => 'workspace-owner@example.test']);
        $this->actingAs($user);

        return $user;
    }

    private function agentToken(User $user): string
    {
        return $user->createToken('workspace-agent', UploadinyTokenAbility::agent())->plainTextToken;
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
        $agent = $this->agentToken(User::factory()->create());
        $this->withToken($agent)->getJson(route('api.images.download', $image))->assertDownload($image->name);
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

    public function test_reloading_with_an_open_image_renders_the_editor_directly_without_the_gallery(): void
    {
        $this->prepare();
        $project = Project::factory()->create(['slug' => 'reload-project']);
        $other = Project::factory()->create(['slug' => 'other-reload']);
        $chunk = UploadChunk::factory()->create(['status' => 'complete', 'completed_at' => now()]);
        $first = UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $chunk->id, 'name' => 'upload-1.png']);
        $second = UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $chunk->id, 'name' => 'upload-2.png']);
        $recording = UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $chunk->id, 'name' => 'upload-3.mp4', 'mime_type' => 'video/mp4']);
        $foreign = UploadImage::factory()->create(['project_id' => $other->id, 'name' => 'foreign.png']);
        $draft = UploadChunk::factory()->create(['status' => 'uploading', 'upload_project_id' => $project->id, 'expected_images' => 2]);
        $unpublished = UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $draft->id, 'name' => 'draft.png']);

        $this->get('/projects/reload-project?image='.$second->uuid)->assertOk()
            ->assertSee('<section id="gallery" tabindex="-1" aria-label="Project gallery"  hidden >', false)
            ->assertDontSee('id="editor" class="editor"  hidden', false)
            ->assertSee('<h2 id="editor-name" tabindex="-1">upload-2.png</h2>', false)
            ->assertSee('2 of 3')
            ->assertSee('id="chunk-list"', false)
            ->assertSee('id="add-callout">Add annotation', false)
            ->assertSee('id="select-callout">Select next annotation', false)
            ->assertSee('id="video-workspace"  hidden', false);
        $this->get('/projects/reload-project?image='.$recording->uuid)->assertOk()
            ->assertSee('id="drawing-workspace"  hidden', false)
            ->assertDontSee('id="video-workspace"  hidden', false);

        foreach (['00000000-0000-4000-8000-000000000001', $foreign->uuid, $unpublished->uuid] as $uuid) {
            $this->get('/projects/reload-project?image='.$uuid)->assertOk()
                ->assertDontSee('<section id="gallery" tabindex="-1" aria-label="Project gallery"  hidden >', false)
                ->assertSee('id="editor" class="editor"  hidden', false)
                ->assertSee('<h2 id="editor-name" tabindex="-1"></h2>', false);
        }
        $this->assertNotNull($first);
    }

    public function test_an_empty_workspace_invites_creating_the_first_project_until_one_exists(): void
    {
        $this->prepare();
        $this->get('/')->assertOk()->assertSee('No projects yet')->assertSee('Create your first project')->assertDontSee('Pick a project, drop in screenshots');
        Project::factory()->create(['name' => 'Taxiny', 'slug' => 'taxiny']);
        $this->get('/')->assertOk()->assertDontSee('No projects yet')->assertSee('Pick a project, drop in screenshots');
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
        $user = $this->prepare();
        $project = Project::factory()->create(['slug' => 'chunk-taxiny']);
        $other = Project::factory()->create(['slug' => 'chunk-other']);
        $this->withToken($this->agentToken($user))->getJson(route('api.projects.latest', $project))->assertJsonPath('chunk', null);
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

    public function test_the_browser_last_chunk_check_requires_a_session_and_matches_the_phone_shape(): void
    {
        $user = $this->prepare();
        $project = Project::factory()->create(['slug' => 'last-taxiny']);
        $this->app['auth']->forgetGuards();
        $this->getJson(route('projects.last-chunk', $project))->assertUnauthorized();
        $this->get(route('projects.last-chunk', $project))->assertRedirect(route('login'));
        $this->actingAs($user);
        $this->getJson(route('projects.last-chunk', $project))->assertOk()->assertExactJson(['chunk' => null]);
        $this->upload($project, 2)->assertCreated();
        $web = $this->getJson(route('projects.last-chunk', $project))->assertOk()->assertJsonStructure(['chunk' => ['id', 'completed_at', 'file_count']])->assertJsonPath('chunk.file_count', 2);
        $this->withToken($user->createToken('phone', UploadinyTokenAbility::phone())->plainTextToken)->getJson(route('api.chunks.last', $project))->assertOk()->assertExactJson($web->json());
    }

    public function test_the_project_page_renders_the_latest_chunk_state_the_browser_compares_against(): void
    {
        $user = $this->prepare();
        $project = Project::factory()->create(['slug' => 'state-taxiny']);
        $this->get(route('projects.show', $project))->assertOk()
            ->assertSee('data-latest="" data-latest-completed="" data-latest-count=""', false)
            ->assertSee(str_replace('/', '\/', route('projects.last-chunk', $project)), false);
        $this->get(route('projects.index'))->assertOk()->assertSee('"last_chunk_url":null', false);

        $first = $this->upload($project, 2)->assertCreated();
        $state = $this->getJson(route('projects.last-chunk', $project))->json('chunk');
        $this->assertSame($first->json('id'), $state['id']);
        $this->get(route('projects.show', $project))->assertOk()->assertSee(sprintf('data-latest="%s" data-latest-completed="%s" data-latest-count="2"', $state['id'], $state['completed_at']), false)->assertSee('id="chunk-list"', false)
            ->assertSee('id="add-callout">Add annotation', false)
            ->assertSee('id="select-callout">Select next annotation', false);

        $this->travel(5)->minutes();
        $draft = $this->postJson(route('chunks.start', $project), ['image_count' => 1, 'append_to' => $first->json('id')])->assertCreated();
        $this->postJson(route('chunks.append', $draft->json('id')), ['file' => UploadedFile::fake()->image('later.png', 30, 20)])->assertCreated();
        $this->postJson(route('chunks.complete', $draft->json('id')))->assertOk();
        $merged = $this->getJson(route('projects.last-chunk', $project))->json('chunk');
        $this->assertSame($state['id'], $merged['id']);
        $this->assertSame(3, $merged['file_count']);
        $this->assertNotSame($state['completed_at'], $merged['completed_at']);
        $this->get(route('projects.show', $project))->assertOk()->assertSee(sprintf('data-latest="%s" data-latest-completed="%s" data-latest-count="3"', $merged['id'], $merged['completed_at']), false);
    }

    public function test_the_add_to_last_upload_switch_shows_only_when_the_project_has_an_upload(): void
    {
        $this->prepare();
        $project = Project::factory()->create(['slug' => 'switch-taxiny']);
        $empty = $this->get(route('projects.show', $project))->assertOk()
            ->assertSee('id="append-to-last"', false)->assertSee('Add to the last upload');
        $this->assertMatchesRegularExpression('/id="append-row"\s+hidden/', $empty->getContent());

        $first = $this->upload($project, 2)->assertCreated();
        $page = $this->get(route('projects.show', $project))->assertOk()
            ->assertSee('id="append-target"', false)->assertSee('Last upload: 2 files')
            ->assertSee(sprintf('data-latest="%s"', $first->json('id')), false);
        $this->assertDoesNotMatchRegularExpression('/id="append-row"\s+hidden/', $page->getContent());
        $this->get(route('projects.index'))->assertOk()->assertDontSee('id="append-to-last"', false);
    }

    public function test_the_browser_session_can_add_files_to_the_last_upload(): void
    {
        $this->prepare();
        $project = Project::factory()->create(['slug' => 'browser-append']);
        $older = $this->upload($project, 2)->assertCreated()->json('id');
        $this->travel(5)->minutes();
        $newer = $this->upload($project, 1)->assertCreated()->json('id');
        $this->travel(5)->minutes();

        $draft = $this->postJson(route('chunks.start', $project), ['image_count' => 2, 'append_to' => $older])->assertCreated()->json('id');
        foreach (['a', 'b'] as $name) {
            $this->postJson(route('chunks.append', $draft), ['file' => UploadedFile::fake()->image("{$name}.png", 30, 20)])->assertCreated();
        }
        $this->postJson(route('chunks.complete', $draft))->assertOk()->assertJsonPath('id', $older);

        $this->assertSame(4, UploadChunk::query()->where('uuid', $older)->firstOrFail()->images()->count());
        $this->assertSame(1, UploadChunk::query()->where('uuid', $newer)->firstOrFail()->images()->count());
        $this->assertSame(0, UploadChunk::query()->where('uuid', $draft)->count());
        $this->getJson(route('projects.last-chunk', $project))->assertJsonPath('chunk.id', $older)->assertJsonPath('chunk.file_count', 4);
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
        $user = $this->prepare();
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
        $this->withToken($this->agentToken($user))->getJson(route('api.projects.latest', $project))->assertJsonPath('chunk.images.0.marks.0.tool', 'arrow')->assertJsonPath('chunk.images.0.mark_count', 1)->assertJsonMissingPath('chunk.images.0.annotations')->assertJsonPath('chunk.images.0.comments', 'Fix the save button alignment');
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

    public function test_line_and_ellipse_feedback_preserves_tool_color_and_thickness(): void
    {
        $user = $this->prepare();
        $project = Project::factory()->create(['slug' => 'shape-feedback']);
        $uploaded = $this->upload($project)->assertCreated()->json('images.0');
        $image = UploadImage::where('uuid', $uploaded['id'])->sole();
        $png = UploadedFile::fake()->image('shapes.png', 30, 20)->getContent();
        $annotations = [
            ['tool' => 'line', 'color' => '#3b82f6', 'width' => 0.0001, 'points' => [['x' => 0.8, 'y' => 0.9], ['x' => 0.2, 'y' => 0.1]]],
            ['tool' => 'ellipse', 'color' => '#16a34a', 'width' => 0.1, 'points' => [['x' => 0.1, 'y' => 0.2], ['x' => 0.9, 'y' => 0.8]]],
        ];
        $this->assertSame([], $image->annotations);

        $this->patchJson(route('images.update', $image), ['comments' => 'Two marked areas', 'annotations' => $annotations, 'revision' => 0, 'annotated_image' => 'data:image/png;base64,'.base64_encode($png)])->assertOk();
        $this->assertSame($annotations, $image->fresh()->annotations);
        $this->assertSame($png, Storage::disk('local')->get($image->fresh()->annotated_path));
        $this->withToken($this->agentToken($user))->getJson(route('api.projects.latest', $project))->assertJsonPath('chunk.images.0.marks.0.tool', 'line')->assertJsonPath('chunk.images.0.marks.0.color', '#3b82f6')->assertJsonPath('chunk.images.0.marks.1.tool', 'ellipse')->assertJsonPath('chunk.images.0.marks.1.color', '#16a34a');
    }

    public function test_callout_text_and_both_boxes_are_saved_for_the_agent_and_incomplete_boxes_are_rejected(): void
    {
        $user = $this->prepare();
        $project = Project::factory()->create(['slug' => 'callout-feedback']);
        $uploaded = $this->upload($project)->assertCreated()->json('images.0');
        $image = UploadImage::where('uuid', $uploaded['id'])->sole();
        $png = UploadedFile::fake()->image('callout.png', 30, 20)->getContent();
        $callout = ['tool' => 'callout', 'text' => "Add a checkbox here.\nKeep the link separate.", 'color' => '#16a34a', 'width' => 0.003, 'points' => [['x' => 0.1, 'y' => 0.4], ['x' => 0.8, 'y' => 0.5], ['x' => 0.2, 'y' => 0.1], ['x' => 0.7, 'y' => 0.3]]];
        $payload = ['comments' => '', 'annotations' => [$callout], 'revision' => 0, 'annotated_image' => 'data:image/png;base64,'.base64_encode($png)];
        $this->assertSame([], $image->annotations);

        $invalid = $payload;
        array_pop($invalid['annotations'][0]['points']);
        $this->patchJson(route('images.update', $image), $invalid)->assertUnprocessable()->assertJsonValidationErrors('annotations.0.points.3');
        $this->assertSame([], $image->fresh()->annotations);
        $this->patchJson(route('images.update', $image), $payload)->assertOk();
        $this->assertSame([$callout], $image->fresh()->annotations);
        $this->withToken($this->agentToken($user))->getJson(route('api.projects.latest', $project))->assertJsonPath('chunk.images.0.marks.0.note', $callout['text'])->assertJsonPath('chunk.images.0.marks.0.tool', 'callout');
    }

    public function test_recordings_share_a_chunk_with_images_and_keep_private_playback_and_text_feedback(): void
    {
        $user = $this->prepare();
        $project = Project::factory()->create(['slug' => 'mixed-recording-feedback']);
        $phoneToken = $user->createToken('recording-phone', UploadinyTokenAbility::phone())->plainTextToken;
        $this->withToken($phoneToken);
        $draft = $this->postJson(route('api.chunks.start', $project), ['image_count' => 2])->assertCreated();
        $chunk = UploadChunk::where('uuid', $draft->json('id'))->sole();
        $this->assertSame('uploading', $chunk->status);
        $this->postJson(route('api.chunks.append', $chunk), ['file' => UploadedFile::fake()->image('screen.png')])->assertCreated();
        $this->postJson(route('api.chunks.append', $chunk), ['file' => UploadedFile::fake()->createWithContent('recording.mov', str_repeat('v', 2048))->mimeType('video/quicktime')])->assertCreated();
        $this->postJson(route('api.chunks.complete', $chunk))->assertOk()->assertJsonPath('images.1.media_type', 'video')->assertJsonPath('images.1.description_status', 'not_applicable');
        $recording = $chunk->images()->where('name', 'upload-2.mov')->sole();
        $still = $chunk->images()->where('name', 'upload-1.png')->sole();
        Storage::disk('local')->assertExists($recording->path);
        Queue::assertPushed(DescribeUploadImage::class, fn ($job): bool => $job->imageId === $still->id);
        Queue::assertNotPushed(DescribeUploadImage::class, fn ($job): bool => $job->imageId === $recording->id);
        $this->get(route('projects.show', $project))->assertSee('Screen recording')->assertSee('recording-player', false);
        $this->get(route('images.preview', $recording))->assertOk()->assertHeader('Content-Type', 'video/quicktime')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('images.preview', $recording), ['Range' => 'bytes=0-9'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-9/2048');
        $this->assertSame('', $recording->comments);
        $this->patchJson(route('images.update', $recording), ['comments' => 'At 00:03 the next button stops responding.', 'annotations' => [], 'revision' => 0])->assertOk()->assertJsonPath('revision', 1);
        $this->assertSame('At 00:03 the next button stops responding.', $recording->fresh()->comments);
        $this->assertSame('', $still->fresh()->comments);
        $this->postJson(route('images.describe', $recording))->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken($this->agentToken($user))->getJson(route('api.projects.latest', $project))->assertOk()->assertJsonPath('chunk.images.1.media_type', 'video')->assertJsonPath('chunk.images.1.comments', 'At 00:03 the next button stops responding.');
        $this->getJson(route('api.images.download', $recording))->assertDownload('upload-2.mov');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->actingAs($user->fresh(), 'web');
        $this->postJson(route('chunks.store', $project), ['files' => [UploadedFile::fake()->create('recording.mp4', 1, 'video/mp4')]])->assertCreated()->assertJsonPath('images.0.media_type', 'video');
        $this->postJson(route('chunks.store', $project), ['files' => [UploadedFile::fake()->create('unsafe.html', 1, 'text/html')]])->assertUnprocessable()->assertJsonValidationErrors('files.0');
        $this->assertSame(2, $chunk->images()->count());
    }

    public function test_copying_to_another_chunk_keeps_independent_originals_and_feedback(): void
    {
        $this->prepare();
        $sourceProject = Project::factory()->create(['slug' => 'copy-source-chunk']);
        $targetProject = Project::factory()->create(['slug' => 'copy-target-chunk']);
        $sourceResponse = $this->upload($sourceProject)->assertCreated();
        $targetResponse = $this->upload($targetProject)->assertCreated();
        $source = UploadImage::where('uuid', $sourceResponse->json('images.0.id'))->sole();
        $target = UploadChunk::where('uuid', $targetResponse->json('id'))->sole();
        $raster = UploadedFile::fake()->image('marked.png')->getContent();
        $annotations = [['tool' => 'arrow', 'color' => '#ef4444', 'width' => 0.003, 'points' => [['x' => 0.1, 'y' => 0.2], ['x' => 0.8, 'y' => 0.7]]]];
        Storage::disk('local')->put('annotations/copy-source.png', $raster);
        $source->update(['annotations' => $annotations, 'comments' => 'Keep this note with the image', 'annotated_path' => 'annotations/copy-source.png', 'description_status' => 'ready', 'description' => 'A screenshot']);
        $original = Storage::disk('local')->get($source->path);
        $this->assertSame(1, $target->images()->count());

        $response = $this->postJson(route('images.transfer-chunk', $source), ['action' => 'copy', 'chunk_id' => $target->uuid, 'project_id' => $targetProject->id])->assertOk()->assertJsonPath('image.name', 'upload-3.png');
        $copy = UploadImage::where('uuid', $response->json('image.id'))->sole();
        $this->assertSame($target->id, $copy->chunk_id);
        $this->assertSame($targetProject->id, $copy->project_id);
        $this->assertSame($annotations, $copy->annotations);
        $this->assertSame('Keep this note with the image', $copy->comments);
        $this->assertSame('A screenshot', $copy->description);
        $this->assertNotSame($source->path, $copy->path);
        $this->assertNotSame($source->annotated_path, $copy->annotated_path);
        $this->assertSame($original, Storage::disk('local')->get($copy->path));
        $this->assertSame($raster, Storage::disk('local')->get($copy->annotated_path));
        $this->assertSame($sourceProject->id, $source->fresh()->project_id);
        $this->deleteJson(route('images.destroy', $source))->assertOk();
        Storage::disk('local')->assertExists([$copy->path, $copy->annotated_path]);
        $this->assertSame(2, $target->images()->count());
        $this->get(route('projects.show', $targetProject))->assertSee('has-stack', false)->assertSee('upload-3.png')->assertSee('2 files');
    }

    public function test_failed_chunk_copy_removes_new_files_and_preserves_source_feedback(): void
    {
        $this->prepare();
        $project = Project::factory()->create(['slug' => 'chunk-copy-rollback']);
        $first = $this->upload($project)->assertCreated();
        $second = $this->upload($project)->assertCreated();
        $source = UploadImage::where('uuid', $first->json('images.0.id'))->sole();
        $target = UploadChunk::where('uuid', $second->json('id'))->sole();
        Storage::disk('local')->put('annotations/rollback-source.png', 'marked screenshot');
        $source->update(['comments' => 'Keep the original note', 'annotated_path' => 'annotations/rollback-source.png']);
        $before = Storage::disk('local')->allFiles();
        $original = Storage::disk('local')->get($source->path);
        $this->assertSame(1, $target->images()->count());
        UploadImage::creating(function (UploadImage $candidate) use ($target): void {
            if ($candidate->chunk_id === $target->id && $candidate->name === 'upload-3.png') {
                throw new \RuntimeException('Simulated copy database failure');
            }
        });

        $this->postJson(route('images.transfer-chunk', $source), ['action' => 'copy', 'chunk_id' => $target->uuid, 'project_id' => $project->id])->assertStatus(500);

        $this->assertSame($before, Storage::disk('local')->allFiles());
        $this->assertSame($original, Storage::disk('local')->get($source->path));
        $this->assertSame('marked screenshot', Storage::disk('local')->get($source->annotated_path));
        $this->assertSame('Keep the original note', $source->fresh()->comments);
        $this->assertSame(1, $target->images()->count());
        $this->assertSame('2', Storage::disk('local')->get('.uploadiny-sequence'));
    }

    public function test_a_file_can_be_moved_or_copied_into_a_brand_new_upload_chunk(): void
    {
        $this->prepare();
        $project = Project::factory()->create(['slug' => 'new-chunk-target']);
        $other = Project::factory()->create(['slug' => 'new-chunk-other']);
        $upload = $this->upload($project, 3)->assertCreated();
        [$moved, $copied, $staying] = array_map(static fn (string $id): UploadImage => UploadImage::where('uuid', $id)->sole(), array_column($upload->json('images'), 'id'));
        $moved->update(['comments' => 'Travel with the file']);
        $this->travel(2)->hours();

        $this->postJson(route('images.transfer-chunk', $moved), ['action' => 'move', 'new_chunk' => true, 'project_id' => $project->id])->assertOk();

        $newChunk = $moved->fresh()->chunk;
        $this->assertNotSame($upload->json('id'), $newChunk->uuid);
        $this->assertSame('complete', $newChunk->status);
        $this->assertSame($project->id, $newChunk->upload_project_id);
        $this->assertSame('Travel with the file', $moved->fresh()->comments);
        $this->assertSame([$moved->id], $newChunk->images()->pluck('id')->all());
        $this->assertSame([$copied->id, $staying->id], UploadChunk::where('uuid', $upload->json('id'))->sole()->images()->orderBy('id')->pluck('id')->all());
        $this->getJson(route('chunks.index'))->assertOk()->assertJsonPath('destinations.0.chunk_id', $newChunk->uuid)
            ->assertJsonPath('destinations.0.uploaded_at', $newChunk->completed_at->toIso8601String());

        $response = $this->postJson(route('images.transfer-chunk', $copied), ['action' => 'copy', 'new_chunk' => true, 'project_id' => $other->id])->assertOk();
        $copy = UploadImage::where('uuid', $response->json('image.id'))->sole();
        $this->assertNotContains($copy->chunk_id, [$newChunk->id, $copied->chunk_id]);
        $this->assertSame($other->id, $copy->project_id);
        $this->assertSame($upload->json('id'), $copied->fresh()->chunk->uuid);
        $this->assertSame(3, UploadChunk::where('status', 'complete')->count());

        $this->postJson(route('images.transfer-chunk', $staying), ['action' => 'move', 'project_id' => $project->id])->assertUnprocessable()->assertJsonValidationErrors('chunk_id');
        $this->postJson(route('images.transfer-chunk', $staying), ['action' => 'move', 'new_chunk' => true, 'project_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->assertSame(3, UploadChunk::count());
    }

    public function test_moving_to_a_chunk_preserves_file_feedback_and_rejects_draft_destinations(): void
    {
        $user = $this->prepare();
        $project = Project::factory()->create(['slug' => 'move-between-chunks']);
        $first = $this->upload($project)->assertCreated();
        $second = $this->upload($project)->assertCreated();
        $image = UploadImage::where('uuid', $first->json('images.0.id'))->sole();
        $image->update(['comments' => 'Preserve this feedback']);
        $before = $image->path;
        $draft = UploadChunk::create(['status' => 'uploading', 'upload_project_id' => $project->id, 'expected_images' => 1]);
        $this->postJson(route('images.transfer-chunk', $image), ['action' => 'move', 'chunk_id' => $draft->uuid, 'project_id' => $project->id])->assertUnprocessable();
        $this->assertSame($first->json('id'), $image->fresh()->chunk->uuid);
        $this->postJson(route('images.transfer-chunk', $image), ['action' => 'move', 'chunk_id' => $second->json('id'), 'project_id' => $project->id])->assertOk();
        $this->assertSame($second->json('id'), $image->fresh()->chunk->uuid);
        $this->assertSame('Preserve this feedback', $image->fresh()->comments);
        $this->assertSame($before, $image->fresh()->path);
        Storage::disk('local')->assertExists($before);
        $this->getJson(route('chunks.index'))->assertOk()->assertJsonCount(1, 'destinations')->assertJsonPath('destinations.0.file_count', 2);
        $this->get(route('projects.show', $project))->assertSee('has-stack', false)->assertSee('upload-2.png')->assertDontSee('upload-1.png');
        $phone = $user->createToken('transfer-phone-rejection', UploadinyTokenAbility::phone())->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken($phone)->postJson(route('images.transfer-chunk', $image), ['action' => 'copy', 'chunk_id' => $second->json('id'), 'project_id' => $project->id])->assertUnauthorized();
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
        $user = $this->prepare();
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
        $this->withToken($this->agentToken($user))->getJson(route('api.projects.latest', $target))->assertJsonPath('chunk.id', $upload->json('id'))->assertJsonCount(1, 'chunk.images');
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
        $user = $this->prepare();
        $project = Project::factory()->create(['slug' => 'fifty-images']);
        $draft = $this->postJson(route('chunks.start', $project), ['image_count' => 50])->assertCreated();
        $chunk = UploadChunk::where('uuid', $draft->json('id'))->sole();
        $this->withToken($this->agentToken($user))->getJson(route('api.projects.latest', $project))->assertJsonPath('chunk', null);
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
        $user = $this->prepare();
        $project = Project::factory()->create(['slug' => 'cancel-draft']);
        $published = $this->upload($project)->assertCreated();
        $draft = $this->postJson(route('chunks.start', $project), ['image_count' => 2])->assertCreated();
        $chunk = UploadChunk::where('uuid', $draft->json('id'))->sole();
        $this->postJson(route('chunks.append', $chunk), ['file' => UploadedFile::fake()->image('partial.png')])->assertCreated();
        $image = $chunk->images()->sole();
        Storage::disk('local')->assertExists($image->path);
        $this->withToken($this->agentToken($user))->getJson(route('api.projects.latest', $project))->assertJsonPath('chunk.id', $published->json('id'));
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

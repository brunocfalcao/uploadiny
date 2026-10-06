<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DescribeUploadImage;
use App\Jobs\DiscardIncompleteUpload;
use App\Project;
use App\UploadChunk;
use App\UploadImage;
use App\UploadinyTokenAbility;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageDuplicateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Project, UploadImage} */
    private function annotatedSource(): array
    {
        Storage::fake('local');
        Queue::fake([DescribeUploadImage::class, DiscardIncompleteUpload::class]);
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->create(['slug' => 'duplicate-project']);
        $response = $this->postJson(route('chunks.store', $project), ['files' => [UploadedFile::fake()->image('a.png', 30, 20), UploadedFile::fake()->image('b.png', 30, 20)]])->assertCreated();
        $source = UploadImage::where('uuid', $response->json('images.0.id'))->sole();
        Storage::disk('local')->put('annotations/dup-source.png', 'marked');
        $source->update([
            'annotations' => [['tool' => 'arrow', 'color' => '#ef4444', 'width' => 0.003, 'points' => [['x' => 0.1, 'y' => 0.2], ['x' => 0.8, 'y' => 0.7]]]],
            'comments' => 'Fix this', 'annotated_path' => 'annotations/dup-source.png', 'feedback_revision' => 4, 'feedback_updated_at' => now(),
            'description_status' => 'ready', 'description' => 'A screenshot', 'description_model' => 'vision-x',
        ]);
        Queue::fake([DescribeUploadImage::class, DiscardIncompleteUpload::class]);

        return [$project, $source->fresh()];
    }

    public function test_duplicate_copies_only_the_original_as_the_last_file_of_the_same_chunk(): void
    {
        [$project, $source] = $this->annotatedSource();
        $before = $source->getAttributes();
        $bytes = Storage::disk('local')->get($source->path);

        $response = $this->postJson(route('images.duplicate', $source))->assertCreated();
        $copy = UploadImage::where('uuid', $response->json('id'))->sole();

        $this->assertSame($source->chunk_id, $copy->chunk_id);
        $this->assertSame($project->id, $copy->project_id);
        $this->assertSame($copy->id, $source->chunk->images()->orderByDesc('id')->value('id'));
        $this->assertSame(3, $source->chunk->images()->count());
        $this->assertSame($bytes, Storage::disk('local')->get($copy->path));
        $this->assertNotSame($source->path, $copy->path);
        $this->assertNotSame($source->uuid, $copy->uuid);
        $this->assertSame('upload-3.png', $copy->name);
        $this->assertSame([], $copy->annotations);
        $this->assertSame('', $copy->comments);
        $this->assertNull($copy->annotated_path);
        $this->assertSame(0, $copy->feedback_revision);
        $this->assertNull($copy->feedback_updated_at);
        $this->assertSame(['ready', 'A screenshot', 'vision-x'], [$copy->description_status, $copy->description, $copy->description_model]);
        $response->assertJsonPath('preview_url', route('images.preview', $copy));
        Queue::assertNothingPushed();
        $this->assertSame($before, $source->fresh()->getAttributes());
        Storage::disk('local')->assertExists([$source->path, 'annotations/dup-source.png']);
    }

    public function test_pending_source_description_is_requested_for_the_copy(): void
    {
        [, $source] = $this->annotatedSource();
        $source->update(['description_status' => 'pending', 'description' => null]);

        $copy = UploadImage::where('uuid', $this->postJson(route('images.duplicate', $source))->assertCreated()->json('id'))->sole();

        Queue::assertPushed(DescribeUploadImage::class, fn (DescribeUploadImage $job): bool => $job->imageId === $copy->id);
    }

    public function test_duplicate_appears_in_agent_feedback_without_marks(): void
    {
        [$project, $source] = $this->annotatedSource();
        $copyId = $this->postJson(route('images.duplicate', $source))->assertCreated()->json('id');
        $token = User::factory()->create()->createToken('agent', UploadinyTokenAbility::agent())->plainTextToken;
        $this->app['auth']->forgetGuards();

        $images = $this->withToken($token)->getJson(route('api.feedback.latest', ['project' => $project->canonical]))->assertOk()->json('chunk.images');

        $this->assertCount(3, $images);
        $this->assertSame($copyId, $images[2]['id']);
        $this->assertSame(0, $images[2]['mark_count']);
        $this->assertSame(1, $images[0]['mark_count']);
    }

    public function test_recordings_and_incomplete_chunks_are_refused(): void
    {
        [, $source] = $this->annotatedSource();
        $video = UploadImage::factory()->create(['project_id' => $source->project_id, 'chunk_id' => $source->chunk_id, 'mime_type' => 'video/mp4', 'path' => 'images/v.mp4', 'description_status' => 'not_applicable']);
        Storage::disk('local')->put('images/v.mp4', 'video');
        $this->postJson(route('images.duplicate', $video))->assertUnprocessable();
        $source->chunk->update(['status' => 'uploading']);
        $this->postJson(route('images.duplicate', $source))->assertStatus(409);
        $this->assertSame(3, UploadImage::count());
    }

    public function test_missing_original_file_returns_404_and_creates_nothing(): void
    {
        [, $source] = $this->annotatedSource();
        Storage::disk('local')->delete($source->path);
        $files = Storage::disk('local')->allFiles();

        $this->postJson(route('images.duplicate', $source))->assertNotFound();

        $this->assertSame(2, UploadImage::count());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame(1, UploadChunk::count());
    }

    public function test_guests_cannot_duplicate(): void
    {
        [, $source] = $this->annotatedSource();
        $this->app['auth']->forgetGuards();
        $this->post(route('images.duplicate', $source))->assertRedirect(route('login'));
        $this->assertSame(2, UploadImage::count());
    }
}

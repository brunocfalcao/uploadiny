<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Project;
use App\UploadChunk;
use App\UploadImage;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProjectChunkDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $prefix): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['email' => $prefix.'@example.test']));
    }

    private function image(Project $project, UploadChunk $chunk, string $prefix): UploadImage
    {
        $image = UploadImage::factory()->create([
            'project_id' => $project->id, 'chunk_id' => $chunk->id,
            'path' => $prefix.'/original.png', 'annotated_path' => $prefix.'/marked.png',
            'comments' => $prefix.' feedback', 'annotations' => [['type' => 'rectangle']],
        ]);
        Storage::disk('local')->put($image->path, $prefix.' original');
        Storage::disk('local')->put($image->annotated_path, $prefix.' drawing');

        return $image;
    }

    #[TestWith([1])]
    #[TestWith([25])]
    public function test_clears_all_published_chunks_and_drafts_and_keeps_the_project(int $count): void
    {
        $prefix = 'clear-'.$count;
        $this->signIn($prefix);
        $project = Project::factory()->create(['slug' => $prefix]);
        $before = $project->fresh()->getAttributes();
        $images = collect();
        $chunks = UploadChunk::factory()->count($count)->create(['upload_project_id' => $project->id, 'status' => 'complete']);
        foreach ($chunks as $index => $chunk) {
            $images->push($this->image($project, $chunk, $prefix.'/'.$index));
        }
        $draft = UploadChunk::factory()->create(['upload_project_id' => $project->id, 'status' => 'uploading']);
        $images->push($this->image($project, $draft, $prefix.'/draft'));
        $emptyDraft = UploadChunk::factory()->create(['upload_project_id' => $project->id, 'status' => 'uploading']);
        $this->assertSame($count + 1, $project->images()->count());
        $this->get(route('projects.show', $project))->assertOk()->assertSee('Delete all Chunks')->assertSee(route('projects.chunks.destroy', $project), false);

        $this->delete(route('projects.chunks.destroy', $project))->assertRedirect(route('projects.show', $project))
            ->assertSessionHas('status', 'All chunks and their files, comments, and annotations deleted.');

        $this->assertSame($before, $project->fresh()->getAttributes());
        $this->assertSame(0, $project->images()->count());
        foreach ($images as $image) {
            $this->assertDatabaseMissing('upload_images', ['id' => $image->id]);
            Storage::disk('local')->assertMissing([$image->path, $image->annotated_path]);
        }
        foreach ($chunks->push($draft, $emptyDraft) as $chunk) {
            $this->assertDatabaseMissing('upload_chunks', ['id' => $chunk->id]);
        }
        $this->get(route('projects.show', $project))->assertOk()->assertSee('Your first feedback starts here.');
    }

    public function test_clears_current_assignments_and_preserves_other_projects_and_shared_chunks(): void
    {
        $this->signIn('clear-shared');
        $project = Project::factory()->create(['slug' => 'clear-shared-source']);
        $other = Project::factory()->create(['slug' => 'clear-shared-target']);
        $otherBefore = $other->fresh()->getAttributes();
        $shared = UploadChunk::factory()->create(['upload_project_id' => $project->id, 'status' => 'complete']);
        $removed = $this->image($project, $shared, 'clear-shared/removed');
        $moved = $this->image($other, $shared, 'clear-shared/moved');
        $otherChunk = UploadChunk::factory()->create(['upload_project_id' => $other->id, 'status' => 'complete']);
        $untouched = $this->image($other, $otherChunk, 'clear-shared/untouched');
        $legacyChunk = UploadChunk::factory()->create(['upload_project_id' => null, 'status' => 'complete']);
        $incoming = $this->image($project, $legacyChunk, 'clear-shared/incoming');
        $this->assertSame(2, $project->images()->count());
        $movedBefore = $moved->fresh()->getAttributes();
        $untouchedBefore = $untouched->fresh()->getAttributes();

        $this->delete(route('projects.chunks.destroy', $project))->assertRedirect(route('projects.show', $project));

        $this->assertSame(0, $project->images()->count());
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
        $this->assertSame($movedBefore, $moved->fresh()->getAttributes());
        $this->assertSame($untouchedBefore, $untouched->fresh()->getAttributes());
        $this->assertDatabaseHas('upload_chunks', ['id' => $shared->id]);
        $this->assertDatabaseHas('upload_chunks', ['id' => $otherChunk->id]);
        $this->assertDatabaseMissing('upload_chunks', ['id' => $legacyChunk->id]);
        Storage::disk('local')->assertMissing([$removed->path, $removed->annotated_path, $incoming->path, $incoming->annotated_path]);
        $this->assertSame('clear-shared/moved original', Storage::disk('local')->get($moved->path));
        $this->assertSame('clear-shared/moved drawing', Storage::disk('local')->get($moved->annotated_path));
        $this->assertSame('clear-shared/untouched original', Storage::disk('local')->get($untouched->path));
        $this->assertSame('clear-shared/untouched drawing', Storage::disk('local')->get($untouched->annotated_path));
    }

    public function test_clearing_an_empty_project_repeatedly_keeps_its_identity(): void
    {
        $this->signIn('clear-empty');
        $project = Project::factory()->create(['slug' => 'clear-empty']);
        $before = $project->fresh()->getAttributes();
        $this->assertSame(0, $project->images()->count());

        $this->delete(route('projects.chunks.destroy', $project))->assertRedirect(route('projects.show', $project));
        $this->delete(route('projects.chunks.destroy', $project))->assertRedirect(route('projects.show', $project));

        $this->assertSame($before, $project->fresh()->getAttributes());
        $this->assertSame(0, $project->images()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('deleting'));
    }

    public function test_guests_and_requests_without_csrf_cannot_clear_chunks(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create(['slug' => 'clear-protected']);
        $chunk = UploadChunk::factory()->create(['upload_project_id' => $project->id, 'status' => 'complete']);
        $image = $this->image($project, $chunk, 'clear-protected');
        $before = $image->fresh()->getAttributes();
        $this->delete(route('projects.chunks.destroy', $project))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['email' => 'clear-protected@example.test']));
        $environment = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $this->delete(route('projects.chunks.destroy', $project))->assertStatus(419);
        } finally {
            $this->app['env'] = $environment;
        }
        $this->assertSame($before, $image->fresh()->getAttributes());
        $this->assertSame('clear-protected original', Storage::disk('local')->get($image->path));
        $this->assertSame('clear-protected drawing', Storage::disk('local')->get($image->annotated_path));
    }

    public function test_failed_file_staging_restores_files_and_keeps_records(): void
    {
        $this->signIn('clear-rollback');
        $project = Project::factory()->create(['slug' => 'clear-rollback']);
        $chunk = UploadChunk::factory()->create(['upload_project_id' => $project->id, 'status' => 'complete']);
        $image = $this->image($project, $chunk, 'clear-rollback');
        $before = $image->fresh()->getAttributes();
        $disk = Storage::disk('local');
        $proxy = \Mockery::mock($disk);
        $proxy->shouldReceive('move')->andReturnUsing(fn (string $from, string $to): bool => $from === $image->annotated_path ? false : $disk->move($from, $to));
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);

        $this->delete(route('projects.chunks.destroy', $project))->assertStatus(500);

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('upload_chunks', ['id' => $chunk->id]);
        $this->assertSame($before, $image->fresh()->getAttributes());
        $this->assertSame('clear-rollback original', $disk->get($image->path));
        $this->assertSame('clear-rollback drawing', $disk->get($image->annotated_path));
        $this->assertSame([], $disk->allFiles('deleting'));
    }
}

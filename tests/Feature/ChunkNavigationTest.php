<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Project;
use App\UploadChunk;
use App\UploadImage;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChunkNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_navigation_requires_a_browser_session_and_returns_404_for_a_deleted_chunk(): void
    {
        $project = Project::factory()->create(['slug' => 'navigation-private']);
        $chunk = UploadChunk::factory()->create(['upload_project_id' => $project->id, 'status' => 'complete']);
        $image = UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $chunk->id]);
        $url = route('projects.chunk-files', [$project, $chunk]);
        $this->getJson($url)->assertUnauthorized();
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['email' => 'navigation-private@example.test']));
        $this->getJson($url)->assertOk()->assertExactJson(['files' => [$image->uuid]]);

        $image->delete();
        $chunk->delete();

        $this->getJson($url)->assertNotFound();
    }

    public function test_navigation_tracks_append_delete_and_move_in_an_older_chunk_without_exposing_feedback(): void
    {
        $this->actingAs(User::factory()->create(['email' => 'navigation-membership@example.test']));
        $project = Project::factory()->create(['slug' => 'navigation-membership']);
        $other = Project::factory()->create(['slug' => 'navigation-membership-other']);
        $chunk = UploadChunk::factory()->create(['upload_project_id' => $project->id, 'status' => 'complete']);
        $first = UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $chunk->id, 'comments' => 'Private feedback stays out of the membership payload.']);
        $moved = UploadImage::factory()->create(['project_id' => $other->id, 'chunk_id' => $chunk->id]);
        $movedBefore = $moved->fresh()->getAttributes();
        $newer = UploadChunk::factory()->create(['upload_project_id' => $project->id, 'status' => 'complete']);
        $newerImage = UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $newer->id]);
        $url = route('projects.chunk-files', [$project, $chunk]);
        $this->getJson($url)->assertOk()->assertExactJson(['files' => [$first->uuid]]);
        $second = UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $chunk->id]);
        $this->getJson($url)->assertOk()->assertExactJson(['files' => [$first->uuid, $second->uuid]]);

        $first->delete();
        $this->getJson($url)->assertOk()->assertExactJson(['files' => [$second->uuid]]);
        $second->update(['project_id' => $other->id]);

        $this->getJson($url)->assertOk()->assertExactJson(['files' => []]);
        $this->assertSame($movedBefore, $moved->fresh()->getAttributes());
        $this->getJson(route('projects.chunk-files', [$project, $newer]))->assertOk()->assertExactJson(['files' => [$newerImage->uuid]]);
    }

    public function test_navigation_omits_drafts_and_other_projects_files(): void
    {
        $this->actingAs(User::factory()->create(['email' => 'navigation-scope@example.test']));
        $project = Project::factory()->create(['slug' => 'navigation-scope']);
        $other = Project::factory()->create(['slug' => 'navigation-scope-other']);
        $draft = UploadChunk::factory()->create(['upload_project_id' => $project->id, 'status' => 'uploading']);
        $draftImage = UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $draft->id]);
        $published = UploadChunk::factory()->create(['upload_project_id' => $other->id, 'status' => 'complete']);
        $otherImage = UploadImage::factory()->create(['project_id' => $other->id, 'chunk_id' => $published->id]);
        $this->getJson(route('projects.chunk-files', [$project, $draft]))->assertOk()->assertExactJson(['files' => []]);
        $this->getJson(route('projects.chunk-files', [$project, $published]))->assertOk()->assertExactJson(['files' => []]);
        $this->assertDatabaseHas('upload_images', ['id' => $draftImage->id, 'project_id' => $project->id]);
        $this->assertDatabaseHas('upload_images', ['id' => $otherImage->id, 'project_id' => $other->id]);
    }
}

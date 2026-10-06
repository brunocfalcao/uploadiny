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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AgentFeedbackShapeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake([DescribeUploadImage::class, DiscardIncompleteUpload::class]);
        $this->owner = User::factory()->create(['email' => 'agent-shape@example.test']);
    }

    private function agent(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->withToken($this->owner->createToken('shape-agent', UploadinyTokenAbility::agent())->plainTextToken);
    }

    /** @param array<string, mixed> $arguments */
    private function tool(string $name, array $arguments): TestResponse
    {
        return $this->agent()->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object) $arguments]]);
    }

    private function assertNoKeyAnywhere(string $key, mixed $value): void
    {
        if (is_array($value)) {
            $this->assertArrayNotHasKey($key, $value);
            foreach ($value as $child) {
                $this->assertNoKeyAnywhere($key, $child);
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function annotations(): array
    {
        return [
            ['tool' => 'pen', 'color' => '#ef4444', 'width' => 0.003, 'points' => [['x' => 0.1, 'y' => 0.2], ['x' => 0.3, 'y' => 0.5], ['x' => 0.2, 'y' => 0.9]]],
            ['tool' => 'arrow', 'color' => '#3b82f6', 'width' => 0.003, 'points' => [['x' => 0.8123, 'y' => 0.1], ['x' => 0.5, 'y' => 0.4567]]],
            ['tool' => 'callout', 'color' => '#16a34a', 'width' => 0.003, 'text' => "Make it bigger.\nKeep spacing.", 'points' => [['x' => 0.4, 'y' => 0.45], ['x' => 0.6, 'y' => 0.55], ['x' => 0.1, 'y' => 0.8], ['x' => 0.5, 'y' => 0.95]]],
            ['tool' => 'rectangle', 'color' => '#000000', 'width' => 0.003, 'points' => [['x' => 0.7, 'y' => 0.7], ['x' => 0.9, 'y' => 0.95]]],
        ];
    }

    public function test_marks_replace_raw_points_and_rest_matches_mcp(): void
    {
        $project = Project::factory()->create();
        $image = UploadImage::factory()->create(['project_id' => $project->id, 'comments' => 'See marks.', 'annotations' => $this->annotations(), 'feedback_revision' => 2]);

        $rest = $this->agent()->getJson(route('api.feedback.latest', ['project' => $project->canonical]))->assertOk()->json();
        $byId = $this->agent()->getJson(route('api.projects.latest', $project))->assertOk()->json();
        $mcp = $this->tool('get_feedback', ['project_canonical' => $project->canonical])->assertOk()->assertJsonPath('result.isError', false)->json('result.structuredContent');

        $this->assertSame($rest, $mcp);
        $this->assertSame($rest, $byId);
        $this->assertNoKeyAnywhere('points', $rest);
        $this->assertNoKeyAnywhere('annotations', $rest);
        $asset = $rest['chunk']['images'][0];
        $this->assertSame($image->uuid, $asset['id']);
        $this->assertSame(4, $asset['mark_count']);
        $this->assertCount(4, $asset['marks']);
        $this->assertSame([1, 2, 3, 4], array_column($asset['marks'], 'n'));

        [$pen, $arrow, $callout, $rectangle] = $asset['marks'];
        $this->assertSame(['tool' => 'pen', 'color' => '#ef4444', 'position' => 'middle-left', 'area' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.2, 'height' => 0.7]], array_intersect_key($pen, array_flip(['tool', 'color', 'area', 'position'])));
        $this->assertNull($pen['note']);
        $this->assertNull($pen['note_area']);
        $this->assertNull($pen['from']);
        $this->assertNull($pen['to']);
        $this->assertSame(['x' => 0.812, 'y' => 0.1], $arrow['from']);
        $this->assertSame(['x' => 0.5, 'y' => 0.457], $arrow['to']);
        $this->assertSame(['x' => 0.5, 'y' => 0.1, 'width' => 0.312, 'height' => 0.357], $arrow['area']);
        $this->assertSame('top-center', $arrow['position']);
        $this->assertSame("Make it bigger.\nKeep spacing.", $callout['note']);
        $this->assertSame(['x' => 0.4, 'y' => 0.45, 'width' => 0.2, 'height' => 0.1], $callout['area']);
        $this->assertSame(['x' => 0.1, 'y' => 0.8, 'width' => 0.4, 'height' => 0.15], $callout['note_area']);
        $this->assertSame('middle', $callout['position']);
        $this->assertNull($callout['from']);
        $this->assertSame('bottom-right', $rectangle['position']);
        $this->assertSame('#000000', $rectangle['color']);
    }

    public function test_get_asset_and_recording_frames_use_compact_marks_and_keep_exact_bytes(): void
    {
        $image = UploadImage::factory()->create(['comments' => 'x', 'annotations' => $this->annotations(), 'annotated_path' => 'shape/annotated.png']);
        Storage::disk('local')->put($image->path, 'original-bytes');
        Storage::disk('local')->put($image->annotated_path, 'annotated-bytes');

        $response = $this->tool('get_asset', ['asset_id' => $image->uuid, 'variant' => 'annotated'])->assertOk()
            ->assertJsonPath('result.content.1.data', base64_encode('annotated-bytes'))
            ->assertJsonPath('result.structuredContent.asset.mark_count', 4)
            ->assertJsonPath('result.structuredContent.asset.marks.2.note_area.height', 0.15);
        $this->assertNoKeyAnywhere('points', $response->json());
        $this->assertNoKeyAnywhere('annotations', $response->json('result.structuredContent'));
        $this->assertSame('original-bytes', base64_decode((string) $this->tool('get_asset', ['asset_id' => $image->uuid])->assertOk()->json('result.content.1.data')));
    }

    public function test_saving_feedback_stamps_freshness_and_settling_expires_after_sixty_seconds(): void
    {
        $project = Project::factory()->create();
        $image = UploadImage::factory()->create(['project_id' => $project->id, 'comments' => 'draft']);
        $this->assertNull($image->fresh()->feedback_updated_at);
        $first = $this->agent()->getJson(route('api.feedback.latest', ['project' => $project->canonical]))->assertOk();
        $first->assertJsonPath('chunk.settling', false)->assertJsonPath('chunk.images.0.feedback_updated_at', null)->assertJsonPath('chunk.images.0.feedback_settling', false);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->actingAs($this->owner)->patchJson(route('images.update', $image), ['comments' => 'final', 'annotations' => [], 'revision' => 0])->assertOk();
        $stamp = $image->fresh()->feedback_updated_at;
        $this->assertNotNull($stamp);

        $settling = $this->tool('get_feedback', ['project_canonical' => $project->canonical])->assertOk();
        $settling->assertJsonPath('result.structuredContent.chunk.settling', true)->assertJsonPath('result.structuredContent.chunk.images.0.feedback_settling', true)
            ->assertJsonPath('result.structuredContent.chunk.images.0.feedback_updated_at', $stamp->toIso8601String());

        $this->travel(61)->seconds();
        $this->tool('get_feedback', ['project_canonical' => $project->canonical])->assertOk()
            ->assertJsonPath('result.structuredContent.chunk.settling', false)->assertJsonPath('result.structuredContent.chunk.images.0.feedback_settling', false)
            ->assertJsonPath('result.structuredContent.chunk.images.0.feedback_updated_at', $stamp->toIso8601String());
    }

    public function test_browser_and_phone_responses_keep_full_annotation_points(): void
    {
        $project = Project::factory()->create();
        $image = UploadImage::factory()->create(['project_id' => $project->id, 'annotations' => $this->annotations(), 'annotated_path' => 'shape/browser.png', 'feedback_revision' => 1]);
        Storage::disk('local')->put($image->annotated_path, 'png');
        $annotations = $this->annotations();

        $this->flushHeaders()->actingAs($this->owner);
        $this->getJson(route('images.show', $image))->assertOk()->assertJsonPath('annotations', $annotations)->assertJsonMissingPath('marks')->assertJsonMissingPath('feedback_settling');
        $png = UploadedFile::fake()->image('p.png', 30, 20)->getContent();
        $saved = $this->patchJson(route('images.update', $image), ['comments' => 'again', 'annotations' => $annotations, 'revision' => 1, 'annotated_image' => 'data:image/png;base64,'.base64_encode($png)])
            ->assertOk()->assertJsonMissingPath('marks');
        $this->assertEquals($annotations, $saved->json('annotations'));
        $this->assertSame(0.3, $saved->json('annotations.0.points.1.x'));

        $this->app['auth']->forgetGuards();
        $phone = $this->flushHeaders()->withToken($this->owner->createToken('phone', UploadinyTokenAbility::phone())->plainTextToken);
        $start = $phone->postJson(route('api.chunks.start', $project), ['image_count' => 1])->assertCreated();
        $phone->postJson(route('api.chunks.append', $start->json('id')), ['file' => UploadedFile::fake()->image('phone.png'), 'comments' => 'from phone'])->assertCreated()
            ->assertJsonPath('image.annotations', [])->assertJsonPath('image.comments', 'from phone')->assertJsonMissingPath('image.marks');
        $phone->postJson(route('api.chunks.complete', $start->json('id')))->assertOk()->assertJsonPath('images.0.annotations', [])->assertJsonMissingPath('images.0.marks');
    }

    public function test_list_projects_reports_each_projects_latest_completed_chunk_in_one_pass(): void
    {
        $project = Project::factory()->create(['name' => 'Alpha']);
        $empty = Project::factory()->create(['name' => 'Beta']);
        $older = UploadChunk::factory()->create(['upload_project_id' => $project->id, 'status' => 'complete', 'completed_at' => now()->subHour()]);
        $newer = UploadChunk::factory()->create(['upload_project_id' => $project->id, 'status' => 'complete', 'completed_at' => now()]);
        UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $older->id]);
        UploadImage::factory()->count(2)->create(['project_id' => $project->id, 'chunk_id' => $newer->id]);
        $draft = UploadChunk::factory()->create(['upload_project_id' => $project->id, 'status' => 'draft']);
        UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $draft->id]);

        $rest = $this->agent()->getJson(route('api.projects.index'))->assertOk()->json('projects');
        $mcp = $this->tool('list_projects', [])->assertOk()->json('result.structuredContent.projects');

        $this->assertSame($rest, $mcp);
        $this->assertSame(['id' => $newer->uuid, 'completed_at' => $newer->completed_at->toIso8601String(), 'asset_count' => 2], $rest[0]['latest_chunk']);
        $this->assertSame(3, $rest[0]['image_count']);
        $this->assertSame($empty->canonical, $rest[1]['canonical']);
        $this->assertNull($rest[1]['latest_chunk']);
    }
}

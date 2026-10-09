<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Project;
use App\Services\AgentImageContent;
use App\UploadChunk;
use App\UploadImage;
use App\UploadinyTokenAbility;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AgentRetrievalOptionsTest extends TestCase
{
    use RefreshDatabase;

    private function reader(string $prefix): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => $prefix.'@example.test']);
        $this->withToken($user->createToken($prefix, UploadinyTokenAbility::agent())->plainTextToken);
    }

    /** @param array<string, mixed> $arguments */
    private function tool(string $name, array $arguments): TestResponse
    {
        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object) $arguments]]);
    }

    /** @return list<array{string}> */
    public static function variants(): array
    {
        return [['original'], ['annotated']];
    }

    #[DataProvider('variants')]
    public function test_requested_width_preserves_aspect_ratio_and_both_stored_variants(string $variant): void
    {
        $this->reader('resize-'.$variant);
        $image = UploadImage::factory()->create(['name' => 'resize-'.$variant, 'annotated_path' => 'resize/'.$variant.'.png']);
        $png = UploadedFile::fake()->image('resize.png', 1320, 2868)->getContent();
        Storage::disk('local')->put($image->path, $png);
        Storage::disk('local')->put($image->annotated_path, $png);
        $before = $image->fresh()->getAttributes();
        $this->tool('get_asset', ['asset_id' => $image->uuid, 'variant' => $variant])->assertJsonPath('result.content.1.data', base64_encode($png));

        $response = $this->tool('get_asset', ['asset_id' => $image->uuid, 'variant' => $variant, 'image_width' => 900])
            ->assertOk();
        $this->assertFalse($response->json('result.isError'), $response->json('result.content.0.text').' used='.memory_get_usage(true).' limit='.ini_get('memory_limit'));
        $response->assertJsonPath('result.content.1.mimeType', 'image/png');

        $dimensions = getimagesizefromstring(base64_decode($response->json('result.content.1.data'), true));
        $this->assertSame([900, 1955], [$dimensions[0], $dimensions[1]]);
        $this->assertSame($png, Storage::disk('local')->get($image->path));
        $this->assertSame($png, Storage::disk('local')->get($image->annotated_path));
        $this->assertSame($before, $image->fresh()->getAttributes());
    }

    public function test_smaller_images_are_not_upscaled_or_reencoded(): void
    {
        $this->reader('no-upscale');
        $image = UploadImage::factory()->create(['name' => 'no-upscale']);
        $png = UploadedFile::fake()->image('small.png', 320, 640)->getContent();
        Storage::disk('local')->put($image->path, $png);

        $this->tool('get_asset', ['asset_id' => $image->uuid, 'image_width' => 900])->assertOk()->assertJsonPath('result.content.1.data', base64_encode($png));

        $this->assertSame($png, Storage::disk('local')->get($image->path));
    }

    public function test_first_feedback_call_includes_only_marked_screenshots_in_the_selected_project(): void
    {
        $this->reader('inline-batch');
        $project = Project::factory()->create(['name' => 'inline-batch', 'slug' => 'inline-batch']);
        $chunk = UploadChunk::factory()->create(['upload_project_id' => $project->id]);
        $marks = [['tool' => 'rectangle', 'points' => [['x' => 0.1, 'y' => 0.2], ['x' => 0.3, 'y' => 0.4]]]];
        $png = UploadedFile::fake()->image('inline.png', 1200, 2400)->getContent();
        $images = [];
        foreach (['first', 'second', 'unmarked', 'video', 'moved'] as $name) {
            $image = UploadImage::factory()->create([
                'name' => 'inline-'.$name, 'project_id' => $project->id, 'chunk_id' => $chunk->id,
                'annotations' => $name === 'unmarked' ? [] : $marks, 'annotated_path' => 'inline/'.$name.'.png',
                'mime_type' => $name === 'video' ? 'video/mp4' : 'image/png',
            ]);
            Storage::disk('local')->put($image->annotated_path, $png);
            $images[] = $image;
        }
        $other = Project::factory()->create(['name' => 'inline-other', 'slug' => 'inline-other']);
        $images[4]->update(['project_id' => $other->id]);
        $before = array_map(fn (UploadImage $image): array => $image->fresh()->getAttributes(), $images);
        $metadataOnly = $this->tool('get_feedback', ['project_canonical' => $project->canonical, 'include_annotated_images' => false])
            ->assertJsonCount(1, 'result.content')->json('result.structuredContent');

        $response = $this->tool('get_feedback', ['project_canonical' => $project->canonical, 'image_width' => 900])
            ->assertOk()->assertJsonPath('result.isError', false);
        $this->assertCount(5, $response->json('result.content'), json_encode(array_column($response->json('result.content'), 'text')));

        $this->assertSame($metadataOnly, $response->json('result.structuredContent'));
        foreach ([0 => 2, 1 => 4] as $assetIndex => $contentIndex) {
            $this->assertSame('Annotated screenshot for asset '.$images[$assetIndex]->uuid.'.', $response->json('result.content.'.($contentIndex - 1).'.text'));
            $dimensions = getimagesizefromstring(base64_decode($response->json('result.content.'.$contentIndex.'.data'), true));
            $this->assertSame([900, 1800], [$dimensions[0], $dimensions[1]]);
        }
        $this->assertSame($before, array_map(fn (UploadImage $image): array => $image->fresh()->getAttributes(), $images));
        foreach ($images as $image) {
            $this->assertSame($png, Storage::disk('local')->get($image->annotated_path));
        }
    }

    public function test_descriptions_can_be_omitted_from_all_feedback_calls_without_changing_review_identity(): void
    {
        $this->reader('omit-descriptions');
        $project = Project::factory()->create(['name' => 'omit-descriptions', 'slug' => 'omit-descriptions']);
        $image = UploadImage::factory()->create([
            'name' => 'omit-image', 'project_id' => $project->id, 'comments' => 'Exact owner instruction.',
            'description' => 'wrong-football-description', 'description_error' => 'wrong-provider-error', 'description_model' => 'wrong-model',
        ]);
        Storage::disk('local')->put($image->path, 'original-bytes');
        $before = $image->fresh()->getAttributes();
        $full = $this->tool('get_feedback', ['project_canonical' => $project->canonical])->assertJsonPath('result.structuredContent.chunk.images.0.description', 'wrong-football-description')->json('result.structuredContent');

        $response = $this->tool('get_feedback', ['project_canonical' => $project->canonical, 'include_descriptions' => false])->assertOk();
        $asset = $this->tool('get_asset', ['asset_id' => $image->uuid, 'include_descriptions' => false])->assertOk();
        $rest = $this->getJson(route('api.feedback.latest', ['project' => $project->canonical, 'include_descriptions' => 0]))->assertOk();

        $this->assertSame($rest->json(), $response->json('result.structuredContent'));
        $this->assertSame($full['chunk']['review_token'], $response->json('result.structuredContent.chunk.review_token'));
        $response->assertJsonPath('result.structuredContent.chunk.images.0.comments', 'Exact owner instruction.');
        foreach (['description', 'description_status', 'description_error', 'description_model'] as $key) {
            $response->assertJsonMissingPath('result.structuredContent.chunk.images.0.'.$key);
            $asset->assertJsonMissingPath('result.structuredContent.asset.'.$key);
        }
        $response->assertDontSee('wrong-football-description')->assertDontSee('wrong-provider-error')->assertDontSee('wrong-model');
        $asset->assertDontSee('wrong-football-description');
        $this->assertSame($before, $image->fresh()->getAttributes());

        $video = UploadImage::factory()->create(['name' => 'omit-video', 'mime_type' => 'video/mp4', 'description' => 'wrong-recording-description']);
        Storage::disk('local')->put($video->path, 'video-bytes');
        Process::fake(['*' => Process::result(output: 'frame')]);
        $this->tool('get_recording_frames', ['asset_id' => $video->uuid, 'include_descriptions' => false])->assertOk()
            ->assertJsonMissingPath('result.structuredContent.asset.description')->assertDontSee('wrong-recording-description')->assertJsonPath('result.content.2.data', base64_encode("frame\n"));
        Process::assertRan(fn ($process): bool => in_array(Storage::disk('local')->path($video->path), $process->command, true));
        $this->assertSame('wrong-recording-description', $video->fresh()->description);
    }

    public function test_after_chunk_uses_completion_order_and_id_ties_and_ignores_drafts(): void
    {
        $this->reader('new-batch');
        $project = Project::factory()->create(['name' => 'new-batch', 'slug' => 'new-batch']);
        $cursor = UploadChunk::factory()->create(['completed_at' => '2026-10-01 12:00:00', 'created_at' => '2026-09-01 12:00:00']);
        UploadImage::factory()->create(['name' => 'cursor-image', 'project_id' => $project->id, 'chunk_id' => $cursor->id]);
        $before = $cursor->fresh()->getAttributes();
        $args = ['project_canonical' => $project->canonical, 'after_chunk' => $cursor->uuid];
        $this->tool('get_feedback', $args)->assertOk()->assertJsonPath('result.structuredContent.chunk', null);
        $older = UploadChunk::factory()->create(['completed_at' => '2026-10-01 11:00:00', 'created_at' => '2026-10-02 12:00:00']);
        UploadImage::factory()->create(['name' => 'older-image', 'project_id' => $project->id, 'chunk_id' => $older->id]);
        $tied = UploadChunk::factory()->create(['completed_at' => '2026-10-01 12:00:00']);
        UploadImage::factory()->create(['name' => 'tied-image', 'project_id' => $project->id, 'chunk_id' => $tied->id]);
        $this->tool('get_feedback', $args)->assertOk()->assertJsonPath('result.structuredContent.chunk.id', $tied->uuid);
        $new = UploadChunk::factory()->create(['completed_at' => '2026-10-02 12:00:00', 'created_at' => '2026-09-01 11:00:00']);
        UploadImage::factory()->create(['name' => 'new-image', 'project_id' => $project->id, 'chunk_id' => $new->id]);
        $draft = UploadChunk::factory()->create(['status' => 'draft', 'completed_at' => '2026-10-03 12:00:00']);
        UploadImage::factory()->create(['name' => 'draft-image', 'project_id' => $project->id, 'chunk_id' => $draft->id]);

        $response = $this->tool('get_feedback', $args)->assertOk()->assertJsonPath('result.structuredContent.chunk.id', $new->uuid);

        $rest = $this->getJson(route('api.feedback.latest', ['project' => $project->canonical, 'after_chunk' => $cursor->uuid]))->assertOk();
        $this->assertSame($rest->json(), $response->json('result.structuredContent'));
        $this->tool('get_feedback', ['project_canonical' => $project->canonical, 'after_chunk' => $new->uuid])->assertOk()->assertJsonPath('result.structuredContent.chunk', null);
        $this->assertSame($before, $cursor->fresh()->getAttributes());
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_unavailable_draft_and_other_project_cursors_fail_clearly(): void
    {
        $this->reader('invalid-cursor');
        $project = Project::factory()->create(['name' => 'invalid-cursor', 'slug' => 'invalid-cursor']);
        $other = UploadImage::factory()->create(['name' => 'other-cursor-image']);
        $draft = UploadChunk::factory()->create(['status' => 'draft']);
        UploadImage::factory()->create(['name' => 'draft-cursor-image', 'project_id' => $project->id, 'chunk_id' => $draft->id]);
        $before = $other->fresh()->getAttributes();

        foreach ([(string) Str::uuid(), $draft->uuid, $other->chunk->uuid] as $cursor) {
            $this->tool('get_feedback', ['project_canonical' => $project->canonical, 'after_chunk' => $cursor])->assertOk()
                ->assertJsonPath('result.isError', true)->assertSee('Fetch without after_chunk')->assertJsonMissingPath('result.structuredContent');
            $this->getJson(route('api.feedback.latest', ['project' => $project->canonical, 'after_chunk' => $cursor]))->assertUnprocessable()->assertJsonValidationErrors('after_chunk');
        }

        $this->assertSame($before, $other->fresh()->getAttributes());
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_legacy_cursor_uses_creation_time_and_does_not_treat_same_batch_edits_as_new(): void
    {
        $this->reader('legacy-cursor');
        $project = Project::factory()->create(['name' => 'legacy-cursor', 'slug' => 'legacy-cursor']);
        $cursor = UploadChunk::factory()->create(['completed_at' => null, 'created_at' => '2026-10-01 12:00:00']);
        $image = UploadImage::factory()->create(['name' => 'legacy-cursor-image', 'project_id' => $project->id, 'chunk_id' => $cursor->id, 'comments' => 'Before edit.']);
        $args = ['project_canonical' => $project->canonical, 'after_chunk' => $cursor->uuid];
        $this->tool('get_feedback', $args)->assertOk()->assertJsonPath('result.structuredContent.chunk', null);

        $image->update(['comments' => 'Edited within same batch.', 'feedback_revision' => 1]);
        $this->tool('get_feedback', $args)->assertOk()->assertJsonPath('result.structuredContent.chunk', null);
        $this->tool('get_feedback', ['project_canonical' => $project->canonical])->assertOk()->assertJsonPath('result.structuredContent.chunk.images.0.comments', 'Edited within same batch.');
        $new = UploadChunk::factory()->create(['completed_at' => null, 'created_at' => '2026-10-01 12:00:01']);
        UploadImage::factory()->create(['name' => 'legacy-new-image', 'project_id' => $project->id, 'chunk_id' => $new->id]);
        $this->tool('get_feedback', $args)->assertOk()->assertJsonPath('result.structuredContent.chunk.id', $new->uuid);

        $this->assertSame('Edited within same batch.', $image->fresh()->comments);
        $this->assertSame(1, $image->fresh()->feedback_revision);
        $this->assertNull($cursor->fresh()->completed_at);
    }

    public function test_missing_and_oversized_inline_images_keep_feedback_and_other_images_available(): void
    {
        $this->reader('inline-fallback');
        $project = Project::factory()->create(['name' => 'inline-fallback', 'slug' => 'inline-fallback']);
        $chunk = UploadChunk::factory()->create();
        $images = [];
        foreach (['missing', 'large', 'available'] as $name) {
            $images[] = UploadImage::factory()->create(['name' => 'fallback-'.$name, 'project_id' => $project->id, 'chunk_id' => $chunk->id,
                'comments' => 'Keep '.$name.' feedback.', 'annotations' => [['tool' => 'pen']], 'annotated_path' => 'fallback/'.$name.'.png']);
        }
        $disk = Storage::disk('local');
        $disk->put($images[1]->annotated_path, '');
        $handle = fopen($disk->path($images[1]->annotated_path), 'r+');
        ftruncate($handle, 95 * 1024 * 1024);
        fclose($handle);
        $disk->put($images[2]->annotated_path, 'available-image-bytes');
        $previous = ini_get('memory_limit');
        ini_set('memory_limit', '256M');
        try {
            $this->tool('get_feedback', ['project_canonical' => $project->canonical])->assertOk()->assertJsonPath('result.isError', false)
                ->assertJsonCount(3, 'result.structuredContent.chunk.images')->assertSee('No annotated image')->assertSee('memory budget')
                ->assertJsonPath('result.content.4.data', base64_encode('available-image-bytes'))->assertDontSee('fallback/large.png');
        } finally {
            ini_set('memory_limit', $previous);
        }

        $this->assertSame(95 * 1024 * 1024, $disk->size($images[1]->annotated_path));
        $disk->assertMissing($images[0]->annotated_path);
        $this->assertSame('Keep large feedback.', $images[1]->fresh()->comments);
    }

    public function test_resize_rejects_unsafe_decoded_dimensions_and_corrupt_images_without_touching_originals(): void
    {
        $this->reader('unsafe-resize');
        $image = UploadImage::factory()->create(['name' => 'unsafe-resize']);
        $png = UploadedFile::fake()->image('header.png', 1, 1)->getContent();
        $inflated = substr_replace($png, pack('NN', 50000, 50000), 16, 8);
        Storage::disk('local')->put($image->path, $inflated);

        $this->tool('get_asset', ['asset_id' => $image->uuid, 'image_width' => 900])->assertOk()->assertJsonPath('result.isError', true)->assertSee('memory budget');

        $this->assertSame($inflated, Storage::disk('local')->get($image->path));
        Storage::disk('local')->put($image->path, 'corrupt-image');
        $this->tool('get_asset', ['asset_id' => $image->uuid, 'image_width' => 900])->assertOk()->assertJsonPath('result.isError', true)->assertSee('cannot be resized');
        $this->assertSame('corrupt-image', Storage::disk('local')->get($image->path));
    }

    public function test_inline_reservation_is_applied_across_images(): void
    {
        $this->reader('batch-reservation');
        $image = UploadImage::factory()->create(['name' => 'batch-reservation']);
        Storage::disk('local')->put($image->path, 'small-image');
        $reader = app(AgentImageContent::class);
        $this->assertSame('small-image', $reader->read($image, 'original')['data']);

        try {
            $reader->read($image, 'original', reservedBytes: PHP_INT_MAX);
            $this->fail('A exhausted batch budget must reject more inline bytes.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('memory budget', $error->getMessage());
        }

        $this->assertSame('small-image', Storage::disk('local')->get($image->path));
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function invalidOptions(): array
    {
        return [
            'zero width' => ['get_asset', ['image_width' => 0]],
            'negative width' => ['get_asset', ['image_width' => -1]],
            'too wide' => ['get_asset', ['image_width' => 4097]],
            'fractional width' => ['get_asset', ['image_width' => 900.5]],
            'null width' => ['get_asset', ['image_width' => null]],
            'invalid descriptions' => ['get_feedback', ['include_descriptions' => 'no']],
            'invalid images toggle' => ['get_feedback', ['include_annotated_images' => 'no']],
            'invalid cursor' => ['get_feedback', ['after_chunk' => "' OR 1=1"]],
        ];
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_options_return_tool_errors(string $tool, array $options): void
    {
        $this->reader('invalid-options');
        $project = Project::factory()->create(['name' => 'invalid-options', 'slug' => 'invalid-options']);
        $image = UploadImage::factory()->create(['name' => 'invalid-options-image', 'project_id' => $project->id]);
        $before = $image->fresh()->getAttributes();

        $this->tool($tool, $options + ['project_canonical' => $project->canonical, 'asset_id' => $image->uuid])->assertOk()->assertJsonPath('result.isError', true);

        $this->assertSame($before, $image->fresh()->getAttributes());
    }
}

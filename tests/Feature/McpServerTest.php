<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Project;
use App\Services\AgentAccess;
use App\UploadChunk;
use App\UploadImage;
use App\UploadinyTokenAbility;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $params */
    private function rpc(string $method, array $params = []): TestResponse
    {
        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => (object) $params]);
    }

    /** @param array<string, mixed> $arguments */
    private function tool(string $name, array $arguments = []): TestResponse
    {
        return $this->rpc('tools/call', ['name' => $name, 'arguments' => (object) $arguments]);
    }

    private function reader(string $email): User
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => $email]);
        $this->withToken($user->createToken('mcp-test-agent', UploadinyTokenAbility::agent())->plainTextToken);

        return $user;
    }

    public function test_missing_invalid_and_phone_credentials_cannot_use_mcp(): void
    {
        $user = User::factory()->create(['email' => 'mcp-auth@example.test']);
        $this->rpc('tools/list')->assertUnauthorized();
        $this->withToken('invalid-mcp-key')->rpc('tools/list')->assertUnauthorized();
        $this->withToken($user->createToken('mcp-phone', UploadinyTokenAbility::phone())->plainTextToken)->rpc('tools/list')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->actingAs($user)->rpc('tools/list')->assertUnauthorized();
    }

    public function test_discovery_advertises_four_read_tools_and_one_destructive_cleanup_tool(): void
    {
        $this->reader('mcp-discovery@example.test');
        $this->rpc('initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'Uploadiny test', 'version' => '1.0']])
            ->assertOk()->assertJsonPath('result.serverInfo.name', 'Uploadiny')->assertJsonPath('result.protocolVersion', '2025-11-25')->assertHeader('Cache-Control', 'no-store, private');
        $response = $this->rpc('tools/list')->assertOk()->assertJsonCount(5, 'result.tools');
        $tools = $response->json('result.tools');
        $this->assertSame(['list_projects', 'get_feedback', 'get_asset', 'get_recording_frames', 'delete_chunk'], array_column($tools, 'name'));
        foreach (array_slice($tools, 0, 4) as $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint']);
            $this->assertFalse($tool['annotations']['openWorldHint']);
        }
        $this->assertFalse($tools[4]['annotations']['readOnlyHint']);
        $this->assertTrue($tools[4]['annotations']['destructiveHint']);
        $this->assertFalse($tools[4]['annotations']['openWorldHint']);
        $this->tool('delete_asset', ['asset_id' => 'arbitrary'])->assertStatus(400)->assertJsonPath('error.code', -32602);
    }

    public function test_latest_protocol_discovery_and_tool_call_work_with_mirrored_headers(): void
    {
        $this->reader('mcp-current-protocol@example.test');
        $meta = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => (object) []];
        $this->withHeaders(['MCP-Protocol-Version' => '2026-07-28', 'MCP-Method' => 'tools/list'])
            ->rpc('tools/list', ['_meta' => $meta])->assertOk()->assertJsonCount(5, 'result.tools');
        $this->withHeaders(['MCP-Protocol-Version' => '2026-07-28', 'MCP-Method' => 'tools/call'])
            ->rpc('tools/call', ['name' => 'list_projects', 'arguments' => (object) [], '_meta' => $meta])
            ->assertStatus(400)->assertJsonPath('error.code', -32020);
        $this->withHeaders(['MCP-Protocol-Version' => '2026-07-28', 'MCP-Method' => 'tools/call', 'MCP-Name' => 'list_projects'])
            ->rpc('tools/call', ['name' => 'list_projects', 'arguments' => (object) [], '_meta' => $meta])
            ->assertOk()->assertJsonPath('result.isError', false)->assertJsonPath('result.structuredContent.projects', []);
    }

    public function test_feedback_matches_the_api_and_ignores_newer_drafts_and_moved_assets(): void
    {
        $this->reader('mcp-feedback@example.test');
        $project = Project::factory()->create(['name' => 'MCP feedback', 'slug' => 'mcp-feedback']);
        $other = Project::factory()->create(['name' => 'MCP other', 'slug' => 'mcp-other']);
        $image = UploadImage::factory()->create(['project_id' => $project->id, 'comments' => 'Make these buttons bigger.', 'feedback_revision' => 3, 'annotations' => [['type' => 'rect', 'x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.4]], 'description' => 'AI summary only.']);
        UploadImage::factory()->create(['project_id' => $other->id, 'chunk_id' => $image->chunk_id, 'comments' => 'Moved asset remark.']);
        $draft = UploadChunk::factory()->create(['upload_project_id' => $project->id, 'status' => 'draft']);
        UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $draft->id, 'comments' => 'Incomplete upload.']);
        $before = $image->fresh()->getAttributes();
        $expected = $this->getJson(route('api.feedback.latest', ['project' => $project->canonical]))->assertOk()->json();

        $response = $this->tool('get_feedback', ['project_canonical' => $project->canonical])->assertOk()->assertJsonPath('result.isError', false);

        $this->assertSame($expected, $response->json('result.structuredContent'));
        $response->assertJsonCount(1, 'result.structuredContent.chunk.images')->assertJsonPath('result.structuredContent.chunk.images.0.comments', 'Make these buttons bigger.')->assertJsonPath('result.structuredContent.chunk.images.0.revision', 3);
        $this->assertSame($before, $image->fresh()->getAttributes());
        $this->assertSame('draft', $draft->fresh()?->status);
        $projects = $this->tool('list_projects')->assertOk();
        $this->assertSame($this->getJson(route('api.projects.index'))->json(), $projects->json('result.structuredContent'));
    }

    public function test_empty_project_returns_no_batch_and_an_unknown_project_returns_a_tool_error(): void
    {
        $this->reader('mcp-empty@example.test');
        $project = Project::factory()->create(['name' => 'MCP empty', 'slug' => 'mcp-empty']);
        $this->tool('get_feedback', ['project_canonical' => $project->canonical])->assertOk()->assertJsonPath('result.structuredContent.chunk', null);
        $this->tool('get_feedback', ['project_canonical' => 'zzzzzz'])->assertOk()->assertJsonPath('result.isError', true)->assertJsonMissingPath('result.structuredContent');
    }

    public function test_original_and_annotated_tools_deliver_exact_private_image_bytes_and_feedback(): void
    {
        $this->reader('mcp-images@example.test');
        $image = UploadImage::factory()->create(['comments' => 'Change the spacing.', 'annotated_path' => 'mcp-images/annotated.png']);
        Storage::disk('local')->put($image->path, 'original-private-image');
        Storage::disk('local')->put($image->annotated_path, 'annotated-private-image');
        $before = $image->fresh()->getAttributes();

        $this->tool('get_asset', ['asset_id' => $image->uuid])->assertOk()->assertJsonPath('result.content.1.type', 'image')->assertJsonPath('result.content.1.data', base64_encode('original-private-image'))->assertJsonPath('result.structuredContent.asset.comments', 'Change the spacing.');
        $this->tool('get_asset', ['asset_id' => $image->uuid, 'variant' => 'annotated'])->assertOk()->assertJsonPath('result.content.1.data', base64_encode('annotated-private-image'))->assertJsonPath('result.content.1.mimeType', 'image/png');

        $this->assertSame($before, $image->fresh()->getAttributes());
        $this->assertSame('original-private-image', Storage::disk('local')->get($image->path));
    }

    public function test_browser_saved_callout_text_and_marked_image_are_retrievable_through_mcp(): void
    {
        $user = $this->reader('mcp-saved-callout@example.test');
        $project = Project::factory()->create(['slug' => 'mcp-saved-callout']);
        $image = UploadImage::factory()->create(['project_id' => $project->id, 'annotations' => [], 'comments' => 'image 2', 'feedback_revision' => 0]);
        $untouched = UploadImage::factory()->create(['project_id' => $project->id, 'chunk_id' => $image->chunk_id, 'comments' => 'Keep this separate.']);
        $beforeUntouched = $untouched->fresh()->getAttributes();
        $callout = ['tool' => 'callout', 'text' => "Change CRUISE to SPEED.\nKeep the indicator.", 'color' => '#ef4444', 'width' => 0.003, 'points' => [['x' => 0.1, 'y' => 0.4], ['x' => 0.4, 'y' => 0.5], ['x' => 0.2, 'y' => 0.1], ['x' => 0.7, 'y' => 0.3]]];
        $png = UploadedFile::fake()->image('mcp-saved-callout.png', 30, 20)->getContent();
        $this->tool('get_feedback', ['project_canonical' => $project->canonical])->assertOk()->assertJsonPath('result.structuredContent.chunk.images.0.annotations', []);

        $this->actingAs($user)->patchJson(route('images.update', $image), ['comments' => 'image 2', 'annotations' => [$callout], 'revision' => 0, 'annotated_image' => 'data:image/png;base64,'.base64_encode($png)])->assertOk()->assertJsonPath('revision', 1);
        $this->app['auth']->forgetGuards();
        $this->tool('get_feedback', ['project_canonical' => $project->canonical])->assertOk()->assertJsonPath('result.structuredContent.chunk.images.0.annotations.0', $callout)->assertJsonPath('result.structuredContent.chunk.images.0.comments', 'image 2')->assertJsonPath('result.structuredContent.chunk.images.0.revision', 1);
        $this->tool('get_asset', ['asset_id' => $image->uuid, 'variant' => 'annotated'])->assertOk()->assertJsonPath('result.structuredContent.asset.annotations.0', $callout)->assertJsonPath('result.content.1.data', base64_encode($png));
        $this->assertSame($beforeUntouched, $untouched->fresh()->getAttributes());
    }

    public function test_missing_variants_and_files_return_errors_without_exposing_private_paths(): void
    {
        $this->reader('mcp-missing@example.test');
        $image = UploadImage::factory()->create();
        $this->tool('get_asset', ['asset_id' => $image->uuid])->assertOk()->assertJsonPath('result.isError', true)->assertDontSee($image->path);
        $this->tool('get_asset', ['asset_id' => $image->uuid, 'variant' => 'annotated'])->assertOk()->assertJsonPath('result.isError', true);
        $this->tool('get_asset', ['asset_id' => '00000000-0000-4000-8000-000000000001'])->assertOk()->assertJsonPath('result.isError', true);
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function invalidArguments(): array
    {
        return [
            'missing canonical' => ['get_feedback', []],
            'invalid canonical' => ['get_feedback', ['project_canonical' => '../taxiny']],
            'file path as ID' => ['get_asset', ['asset_id' => '/etc/passwd']],
            'unknown variant' => ['get_asset', ['asset_id' => '00000000-0000-4000-8000-000000000001', 'variant' => 'remote']],
            'empty timestamps' => ['get_recording_frames', ['asset_id' => '00000000-0000-4000-8000-000000000001', 'timestamps' => []]],
            'negative timestamp' => ['get_recording_frames', ['asset_id' => '00000000-0000-4000-8000-000000000001', 'timestamps' => [-1]]],
            'too many frames' => ['get_recording_frames', ['asset_id' => '00000000-0000-4000-8000-000000000001', 'timestamps' => [0, 1, 2, 3, 4, 5]]],
        ];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidArguments')]
    public function test_invalid_tool_arguments_are_rejected(string $tool, array $arguments): void
    {
        $this->reader('mcp-validation@example.test');
        Process::fake();
        $this->tool($tool, $arguments)->assertOk()->assertJsonPath('result.isError', true);
        Process::assertNothingRan();
    }

    public function test_recording_frames_include_timestamps_and_exact_feedback_without_changing_the_asset(): void
    {
        $this->reader('mcp-recording@example.test');
        $image = UploadImage::factory()->create(['mime_type' => 'video/quicktime', 'comments' => 'At 2 seconds the menu disappears.']);
        Storage::disk('local')->put($image->path, 'video-fixture');
        Process::fake(['*' => Process::sequence()->push(Process::result(output: 'frame-zero'))->push(Process::result(output: 'frame-two'))]);
        $before = $image->fresh()->getAttributes();
        $this->tool('get_asset', ['asset_id' => $image->uuid])->assertOk()->assertJsonCount(1, 'result.content')->assertJsonPath('result.structuredContent.asset.media_type', 'video');
        $response = $this->tool('get_recording_frames', ['asset_id' => $image->uuid, 'timestamps' => [0, 2]])->assertOk()->assertJsonPath('result.isError', false);
        $this->assertEquals([0.0, 2.0], array_column($response->json('result.structuredContent.frames'), 'timestamp_seconds'));
        $response->assertJsonPath('result.content.2.data', base64_encode("frame-zero\n"))->assertJsonPath('result.content.4.data', base64_encode("frame-two\n"))->assertJsonPath('result.structuredContent.asset.comments', 'At 2 seconds the menu disappears.');
        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && in_array(Storage::disk('local')->path($image->path), $process->command, true) && $process->timeout === 5);
        $this->assertSame($before, $image->fresh()->getAttributes());
    }

    public function test_failed_extraction_is_a_safe_tool_error(): void
    {
        $this->reader('mcp-extract-error@example.test');
        $video = UploadImage::factory()->create(['mime_type' => 'video/mp4']);
        Storage::disk('local')->put($video->path, 'invalid-video');
        Process::fake(['*' => Process::result(errorOutput: 'private server path and diagnostics', exitCode: 1)]);
        $this->tool('get_recording_frames', ['asset_id' => $video->uuid])->assertOk()->assertJsonPath('result.isError', true)->assertDontSee('private server path');
        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command) && in_array('0', $process->command, true));
    }

    public function test_still_images_cannot_be_sent_to_ffmpeg(): void
    {
        $this->reader('mcp-still-recording@example.test');
        Process::fake();
        $image = UploadImage::factory()->create();
        $this->tool('get_recording_frames', ['asset_id' => $image->uuid])->assertOk()->assertJsonPath('result.isError', true);
        Process::assertNothingRan();
    }

    public function test_missing_ffmpeg_returns_actionable_guidance_without_server_diagnostics(): void
    {
        $this->reader('mcp-missing-ffmpeg@example.test');
        $video = UploadImage::factory()->create(['mime_type' => 'video/mp4']);
        Storage::disk('local')->put($video->path, 'video-fixture');
        Process::fake(['*' => Process::result(errorOutput: 'private executable path', exitCode: 127)]);

        $this->tool('get_recording_frames', ['asset_id' => $video->uuid])->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'FFmpeg is unavailable on the server. Configure UPLOADINY_FFMPEG_BINARY with its executable path.')
            ->assertDontSee('private executable path');
    }

    public function test_missing_recording_file_returns_an_error_without_starting_ffmpeg(): void
    {
        $this->reader('mcp-missing-recording@example.test');
        $video = UploadImage::factory()->create(['mime_type' => 'video/mp4']);
        Process::fake();

        $this->tool('get_recording_frames', ['asset_id' => $video->uuid])->assertOk()
            ->assertJsonPath('result.isError', true)->assertDontSee($video->path);
        Process::assertNothingRan();
    }

    public function test_rotating_and_revoking_the_backoffice_key_immediately_changes_mcp_access(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'mcp-rotation@example.test']);
        $access = app(AgentAccess::class);
        $this->assertTrue($access->rotate($user));
        $old = $access->current($user);
        $this->withToken($old)->rpc('tools/list')->assertOk();

        $this->assertTrue($access->rotate($user));
        $new = $access->current($user);
        $this->assertNotSame($old, $new);
        $this->app['auth']->forgetGuards();
        $this->withToken($old)->rpc('tools/list')->assertUnauthorized();
        $this->withToken($new)->rpc('tools/list')->assertOk();
        $access->revoke($user);
        $this->app['auth']->forgetGuards();
        $this->withToken($new)->rpc('tools/list')->assertUnauthorized();
    }
}

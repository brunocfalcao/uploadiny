<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DescribeUploadImage;
use App\Jobs\DiscardIncompleteUpload;
use App\Mcp\Tools\GetFeedback;
use App\Project;
use App\Services\AgentReportText;
use App\Services\PrivacyReport;
use App\UploadChunk;
use App\UploadImage;
use App\UploadinyTokenAbility;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrivacyReportTest extends TestCase
{
    use RefreshDatabase;

    private function access(string $app, string $category, string $kind = 'intervalBegin', string $time = '2026-10-20T09:12:01.123+01:00'): string
    {
        return json_encode(['accessor' => ['identifier' => $app, 'identifierType' => 'bundleID'], 'category' => $category, 'identifier' => '8A4054BB-1810-4F8B-8163-483409E2D35C', 'kind' => $kind, 'timeStamp' => $time, 'type' => 'access'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function network(string $app, string $domain, int $hits = 4, string $time = '2026-10-21T18:30:00Z'): string
    {
        return json_encode(['bundleID' => $app, 'context' => '', 'contextVerificationType' => 2, 'domain' => $domain, 'domainClassification' => 1, 'domainOwner' => 'Example Inc.', 'domainType' => 2, 'firstTimeStamp' => '2026-10-19T07:00:00Z', 'hits' => $hits, 'initiatedType' => 'AppInitiated', 'timeStamp' => $time, 'type' => 'networkActivity'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function synthetic(): string
    {
        return $this->access('com.example.notes', 'photos')."\n".$this->network('com.example.notes', 'api.example.com')."\n";
    }

    /** A real temporary upload, so the server sniffs its content exactly as in production. */
    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'uploadiny-report-');
        file_put_contents($path, $content);
        $this->beforeApplicationDestroyed(static fn () => @unlink($path));

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function pick(array $entry, array $keys): array
    {
        return array_map(static fn (string $key): mixed => $entry[$key], array_combine($keys, $keys));
    }

    private function phone(): string
    {
        return User::factory()->create()->createToken('report-phone', UploadinyTokenAbility::phone())->plainTextToken;
    }

    private function agent(): string
    {
        return User::factory()->create()->createToken('report-agent', UploadinyTokenAbility::agent())->plainTextToken;
    }

    private function prepare(string $slug): Project
    {
        Storage::fake('local');
        Queue::fake([DescribeUploadImage::class, DiscardIncompleteUpload::class]);

        return Project::factory()->create(['slug' => $slug]);
    }

    private function report(Project $project, string $content, array $attributes = []): UploadImage
    {
        $report = UploadImage::factory()->create($attributes + [
            'project_id' => $project->id, 'name' => 'upload-41.ndjson', 'original_name' => 'App_Privacy_Report_v4_fixture.ndjson',
            'path' => 'images/report-'.$project->slug.'.ndjson', 'mime_type' => UploadImage::PRIVACY_REPORT_MIME_TYPE,
            'size' => strlen($content), 'description_status' => 'not_applicable',
        ]);
        Storage::disk('local')->put($report->path, $content);

        return $report;
    }

    /** @param array<string, mixed> $arguments */
    private function tool(string $name, array $arguments): TestResponse
    {
        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object) $arguments]]);
    }

    public function test_iphone_shares_a_report_with_a_screenshot_and_only_the_screenshot_is_described(): void
    {
        $project = $this->prepare('report-phone-upload');
        $token = $this->phone();
        Storage::disk('local')->put('.uploadiny-sequence', '40');
        $content = $this->synthetic();
        $draft = $this->withToken($token)->postJson(route('api.chunks.start', $project), ['image_count' => 2])->assertCreated()->json('id');

        $report = $this->withToken($token)->postJson(route('api.chunks.append', $draft), ['file' => $this->upload('App_Privacy_Report_v4_2026-10-25T10_11_12.NDJSON', $content), 'comments' => 'Why does Notes read my photos?'])
            ->assertCreated()->assertJsonPath('received_images', 1)->assertJsonPath('image.name', 'upload-41.ndjson')
            ->assertJsonPath('image.media_type', 'privacy_report')->assertJsonPath('image.mime_type', 'application/x-ndjson')
            ->assertJsonPath('image.description_status', 'not_applicable')->assertJsonPath('image.comments', 'Why does Notes read my photos?')->json('image');
        $this->withToken($token)->postJson(route('api.chunks.append', $draft), ['file' => UploadedFile::fake()->image('screen.png')])->assertCreated()->assertJsonPath('image.media_type', 'image');
        Queue::assertNotPushed(DescribeUploadImage::class);

        $completed = $this->withToken($token)->postJson(route('api.chunks.complete', $draft))->assertOk()->json('images');

        $stored = UploadImage::where('uuid', $report['id'])->sole();
        $this->assertSame([$report['id'], 'upload-42.png'], [$completed[0]['id'], $completed[1]['name']]);
        $this->assertSame(['App_Privacy_Report_v4_2026-10-25T10_11_12.NDJSON', 'not_applicable', strlen($content)], [$stored->original_name, $stored->description_status, $stored->size]);
        $this->assertSame($content, Storage::disk('local')->get($stored->path));
        $screenshot = UploadImage::where('uuid', $completed[1]['id'])->sole();
        Queue::assertPushed(DescribeUploadImage::class, 1);
        Queue::assertPushed(DescribeUploadImage::class, fn (DescribeUploadImage $job): bool => $job->imageId === $screenshot->id);
    }

    /** @return array<string, array{string, string}> */
    public static function counterfeitReports(): array
    {
        return [
            'HTML page' => ['report.ndjson', "<!doctype html><html><body><script>alert(1)</script></body></html>\n"],
            'binary bytes' => ['report.ndjson', "{\"type\":\"access\"}\n\x00\x01\x02binary"],
            'PNG image' => ['report.ndjson', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')],
            'text with a binary tail beyond content sniffing' => ['report.ndjson', str_repeat('{"type":"networkActivity","domain":"a.example.com"}'."\n", 180000)."\x00\x01"],
            'empty file' => ['report.ndjson', ''],
            'text without the report extension' => ['report.txt', "{\"type\":\"access\"}\n"],
        ];
    }

    #[DataProvider('counterfeitReports')]
    public function test_iphone_append_refuses_files_that_are_not_text_reports(string $name, string $content): void
    {
        $project = $this->prepare('report-counterfeit');
        $token = $this->phone();
        $draft = $this->withToken($token)->postJson(route('api.chunks.start', $project), ['image_count' => 1])->assertCreated()->json('id');

        $this->withToken($token)->postJson(route('api.chunks.append', $draft), ['file' => $this->upload($name, $content), 'comments' => 'note'])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, UploadChunk::where('uuid', $draft)->sole()->images()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('images'));
    }

    public function test_website_and_one_shot_upload_paths_keep_refusing_reports(): void
    {
        $project = $this->prepare('report-website');
        $user = User::factory()->create();
        $draft = $this->actingAs($user)->postJson(route('chunks.start', $project), ['image_count' => 1])->assertCreated()->json('id');

        $this->postJson(route('chunks.append', $draft), ['file' => $this->upload('report.ndjson', $this->synthetic())])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson(route('chunks.store', $project), ['files' => [$this->upload('report.ndjson', $this->synthetic())]])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->phone())->postJson(route('api.chunks.store', $project), ['files' => [$this->upload('report.ndjson', $this->synthetic())]])->assertUnprocessable();

        $this->assertSame(0, UploadChunk::where('uuid', $draft)->sole()->images()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('images'));
    }

    public function test_readable_view_pages_every_entry_and_returns_unreadable_lines_raw(): void
    {
        $project = $this->prepare('report-readable');
        $lines = [$this->access('<script>alert("app")</script>', 'location', 'intervalEnd'), '', 'not json <b>bold</b>', '   '];
        for ($index = 1; $index <= 199; $index++) {
            $lines[] = $this->network('com.example.app'.$index, 'host'.$index.'.example.com', $index);
        }
        $lines[] = '{"type":"futureType","bundleID":"com.example.future"}';
        $lines[] = str_repeat('x', 70000);
        $lines[] = $this->access('com.example.last', 'mediaLibrary');
        $report = $this->report($project, implode("\r\n", $lines));
        $this->actingAs(User::factory()->create());

        $first = $this->getJson(route('images.report', $report))->assertOk()
            ->assertJsonPath('page', 1)->assertJsonPath('per_page', PrivacyReport::PAGE_SIZE)->assertJsonPath('total_entries', 204)
            ->assertJsonPath('first_entry', 1)->assertJsonPath('last_entry', 200)->assertJsonPath('has_previous', false)->assertJsonPath('has_next', true);
        $entries = $first->json('entries');
        $this->assertCount(200, $entries);
        $this->assertSame(['line' => 1, 'kind' => 'access', 'app' => '<script>alert("app")</script>', 'accessed' => 'Location', 'detail' => 'Access ended', 'time' => '20 Oct 2026, 09:12:01 +01:00'], $this->pick($entries[0], ['line', 'kind', 'app', 'accessed', 'detail', 'time']));
        $this->assertSame(['line' => 3, 'kind' => 'unreadable', 'raw' => 'not json <b>bold</b>', 'raw_truncated' => false], $this->pick($entries[1], ['line', 'kind', 'raw', 'raw_truncated']));
        $this->assertSame(['line' => 5, 'kind' => 'network', 'app' => 'com.example.app1', 'accessed' => 'host1.example.com', 'detail' => 'Example Inc. · 1 contact in 7 days · App initiated · First contact 19 Oct 2026, 07:00:00 UTC', 'time' => '21 Oct 2026, 18:30:00 UTC'], $this->pick($entries[2], ['line', 'kind', 'app', 'accessed', 'detail', 'time']));
        $this->assertSame(202, $entries[199]['line'], 'Blank lines are skipped but keep their line numbers');
        $this->assertStringNotContainsString('<script>', $first->getContent(), 'JSON escapes markup characters before the browser renders text');

        $second = $this->getJson(route('images.report', $report).'?page=2')->assertOk()->assertJsonPath('first_entry', 201)->assertJsonPath('last_entry', 204)->assertJsonPath('has_next', false)->assertJsonPath('has_previous', true)->json('entries');
        $this->assertSame([203, 204, 205, 206], array_column($second, 'line'));
        $this->assertSame(['other', 'com.example.future', 'futureType'], [$second[1]['kind'], $second[1]['app'], $second[1]['accessed']]);
        $this->assertSame(['unreadable', 2000, true, 70000], [$second[2]['kind'], mb_strlen($second[2]['raw']), $second[2]['raw_truncated'], $second[2]['raw_bytes']]);
        $this->assertSame(['access', 'Media library', 'Access started'], [$second[3]['kind'], $second[3]['accessed'], $second[3]['detail']]);
        $this->getJson(route('images.report', $report).'?page=3')->assertOk()->assertJsonPath('entries', [])->assertJsonPath('total_entries', 204)->assertJsonPath('first_entry', null);
        $this->getJson(route('images.report', $report).'?page=0')->assertUnprocessable();
    }

    public function test_reports_render_as_document_tiles_and_download_only_as_attachments(): void
    {
        $project = $this->prepare('report-gallery');
        $image = UploadImage::factory()->create(['project_id' => $project->id]);
        $report = $this->report($project, $this->synthetic(), ['chunk_id' => $image->chunk_id]);
        $this->actingAs(User::factory()->create());

        $page = $this->get(route('projects.show', $project))->assertOk()->assertSee('report-thumbnail', false)->assertSee('App Privacy Report')->assertSee('upload-41.ndjson');
        $page->assertDontSee('<img src="'.route('images.preview', $report).'"', false);
        $page->assertSee('<img src="'.route('images.preview', $image).'"', false);
        $this->getJson(route('images.show', $report))->assertOk()->assertJsonPath('media_type', 'privacy_report')->assertJsonPath('report_url', route('images.report', $report));
        $this->getJson(route('images.show', $image))->assertOk()->assertJsonMissingPath('report_url');
        $this->getJson(route('images.report', $image))->assertNotFound();

        foreach (['images.preview', 'images.download'] as $route) {
            $response = $this->get(route($route, $report))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'application/x-ndjson')->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
            $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
            $this->assertSame($this->synthetic(), $response->streamedContent());
        }
        $this->assertNull($this->get(route('images.preview', $image))->headers->get('Content-Disposition'), 'Screenshot previews stay inline');
    }

    public function test_reports_take_written_feedback_but_refuse_drawings_and_descriptions(): void
    {
        $project = $this->prepare('report-feedback');
        $report = $this->report($project, $this->synthetic(), ['feedback_revision' => 2, 'comments' => 'Before']);
        $this->actingAs(User::factory()->create());
        $mark = ['tool' => 'rectangle', 'color' => '#ef4444', 'width' => 0.003, 'points' => [['x' => 0.1, 'y' => 0.1], ['x' => 0.2, 'y' => 0.2]]];

        $this->patchJson(route('images.update', $report), ['comments' => 'Drawn', 'annotations' => [$mark], 'revision' => 2, 'annotated_image' => null])->assertUnprocessable()->assertJsonValidationErrors(['annotations' => 'Use written feedback for privacy reports.']);
        $this->patchJson(route('images.update', $report), ['comments' => 'Drawn', 'annotations' => [], 'revision' => 2, 'annotated_image' => 'data:image/png;base64,AAAA'])->assertUnprocessable();
        $this->assertSame([2, 'Before', null], [$report->fresh()->feedback_revision, $report->fresh()->comments, $report->fresh()->annotated_path]);

        $this->patchJson(route('images.update', $report), ['comments' => 'Explain the location access.', 'annotations' => [], 'revision' => 2, 'annotated_image' => null])->assertOk()->assertJsonPath('revision', 3)->assertJsonPath('comments', 'Explain the location access.');
        $this->postJson(route('images.describe', $report))->assertUnprocessable();
        $this->assertSame(['not_applicable', [], null], [$report->fresh()->description_status, $report->fresh()->annotations, $report->fresh()->annotated_path]);
        Queue::assertNothingPushed();
    }

    public function test_reports_duplicate_copy_move_and_delete_with_their_original_text(): void
    {
        $project = $this->prepare('report-transfer');
        $target = Project::factory()->create(['slug' => 'report-transfer-target']);
        $content = $this->synthetic();
        $report = $this->report($project, $content, ['comments' => 'Keep me']);
        $neighbour = UploadImage::factory()->create(['project_id' => $target->id, 'comments' => 'Neighbour']);
        $this->actingAs(User::factory()->create());

        $duplicate = UploadImage::where('uuid', $this->postJson(route('images.duplicate', $report))->assertCreated()->json('id'))->sole();
        $this->assertSame([$report->chunk_id, UploadImage::PRIVACY_REPORT_MIME_TYPE, 'not_applicable', '', $content], [$duplicate->chunk_id, $duplicate->mime_type, $duplicate->description_status, $duplicate->comments, Storage::disk('local')->get($duplicate->path)]);
        $this->assertStringEndsWith('.ndjson', $duplicate->name);

        $copy = $this->postJson(route('images.transfer-chunk', $report), ['chunk_id' => $neighbour->chunk->uuid, 'project_id' => $target->id, 'action' => 'copy'])->assertOk()->assertJsonPath('image.media_type', 'privacy_report')->assertJsonPath('image.comments', 'Keep me')->json('image');
        $copied = UploadImage::where('uuid', $copy['id'])->sole();
        $this->assertSame([$neighbour->chunk_id, $target->id, $content], [$copied->chunk_id, $copied->project_id, Storage::disk('local')->get($copied->path)]);

        $this->postJson(route('images.transfer-chunk', $duplicate), ['new_chunk' => true, 'project_id' => $target->id, 'action' => 'move'])->assertOk()->assertJsonPath('image.id', $duplicate->uuid);
        $this->assertSame([$target->id, $content], [$duplicate->fresh()->project_id, Storage::disk('local')->get($duplicate->path)]);
        Queue::assertNothingPushed();

        $this->deleteJson(route('images.destroy', $report))->assertOk();
        $this->assertNull($report->fresh());
        Storage::disk('local')->assertMissing($report->path);
        $this->assertSame($content, Storage::disk('local')->get($copied->path));
        $this->assertSame('Neighbour', $neighbour->fresh()->comments);
    }

    public function test_agent_feedback_includes_the_report_text_and_matches_rest_metadata(): void
    {
        $project = $this->prepare('report-agent');
        $content = $this->synthetic();
        $report = $this->report($project, $content, ['comments' => 'Which app read my photos?']);
        $this->withToken($this->agent());

        $rest = $this->getJson(route('api.feedback.latest', ['project' => $project->canonical]))->assertOk()
            ->assertJsonPath('chunk.images.0.media_type', 'privacy_report')->assertJsonPath('chunk.images.0.mime_type', 'application/x-ndjson')
            ->assertJsonPath('chunk.images.0.image_url', route('api.images.download', $report))->assertJsonPath('chunk.images.0.size', strlen($content))
            ->assertJsonPath('chunk.images.0.comments', 'Which app read my photos?')->json();
        $this->assertStringNotContainsString('api.example.com', json_encode($rest, JSON_THROW_ON_ERROR), 'REST stays URL-based');
        $download = $this->get(route('api.images.download', $report))->assertOk()->assertHeader('Content-Type', 'application/x-ndjson');
        $this->assertSame($content, $download->streamedContent());

        foreach ([[], ['include_annotated_images' => false]] as $options) {
            $mcp = $this->tool('get_feedback', ['project_canonical' => $project->canonical] + $options)->assertOk()->assertJsonPath('result.isError', false);
            $this->assertSame($rest, $mcp->json('result.structuredContent'));
            $this->assertSame('App Privacy Report upload-41.ndjson (asset '.$report->uuid.'), complete text follows ('.strlen($content).' bytes, 2 lines).', $mcp->json('result.content.1.text'));
            $this->assertSame($content, $mcp->json('result.content.2.text'));
            $mcp->assertJsonCount(3, 'result.content');
        }

        $asset = $this->tool('get_asset', ['asset_id' => $report->uuid, 'image_width' => 200])->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.asset.comments', 'Which app read my photos?')->assertJsonPath('result.content.2.text', $content)
            ->assertJsonPath('result.content.3.text', 'image_width does not apply to App Privacy Reports; the text is unchanged.');
        $this->assertSame($rest['chunk']['images'][0]['id'], $asset->json('result.structuredContent.asset.id'));
        $this->tool('get_asset', ['asset_id' => $report->uuid, 'variant' => 'annotated'])->assertOk()->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'App Privacy Reports have no annotated variant. Request variant original to read the report text.');
        $this->tool('get_recording_frames', ['asset_id' => $report->uuid])->assertOk()->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'This asset is an App Privacy Report, not a recording. Use get_asset with this asset ID to read its text.');
    }

    public function test_agent_report_text_is_cut_at_a_line_boundary_with_an_exact_note(): void
    {
        $project = $this->prepare('report-truncated');
        $line = $this->network('com.example.app', 'api.example.com');
        $limit = PrivacyReport::AGENT_TEXT_LIMIT_BYTES;
        $lineBytes = strlen($line) + 1;
        $count = intdiv($limit, $lineBytes) + 10;
        $content = str_repeat($line."\n", $count);
        $report = $this->report($project, $content);
        $kept = intdiv($limit, $lineBytes);
        $includedBytes = $kept * $lineBytes;
        $this->withToken($this->agent());

        foreach ([$this->tool('get_feedback', ['project_canonical' => $project->canonical]), $this->tool('get_asset', ['asset_id' => $report->uuid])] as $response) {
            $response->assertOk()->assertJsonPath('result.isError', false);
            $this->assertSame(str_repeat($line."\n", $kept), $response->json('result.content.2.text'));
            $this->assertSame('App Privacy Report upload-41.ndjson (asset '.$report->uuid.') is TRUNCATED. The text that follows contains only the first '.number_format($includedBytes).' of '.number_format(strlen($content)).' bytes (lines 1-'.number_format($kept).' of '.number_format($count).'). '
                .number_format(strlen($content) - $includedBytes).' bytes and 10 lines were omitted. Download the complete original with the authenticated URL: '.route('api.images.download', $report), $response->json('result.content.1.text'));
        }
    }

    public function test_agent_report_text_uses_singular_grammar_for_one_omitted_line(): void
    {
        $project = $this->prepare('report-singular-truncation');
        $limit = PrivacyReport::AGENT_TEXT_LIMIT_BYTES;
        $content = str_repeat('a', $limit - 1)."\n"."b\n";
        $report = $this->report($project, $content);
        $this->withToken($this->agent());

        $note = $this->tool('get_asset', ['asset_id' => $report->uuid])->assertOk()->json('result.content.1.text');

        $this->assertStringContainsString('1 line was omitted.', $note);
        $this->assertStringNotContainsString('1 line were omitted.', $note);
    }

    public function test_one_feedback_response_caps_the_combined_report_text(): void
    {
        $project = $this->prepare('report-budget');
        $chunk = UploadChunk::factory()->create(['upload_project_id' => $project->id, 'completed_at' => now()]);
        $line = str_repeat('a', 1023)."\n";
        $reports = [];
        foreach ([1, 2, 3] as $index) {
            $reports[] = $this->report($project, str_repeat($line, 30), ['chunk_id' => $chunk->id, 'path' => 'images/budget-'.$index.'.ndjson', 'name' => 'upload-'.$index.'.ndjson']);
        }
        $this->withToken($this->agent());

        $content = $this->tool('get_feedback', ['project_canonical' => $project->canonical])->assertOk()->json('result.content');

        $this->assertSame(30720, strlen($content[2]['text']));
        $this->assertSame(30720, strlen($content[4]['text']));
        $this->assertSame(GetFeedback::REPORT_TEXT_BUDGET_BYTES - 61440, strlen($content[6]['text']));
        $this->assertStringContainsString('upload-3.ndjson (asset '.$reports[2]->uuid.') is TRUNCATED', $content[5]['text']);
        $this->assertStringContainsString('(lines 1-4 of 30). 26,624 bytes and 26 lines were omitted.', $content[5]['text']);
        $this->assertCount(7, $content);
    }

    public function test_report_fallbacks_keep_metadata_and_authenticated_download_context(): void
    {
        $project = $this->prepare('report-fallbacks');
        $report = $this->report($project, $this->synthetic());
        $disk = Storage::disk('local');
        $disk->delete($report->path);
        $this->withToken($this->agent());

        $asset = $this->tool('get_asset', ['asset_id' => $report->uuid])->assertOk();

        $asset->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.structuredContent.asset.id', $report->uuid)
            ->assertSee('text is unavailable');
        $this->assertStringContainsString(route('api.images.download', $report), $asset->json('result.content.0.text'));
    }

    public function test_report_memory_fallback_keeps_the_authenticated_download_context(): void
    {
        $project = $this->prepare('report-memory-fallback');
        $report = $this->report($project, $this->synthetic());

        $fallback = app(AgentReportText::class)->read($report, PrivacyReport::AGENT_TEXT_LIMIT_BYTES, PHP_INT_MAX);

        $this->assertNull($fallback['text']);
        $this->assertStringContainsString('safe inline memory budget', $fallback['note']);
        $this->assertStringContainsString(route('api.images.download', $report), $fallback['note']);
    }

    public function test_report_and_image_content_keep_separate_response_ownership(): void
    {
        $project = $this->prepare('report-mixed-memory');
        $chunk = UploadChunk::factory()->create(['upload_project_id' => $project->id, 'completed_at' => now()]);
        $report = $this->report($project, $this->synthetic(), ['chunk_id' => $chunk->id]);
        $image = UploadImage::factory()->create([
            'project_id' => $project->id, 'chunk_id' => $chunk->id, 'annotations' => [['tool' => 'pen']], 'annotated_path' => 'annotations/mixed.png',
        ]);
        Storage::disk('local')->put($image->annotated_path, 'mixed-image-bytes');
        $this->withToken($this->agent());

        $previous = ini_get('memory_limit');
        ini_set('memory_limit', '256M');
        try {
            $response = $this->tool('get_feedback', ['project_canonical' => $project->canonical]);
        } finally {
            ini_set('memory_limit', $previous);
        }

        $response->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.content.4.data', base64_encode('mixed-image-bytes'))
            ->assertSee('App Privacy Report');
    }

    public function test_review_token_is_stable_and_changes_when_report_feedback_changes(): void
    {
        $project = $this->prepare('report-token');
        $report = $this->report($project, $this->synthetic(), ['feedback_revision' => 0]);
        $user = User::factory()->create();
        $agent = $this->agent();
        $token = fn (): string => $this->withToken($agent)->getJson(route('api.feedback.latest', ['project' => $project->canonical]))->assertOk()->json('chunk.review_token');
        $first = $token();
        $this->assertSame($first, $token());
        $this->app['auth']->forgetGuards();

        $this->flushHeaders()->actingAs($user)->patchJson(route('images.update', $report), ['comments' => 'New report note', 'annotations' => [], 'revision' => 0, 'annotated_image' => null])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->assertNotSame($first, $token());
        $mcp = $this->tool('get_feedback', ['project_canonical' => $project->canonical])->assertOk();
        $this->assertSame($token(), $mcp->json('result.structuredContent.chunk.review_token'));
        $this->assertSame('New report note', $mcp->json('result.structuredContent.chunk.images.0.comments'));
    }
}

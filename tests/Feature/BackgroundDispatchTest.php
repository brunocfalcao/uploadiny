<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DescribeUploadImage;
use App\Jobs\DiscardIncompleteUpload;
use App\Project;
use App\UploadChunk;
use App\UploadImage;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackgroundDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_cleanup_is_enqueued_before_an_outer_transaction_commits(): void
    {
        config(['queue.default' => 'database']);
        $project = Project::factory()->create(['slug' => 'dispatch-start']);
        $this->actingAs(User::factory()->create(['email' => 'dispatch-start@example.test']));

        DB::beginTransaction();
        try {
            $response = $this->postJson(route('chunks.start', $project), ['image_count' => 1])->assertCreated();
            $chunk = UploadChunk::query()->where('uuid', $response->json('id'))->sole();

            $payload = json_decode((string) DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(DiscardIncompleteUpload::class, $payload['displayName']);
            $jobId = DB::table('jobs')->value('id');
        } finally {
            DB::rollBack();
        }

        $this->assertDatabaseMissing('upload_chunks', ['uuid' => $response->json('id')]);
        $this->assertDatabaseMissing('jobs', ['id' => $jobId]);
    }

    public function test_description_is_enqueued_before_an_outer_transaction_commits(): void
    {
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $project = Project::factory()->create(['slug' => 'dispatch-store']);
        $this->actingAs(User::factory()->create(['email' => 'dispatch-store@example.test']));

        DB::beginTransaction();
        try {
            $response = $this->postJson(route('chunks.store', $project), ['files' => [UploadedFile::fake()->image('dispatch.png')]])->assertCreated();
            $image = UploadImage::query()->where('uuid', $response->json('images.0.id'))->sole();

            $payload = json_decode((string) DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(DescribeUploadImage::class, $payload['displayName']);
            $jobId = DB::table('jobs')->value('id');
        } finally {
            DB::rollBack();
        }

        $this->assertDatabaseMissing('upload_images', ['uuid' => $response->json('images.0.id')]);
        $this->assertDatabaseMissing('jobs', ['id' => $jobId]);
    }
}

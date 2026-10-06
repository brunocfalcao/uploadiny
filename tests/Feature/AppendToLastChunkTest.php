<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DescribeUploadImage;
use App\Jobs\DiscardIncompleteUpload;
use App\Project;
use App\Services\WorkspaceDeletion;
use App\UploadChunk;
use App\UploadImage;
use App\UploadinyTokenAbility;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppendToLastChunkTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Project, string} */
    private function prepare(): array
    {
        Storage::fake('local');
        Queue::fake([DescribeUploadImage::class, DiscardIncompleteUpload::class]);
        $project = Project::factory()->create(['slug' => 'append-project']);
        $token = User::factory()->create()->createToken('phone', UploadinyTokenAbility::phone())->plainTextToken;

        return [$project, $token];
    }

    private function share(Project $project, string $token, int $files, ?string $appendTo = null): array
    {
        $start = $this->withToken($token)->postJson(route('api.chunks.start', $project), array_filter(['image_count' => $files, 'append_to' => $appendTo]))->assertCreated();
        for ($index = 0; $index < $files; $index++) {
            $this->withToken($token)->postJson(route('api.chunks.append', $start->json('id')), ['file' => UploadedFile::fake()->image("later-{$index}.png"), 'comments' => "note {$index}"])->assertCreated();
        }

        return $this->withToken($token)->postJson(route('api.chunks.complete', $start->json('id')))->assertOk()->json();
    }

    public function test_the_phone_sees_which_upload_is_last_in_the_project(): void
    {
        [$project, $token] = $this->prepare();
        $this->withToken($token)->getJson(route('api.chunks.last', $project))->assertOk()->assertExactJson(['chunk' => null]);

        $first = $this->share($project, $token, 2);
        $this->travel(5)->minutes();
        $second = $this->share($project, $token, 1);

        $this->withToken($token)->getJson(route('api.chunks.last', $project))->assertOk()
            ->assertJsonPath('chunk.id', $second['id'])
            ->assertJsonPath('chunk.file_count', 1);
        $this->assertNotSame($first['id'], $second['id']);
    }

    public function test_a_later_share_joins_the_last_upload_and_moves_it_back_to_the_top(): void
    {
        [$project, $token] = $this->prepare();
        $older = $this->share($project, $token, 2);
        $this->travel(5)->minutes();
        $newer = $this->share($project, $token, 1);
        $this->travel(5)->minutes();

        $joined = $this->share($project, $token, 2, $older['id']);

        $this->assertSame($older['id'], $joined['id']);
        $this->assertCount(4, $joined['images']);
        $this->assertSame(['note 0', 'note 1'], array_slice(array_column($joined['images'], 'comments'), 2));
        $this->assertSame(2, UploadChunk::query()->where('status', 'complete')->count());
        $this->assertSame(0, UploadChunk::query()->where('status', 'uploading')->count());
        $this->withToken($token)->getJson(route('api.chunks.last', $project))->assertJsonPath('chunk.id', $older['id'])->assertJsonPath('chunk.file_count', 4);
        $agent = User::factory()->create()->createToken('agent', UploadinyTokenAbility::agent())->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken($agent)->getJson(route('api.feedback.latest', ['project' => $project->canonical]))->assertOk()
            ->assertJsonPath('chunk.id', $older['id'])->assertJsonCount(4, 'chunk.images');
        $this->assertNotSame($newer['id'], $joined['id']);
    }

    public function test_joining_an_upload_describes_only_the_newly_shared_images(): void
    {
        [$project, $token] = $this->prepare();
        $older = $this->share($project, $token, 1);
        $olderImageId = UploadChunk::query()->where('uuid', $older['id'])->firstOrFail()->images()->value('id');
        Queue::fake([DescribeUploadImage::class, DiscardIncompleteUpload::class]);

        $joined = $this->share($project, $token, 2, $older['id']);

        $newIds = UploadImage::query()->whereKeyNot($olderImageId)->pluck('id')->all();
        $this->assertCount(3, $joined['images']);
        Queue::assertPushed(DescribeUploadImage::class, 2);
        Queue::assertPushed(DescribeUploadImage::class, fn (DescribeUploadImage $job): bool => in_array($job->imageId, $newIds, true));
        Queue::assertNotPushed(DescribeUploadImage::class, fn (DescribeUploadImage $job): bool => $job->imageId === $olderImageId);
    }

    public function test_an_unfinished_share_never_touches_the_upload_it_meant_to_join(): void
    {
        [$project, $token] = $this->prepare();
        $older = $this->share($project, $token, 1);
        $before = UploadImage::query()->orderBy('id')->get()->map->getAttributes()->all();
        $start = $this->withToken($token)->postJson(route('api.chunks.start', $project), ['image_count' => 2, 'append_to' => $older['id']])->assertCreated();
        $this->withToken($token)->postJson(route('api.chunks.append', $start->json('id')), ['file' => UploadedFile::fake()->image('only-one.png')])->assertCreated();

        $this->withToken($token)->postJson(route('api.chunks.complete', $start->json('id')))->assertConflict();
        $this->withToken($token)->deleteJson(route('api.chunks.cancel', $start->json('id')))->assertOk();

        $this->assertSame($before, UploadImage::query()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame(1, UploadChunk::query()->where('uuid', $older['id'])->firstOrFail()->images()->count());
    }

    public function test_a_share_becomes_a_new_upload_when_the_last_one_was_deleted_meanwhile(): void
    {
        [$project, $token] = $this->prepare();
        $older = $this->share($project, $token, 1);
        $start = $this->withToken($token)->postJson(route('api.chunks.start', $project), ['image_count' => 1, 'append_to' => $older['id']])->assertCreated();
        app(WorkspaceDeletion::class)->completedChunk($project, UploadChunk::query()->where('uuid', $older['id'])->firstOrFail());
        $this->withToken($token)->postJson(route('api.chunks.append', $start->json('id')), ['file' => UploadedFile::fake()->image('survivor.png')])->assertCreated();

        $result = $this->withToken($token)->postJson(route('api.chunks.complete', $start->json('id')))->assertOk()->json();

        $this->assertSame($start->json('id'), $result['id']);
        $this->assertCount(1, $result['images']);
        $this->assertNull(UploadChunk::query()->where('uuid', $older['id'])->first());
    }

    public function test_a_phone_cannot_join_an_upload_from_another_project_or_an_unfinished_one(): void
    {
        [$project, $token] = $this->prepare();
        $other = Project::factory()->create(['slug' => 'other-project']);
        $foreign = $this->share($other, $token, 1);
        $draft = $this->withToken($token)->postJson(route('api.chunks.start', $project), ['image_count' => 1])->assertCreated()->json('id');

        foreach ([$foreign['id'], $draft] as $target) {
            $start = $this->withToken($token)->postJson(route('api.chunks.start', $project), ['image_count' => 1, 'append_to' => $target])->assertCreated();
            $this->withToken($token)->postJson(route('api.chunks.append', $start->json('id')), ['file' => UploadedFile::fake()->image('isolated.png')])->assertCreated();
            $result = $this->withToken($token)->postJson(route('api.chunks.complete', $start->json('id')))->assertOk()->json();
            $this->assertSame($start->json('id'), $result['id']);
        }
        $this->assertSame(1, UploadChunk::query()->where('uuid', $foreign['id'])->firstOrFail()->images()->count());
        $this->withToken($token)->postJson(route('api.chunks.start', $project), ['image_count' => 1, 'append_to' => 'not-a-uuid'])->assertUnprocessable()->assertJsonValidationErrors('append_to');
    }

    public function test_agent_and_missing_credentials_cannot_read_the_last_upload_summary(): void
    {
        [$project] = $this->prepare();
        $this->getJson(route('api.chunks.last', $project))->assertUnauthorized();
        $agent = User::factory()->create()->createToken('agent', UploadinyTokenAbility::agent())->plainTextToken;
        $this->withToken($agent)->getJson(route('api.chunks.last', $project))->assertForbidden();
    }
}

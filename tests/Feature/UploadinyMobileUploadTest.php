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
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class UploadinyMobileUploadTest extends TestCase
{
    use RefreshDatabase;

    private function phoneToken(User $user, ?\DateTimeInterface $expiresAt = null): string
    {
        return $user->createToken('test-phone', UploadinyTokenAbility::phone(), $expiresAt)->plainTextToken;
    }

    /** @return array{Project, User} */
    private function prepare(): array
    {
        Storage::fake('local');
        Queue::fake([DescribeUploadImage::class, DiscardIncompleteUpload::class]);

        return [Project::factory()->create(['slug' => 'mobile-upload']), User::factory()->create()];
    }

    public function test_personal_iphone_can_upload_into_the_existing_collection(): void
    {
        [$project, $user] = $this->prepare();
        $token = $this->phoneToken($user);
        $this->withToken($token)->getJson('/api/projects')->assertOk()->assertJsonPath('projects.0.slug', 'mobile-upload');
        $response = $this->withToken($token)->postJson(route('api.chunks.store', $project), ['files' => [UploadedFile::fake()->image('shared.png'), UploadedFile::fake()->image('shared-two.png')]])->assertCreated()->assertJsonCount(2, 'images');
        $this->assertCount(2, $response->json('images'));
        Queue::assertPushed(DescribeUploadImage::class, 2);
    }

    public function test_server_continues_sequential_names_and_preserves_each_extension(): void
    {
        [$project, $user] = $this->prepare();
        $token = $this->phoneToken($user);
        Storage::disk('local')->put('.uploadiny-sequence', '7');
        $image = $this->withToken($token)->postJson(route('api.chunks.store', $project), ['files' => [UploadedFile::fake()->image('shared.JPG')]])->assertCreated()->assertJsonPath('images.0.name', 'upload-8.jpg');
        $first = UploadImage::where('uuid', $image->json('images.0.id'))->sole();
        Storage::disk('local')->assertExists($first->path);
        $this->withToken($token)->postJson(route('api.chunks.store', $project), ['files' => [UploadedFile::fake()->image('shared.PNG')]])->assertCreated()->assertJsonPath('images.0.name', 'upload-9.png');
        Queue::assertPushed(DescribeUploadImage::class, 2);
    }

    public function test_missing_incorrect_and_expired_phone_tokens_are_rejected(): void
    {
        [$project, $user] = $this->prepare();
        $expired = $this->phoneToken($user, now()->subMinute());
        $this->postJson(route('api.chunks.store', $project), ['files' => [UploadedFile::fake()->image('private.png')]])->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->withToken('incorrect-token')->postJson(route('api.chunks.store', $project), ['files' => [UploadedFile::fake()->image('private.png')]])->assertUnauthorized();
        $this->withToken($expired)->postJson(route('api.chunks.store', $project), ['files' => [UploadedFile::fake()->image('private.png')]])->assertUnauthorized();
        $this->assertSame([], Storage::disk('local')->allFiles('images'));
        Queue::assertNothingPushed();
    }

    public function test_mobile_upload_uses_the_existing_blocked_extension_rule(): void
    {
        [$project, $user] = $this->prepare();
        $this->withToken($this->phoneToken($user))->postJson(route('api.chunks.store', $project), ['files' => [UploadedFile::fake()->createWithContent('unsafe.php', '<?php echo 1;')]])->assertUnprocessable();
        $this->assertSame([], Storage::disk('local')->allFiles('images'));
        Queue::assertNothingPushed();
    }

    public function test_iphone_feedback_stays_with_each_asset_and_is_available_in_the_workspace_after_publication(): void
    {
        [$project, $user] = $this->prepare();
        $other = UploadImage::factory()->create(['comments' => 'Keep unrelated feedback']);
        $phone = $this->phoneToken($user);
        $draft = $this->withToken($phone)->postJson(route('api.chunks.start', $project), ['image_count' => 3])->assertCreated();
        $chunk = UploadChunk::where('uuid', $draft->json('id'))->sole();
        $this->assertSame('uploading', $chunk->status);
        $this->assertSame(0, $chunk->images()->count());
        $notes = ["The screen is too small.\nMake the buttons bigger. 👋", 'At 00:03 the button stops responding.', ''];
        $files = [UploadedFile::fake()->image('share-screen.png'), UploadedFile::fake()->createWithContent('share-recording.mp4', "\x00\x00\x00\x18ftypmp42".str_repeat("\x00", 128)), UploadedFile::fake()->image('share-no-note.png')];
        $ids = [];

        foreach ($files as $index => $file) {
            $response = $this->postJson(route('api.chunks.append', $chunk), [
                'file' => $file, 'comments' => $notes[$index],
                'annotations' => [['tool' => 'untrusted']], 'feedback_revision' => 99,
            ])->assertCreated()->assertJsonPath('received_images', $index + 1)->assertJsonPath('image.comments', $notes[$index])->assertJsonPath('image.revision', 0)->assertJsonPath('image.annotations', []);
            $ids[] = $response->json('image.id');
            $image = UploadImage::where('uuid', $ids[$index])->sole();
            $this->assertSame($chunk->id, $image->chunk_id);
            $this->assertSame($notes[$index], $image->comments);
        }
        $this->assertSame('uploading', $chunk->fresh()->status);
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('share-feedback-agent', UploadinyTokenAbility::agent())->plainTextToken)->getJson(route('api.projects.latest', $project))->assertJsonPath('chunk', null);
        $this->app['auth']->forgetGuards();
        $this->withToken($phone)->postJson(route('api.chunks.complete', $chunk))->assertOk()->assertJsonPath('images.0.comments', $notes[0])->assertJsonPath('images.1.comments', $notes[1])->assertJsonPath('images.2.comments', '');
        $this->assertSame('complete', $chunk->fresh()->status);
        $this->assertSame('Keep unrelated feedback', $other->fresh()->comments);

        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('share-published-agent', UploadinyTokenAbility::agent())->plainTextToken)->getJson(route('api.projects.latest', $project))->assertOk()->assertJsonPath('chunk.id', $chunk->uuid)->assertJsonPath('chunk.images.0.comments', $notes[0])->assertJsonPath('chunk.images.1.comments', $notes[1]);
        $first = UploadImage::where('uuid', $ids[0])->sole();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->actingAs($user)->getJson(route('images.show', $first))->assertOk()->assertJsonPath('comments', $notes[0]);
        $this->patchJson(route('images.update', $first), ['comments' => 'Updated in the workspace', 'annotations' => [], 'revision' => 0])->assertOk()->assertJsonPath('revision', 1);
        $this->assertSame('Updated in the workspace', $first->fresh()->comments);
        $this->assertSame($notes[1], UploadImage::where('uuid', $ids[1])->sole()->comments);
    }

    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(['   '])]
    public function test_blank_iphone_feedback_remains_optional(?string $comments): void
    {
        [$project, $user] = $this->prepare();
        $draft = $this->withToken($this->phoneToken($user))->postJson(route('api.chunks.start', $project), ['image_count' => 1])->assertCreated();
        $chunk = UploadChunk::where('uuid', $draft->json('id'))->sole();
        $this->assertSame(0, $chunk->images()->count());

        $this->postJson(route('api.chunks.append', $chunk), ['file' => UploadedFile::fake()->image('share-optional-note.png'), 'comments' => $comments])->assertCreated()->assertJsonPath('image.comments', '');

        $this->assertSame('', $chunk->images()->sole()->comments);
        $this->postJson(route('api.chunks.complete', $chunk))->assertOk()->assertJsonPath('images.0.comments', '');
    }

    public function test_older_clients_can_append_without_feedback_and_the_existing_limit_is_accepted(): void
    {
        [$project, $user] = $this->prepare();
        $draft = $this->withToken($this->phoneToken($user))->postJson(route('api.chunks.start', $project), ['image_count' => 2])->assertCreated();
        $chunk = UploadChunk::where('uuid', $draft->json('id'))->sole();
        $this->assertSame(0, $chunk->images()->count());
        $limit = str_repeat('é', 50000);

        $first = $this->postJson(route('api.chunks.append', $chunk), ['file' => UploadedFile::fake()->image('share-legacy.png')])->assertCreated()->assertJsonPath('image.comments', '');
        $second = $this->postJson(route('api.chunks.append', $chunk), ['file' => UploadedFile::fake()->image('share-limit.png'), 'comments' => $limit])->assertCreated()->assertJsonPath('image.comments', $limit);

        $this->assertSame('', UploadImage::where('uuid', $first->json('image.id'))->sole()->comments);
        $this->assertSame($limit, UploadImage::where('uuid', $second->json('image.id'))->sole()->comments);
    }

    #[TestWith([['unexpected']])]
    #[TestWith([42])]
    #[TestWith([50001])]
    public function test_invalid_iphone_feedback_is_rejected_before_an_asset_is_stored(array|int $input): void
    {
        [$project, $user] = $this->prepare();
        $draft = $this->withToken($this->phoneToken($user))->postJson(route('api.chunks.start', $project), ['image_count' => 1])->assertCreated();
        $chunk = UploadChunk::where('uuid', $draft->json('id'))->sole();
        $this->assertSame(0, $chunk->images()->count());
        $comments = $input === 50001 ? str_repeat('a', 50001) : $input;

        $this->postJson(route('api.chunks.append', $chunk), ['file' => UploadedFile::fake()->image('share-invalid-note.png'), 'comments' => $comments])->assertUnprocessable()->assertJsonValidationErrors('comments');

        $this->assertSame(0, $chunk->images()->count());
        $this->assertSame('uploading', $chunk->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles('images'));
        Queue::assertNotPushed(DescribeUploadImage::class);
    }

    public function test_agent_and_missing_tokens_cannot_append_feedback_or_change_existing_notes(): void
    {
        [$project, $user] = $this->prepare();
        $draft = $this->withToken($this->phoneToken($user))->postJson(route('api.chunks.start', $project), ['image_count' => 2])->assertCreated();
        $chunk = UploadChunk::where('uuid', $draft->json('id'))->sole();
        $uploaded = $this->postJson(route('api.chunks.append', $chunk), ['file' => UploadedFile::fake()->image('share-protected.png'), 'comments' => 'Keep this note'])->assertCreated();
        $image = UploadImage::where('uuid', $uploaded->json('image.id'))->sole();
        $this->assertSame('Keep this note', $image->comments);

        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('share-read-only-agent', UploadinyTokenAbility::agent())->plainTextToken)->postJson(route('api.chunks.append', $chunk), ['file' => UploadedFile::fake()->image('share-forbidden.png'), 'comments' => 'Must not be saved'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->postJson(route('api.chunks.append', $chunk), ['file' => UploadedFile::fake()->image('share-guest.png'), 'comments' => 'Must not be saved'])->assertUnauthorized();

        $this->assertSame(1, $chunk->images()->count());
        $this->assertSame('Keep this note', $image->fresh()->comments);
    }

    public function test_published_asset_feedback_cannot_be_replaced_by_another_append(): void
    {
        [$project, $user] = $this->prepare();
        $draft = $this->withToken($this->phoneToken($user))->postJson(route('api.chunks.start', $project), ['image_count' => 1])->assertCreated();
        $chunk = UploadChunk::where('uuid', $draft->json('id'))->sole();
        $response = $this->postJson(route('api.chunks.append', $chunk), ['file' => UploadedFile::fake()->image('share-published.png'), 'comments' => 'Keep published feedback'])->assertCreated();
        $image = UploadImage::where('uuid', $response->json('image.id'))->sole();
        $this->postJson(route('api.chunks.complete', $chunk))->assertOk();
        $this->assertSame('Keep published feedback', $image->fresh()->comments);

        $this->postJson(route('api.chunks.append', $chunk), ['file' => UploadedFile::fake()->image('share-extra.png'), 'comments' => 'Must not replace feedback'])->assertConflict();

        $this->assertSame(1, $chunk->images()->count());
        $this->assertSame('Keep published feedback', $image->fresh()->comments);
        Storage::disk('local')->assertExists($image->path);
    }
}

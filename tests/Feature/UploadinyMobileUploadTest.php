<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DescribeUploadImage;
use App\Project;
use App\UploadImage;
use App\UploadinyTokenAbility;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
        Queue::fake([DescribeUploadImage::class]);

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
}

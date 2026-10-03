<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DescribeUploadImage;
use App\Project;
use App\UploadImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadinyMobileUploadTest extends TestCase
{
    use RefreshDatabase;

    private const UPLOAD_TOKEN = 'test-personal-device-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.uploadiny.upload_token' => self::UPLOAD_TOKEN]);
    }

    private function prepare(): Project
    {
        Storage::fake('local');
        Queue::fake([DescribeUploadImage::class]);

        return Project::factory()->create(['slug' => 'mobile-upload']);
    }

    public function test_personal_iphone_can_upload_into_the_existing_collection(): void
    {
        $project = $this->prepare();
        $this->withHeader('Authorization', 'Bearer '.self::UPLOAD_TOKEN)->getJson('/api/projects')->assertOk()->assertJsonPath('projects.0.slug', 'mobile-upload');
        $response = $this->postJson(route('api.chunks.store', $project), ['files' => [UploadedFile::fake()->image('shared.png'), UploadedFile::fake()->image('shared-two.png')]])->assertCreated()->assertJsonCount(2, 'images');
        $this->getJson(route('api.projects.latest', $project))->assertJsonPath('chunk.id', $response->json('id'))->assertJsonCount(2, 'chunk.images');
        Queue::assertPushed(DescribeUploadImage::class, 2);
    }

    public function test_server_continues_sequential_names_and_preserves_each_extension(): void
    {
        $project = $this->prepare();
        Storage::disk('local')->put('.uploadiny-sequence', '7');
        $image = $this->withHeader('Authorization', 'Bearer '.self::UPLOAD_TOKEN)->postJson(route('api.chunks.store', $project), ['files' => [UploadedFile::fake()->image('shared.JPG')]])->assertCreated()->assertJsonPath('images.0.name', 'upload-8.jpg');
        $first = UploadImage::where('uuid', $image->json('images.0.id'))->sole();
        Storage::disk('local')->assertExists($first->path);
        $this->postJson(route('api.chunks.store', $project), ['files' => [UploadedFile::fake()->image('shared.PNG')]])->assertCreated()->assertJsonPath('images.0.name', 'upload-9.png');
        Queue::assertPushed(DescribeUploadImage::class, 2);
    }

    public function test_missing_or_incorrect_personal_device_token_is_rejected(): void
    {
        $project = $this->prepare();
        $this->postJson(route('api.chunks.store', $project), ['files' => [UploadedFile::fake()->image('private.png')]])->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->withHeader('Authorization', 'Bearer incorrect-token')->postJson(route('api.chunks.store', $project), ['files' => [UploadedFile::fake()->image('private.png')]])->assertUnauthorized();
        $this->assertSame([], Storage::disk('local')->allFiles('images'));
        Queue::assertNothingPushed();
    }

    public function test_mobile_upload_is_closed_when_no_server_token_is_configured(): void
    {
        $project = $this->prepare();
        config(['services.uploadiny.upload_token' => null]);
        $this->withHeader('Authorization', 'Bearer '.self::UPLOAD_TOKEN)->postJson(route('api.chunks.store', $project), ['files' => [UploadedFile::fake()->image('private.png')]])->assertUnauthorized();
        $this->assertSame([], Storage::disk('local')->allFiles('images'));
        Queue::assertNothingPushed();
    }

    public function test_mobile_upload_uses_the_existing_blocked_extension_rule(): void
    {
        $project = $this->prepare();
        $this->withHeader('Authorization', 'Bearer '.self::UPLOAD_TOKEN)->postJson(route('api.chunks.store', $project), ['files' => [UploadedFile::fake()->createWithContent('unsafe.php', '<?php echo 1;')]])->assertUnprocessable();
        $this->assertSame([], Storage::disk('local')->allFiles('images'));
        Queue::assertNothingPushed();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DescribeUploadImage;
use App\Project;
use App\UploadImage;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadFlowTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(): Project
    {
        Storage::fake('local');
        Queue::fake([DescribeUploadImage::class]);
        $this->actingAs(User::factory()->create(['email' => 'upload-owner@example.test']));

        return Project::factory()->create(['slug' => 'upload-flow']);
    }

    public function test_file_can_be_uploaded_downloaded_and_deleted_by_its_returned_url(): void
    {
        $project = $this->prepare();
        $file = UploadedFile::fake()->image('Screenshot.PNG', 30, 20);
        $contents = $file->getContent();
        $response = $this->postJson(route('chunks.store', $project), ['files' => [$file]])->assertCreated()->assertJsonPath('images.0.name', 'upload-1.png');
        $image = UploadImage::where('uuid', $response->json('images.0.id'))->sole();
        $this->assertSame($contents, Storage::disk('local')->get($image->path));
        $this->get(route('images.download', $image))->assertDownload('upload-1.png');
        $this->deleteJson(route('images.destroy', $image))->assertOk()->assertExactJson(['message' => 'Image deleted.']);
        Storage::disk('local')->assertMissing($image->path);
        $this->get(route('images.download', $image))->assertNotFound();
        $next = $this->postJson(route('chunks.store', $project), ['files' => [UploadedFile::fake()->image('next.png')]])->assertCreated()->assertJsonPath('images.0.name', 'upload-2.png');
        $this->assertNotSame($response->json('id'), $next->json('id'));
        Queue::assertPushed(DescribeUploadImage::class, 2);
    }

    public function test_blocked_executable_is_rejected_without_storing_a_file(): void
    {
        $project = $this->prepare();
        $this->postJson(route('chunks.store', $project), ['files' => [UploadedFile::fake()->createWithContent('unsafe.php', '<?php echo 1;')]])->assertUnprocessable()->assertJsonValidationErrors('files.0');
        $this->assertSame([], Storage::disk('local')->allFiles('images'));
        Queue::assertNothingPushed();
    }

    public function test_missing_file_and_unknown_uuid_are_rejected(): void
    {
        $project = $this->prepare();
        $this->postJson(route('chunks.store', $project))->assertUnprocessable()->assertJsonValidationErrors('files');
        $this->get('/images/00000000-0000-4000-8000-000000000000/download')->assertNotFound();
        $this->deleteJson('/images/00000000-0000-4000-8000-000000000000')->assertNotFound();
        Queue::assertNothingPushed();
    }
}

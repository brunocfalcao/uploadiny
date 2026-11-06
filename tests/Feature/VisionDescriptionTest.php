<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DescribeUploadImage;
use App\Services\VisionDescription;
use App\Services\VisionFailure;
use App\UploadImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VisionDescriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_handoff_preserves_originals_and_does_not_send_owner_feedback_or_api_requests(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $image = UploadImage::factory()->create(['original_name' => 'vision-private.png', 'comments' => 'PRIVATE owner instruction', 'annotations' => [['text' => 'PRIVATE annotation']]]);
        $original = UploadedFile::fake()->image('vision-private.png', 2000, 1000)->getContent();
        Storage::disk('local')->put($image->path, $original);
        Process::fake(['*' => Process::result(output: '{"description":"A Save button.","model":"gpt-6-luna"}')]);
        $this->assertNull($image->description);
        $this->assertSame('pending', $image->description_status);

        (new DescribeUploadImage($image->id))->handle(app(VisionDescription::class));

        $image->refresh();
        $this->assertSame(['A Save button.', 'ready', 'gpt-6-luna', null], [$image->description, $image->description_status, $image->description_model, $image->description_error]);
        $this->assertSame('PRIVATE owner instruction', $image->comments);
        $this->assertSame([['text' => 'PRIVATE annotation']], $image->annotations);
        $this->assertSame($original, Storage::disk('local')->get($image->path));
        Process::assertRan(function ($process): bool {
            $dimensions = getimagesizefromstring($process->input);

            return $process->command === ['/usr/bin/sudo', '-n', '-H', '-u', 'olloma-agent', '/usr/local/libexec/uploadiny-vision.py']
                && $process->timeout === 80
                && [$dimensions[0], $dimensions[1], $dimensions['mime']] === [1536, 768, 'image/jpeg']
                && ! str_contains($process->input, 'PRIVATE');
        });
        Http::assertNothingSent();
    }

    public function test_malformed_empty_and_wrong_model_answers_leave_the_image_retryable_without_private_errors(): void
    {
        Storage::fake('local');
        $original = UploadedFile::fake()->image('vision-malformed.png', 20, 20)->getContent();
        foreach (['PRIVATE invalid JSON', '{}', '{"description":"   ","model":"gpt-6-luna"}', '{"description":"Wrong route","model":"gpt-6-astra"}'] as $index => $output) {
            $image = UploadImage::factory()->create(['original_name' => "vision-malformed-{$index}.png"]);
            Storage::disk('local')->put($image->path, $original);
            Process::fake(['*' => Process::result(output: $output)]);
            $this->assertSame('pending', $image->description_status);

            (new DescribeUploadImage($image->id))->handle(app(VisionDescription::class));

            $image->refresh();
            $this->assertSame('failed', $image->description_status);
            $this->assertNull($image->description);
            $this->assertSame('The vision provider returned an invalid description.', $image->description_error);
            $this->assertSame($original, Storage::disk('local')->get($image->path));
        }
    }

    public function test_unpreparable_images_and_missing_configuration_never_start_a_process(): void
    {
        Storage::fake('local');
        Process::fake();
        $image = UploadImage::factory()->create(['original_name' => 'vision-invalid.png']);
        Storage::disk('local')->put($image->path, 'not an image');
        $before = $image->fresh()->getAttributes();
        try {
            app(VisionDescription::class)->describe($image);
            $this->fail('Invalid images must be refused.');
        } catch (VisionFailure $error) {
            $this->assertSame('unsupported_image', $error->category);
        }
        config(['services.uploadiny.vision_binary' => '']);
        try {
            app(VisionDescription::class)->describe($image);
            $this->fail('Missing configuration must be refused.');
        } catch (VisionFailure $error) {
            $this->assertSame('missing_configuration', $error->category);
        }

        $this->assertSame($before, $image->fresh()->getAttributes());
        $this->assertSame('not an image', Storage::disk('local')->get($image->path));
        Process::assertNothingRan();
    }
}

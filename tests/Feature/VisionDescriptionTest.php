<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DescribeUploadImage;
use App\Services\VisionDescription;
use App\Services\VisionFailure;
use App\UploadImage;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VisionDescriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_attempt_migration_preserves_existing_rows_and_serialized_legacy_jobs(): void
    {
        $migration = require database_path('migrations/2026_11_09_180000_add_description_attempt_to_upload_images.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('upload_images', 'description_attempt'));
        $image = UploadImage::factory()->create(['original_name' => 'legacy-attempt.png', 'comments' => 'Keep this feedback']);
        $legacy = new DescribeUploadImage($image->id);
        unset($legacy->attemptId);
        $payload = serialize($legacy);
        $this->assertStringNotContainsString('attemptId', $payload);

        $migration->up();
        $this->assertTrue(Schema::hasColumn('upload_images', 'description_attempt'));
        $this->assertSame('pending', $image->fresh()->description_status);
        $this->assertNull($image->fresh()->description_attempt);
        $restored = unserialize($payload);
        $vision = \Mockery::mock(VisionDescription::class);
        $vision->shouldReceive('describe')->once()->andReturn('Legacy job result');
        $restored->handle($vision);
        $this->assertSame('ready', $image->fresh()->description_status);
        $this->assertSame('Legacy job result', $image->fresh()->description);
        $this->assertSame('Keep this feedback', $image->fresh()->comments);
    }

    public function test_large_oriented_previews_fit_a_bounded_worker_and_insufficient_memory_fails_safely(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'vision-memory-');
        $source = imagecreatetruecolor(5000, 4000);
        imagefill($source, 0, 0, imagecolorallocate($source, 240, 20, 20));
        imagefilledrectangle($source, 0, 2000, 2499, 3999, imagecolorallocate($source, 20, 20, 240));
        ob_start();
        imagejpeg($source, null, 90);
        $jpeg = (string) ob_get_clean();
        unset($source);
        try {
            foreach ([1, 3, 5, 6, 7, 8] as $orientation) {
                $tiff = 'II'.pack('v', 42).pack('V', 8).pack('v', 1).pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation).pack('v', 0).pack('V', 0);
                $exif = "Exif\0\0".$tiff;
                $original = substr($jpeg, 0, 2).pack('CCn', 255, 225, strlen($exif) + 2).$exif.substr($jpeg, 2);
                file_put_contents($path, $original);
                $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'memory_limit=128M', base_path('tests/Fixtures/vision-memory.php'), $path]);
                $process->mustRun();
                $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($orientation >= 5 ? [1229, 1536] : [1536, 1229], [$result['width'], $result['height']]);
                $this->assertGreaterThan(200, $result['corner'][$orientation === 6 ? 'blue' : 'red']);
                $this->assertSame($original, file_get_contents($path));
            }
            $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'memory_limit=64M', base_path('tests/Fixtures/vision-memory.php'), $path]);
            $process->mustRun();
            $this->assertSame(['failure' => 'unsupported_image'], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            unlink($path);
        }
    }

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

    public function test_late_failure_preserves_successful_results_and_only_fails_unfinished_images(): void
    {
        foreach (['ready', 'pending', 'processing', 'not_applicable'] as $status) {
            $image = UploadImage::factory()->create(['original_name' => 'late-'.$status.'.png', 'description_status' => $status, 'description' => 'Saved result', 'description_error' => null]);
            $this->assertSame($status, $image->fresh()->description_status);
            (new DescribeUploadImage($image->id))->failed(new \RuntimeException('private failure'));
            $expected = in_array($status, ['pending', 'processing'], true) ? 'failed' : $status;
            $this->assertSame($expected, $image->fresh()->description_status);
            $this->assertSame('Saved result', $image->fresh()->description);
            $this->assertSame($expected === 'failed' ? 'The description could not finish. Try again later.' : null, $image->fresh()->description_error);
        }
        (new DescribeUploadImage(999999))->failed(null);
    }

    public function test_old_failure_cannot_overwrite_a_new_owner_requested_attempt(): void
    {
        Queue::fake();
        $image = UploadImage::factory()->create(['original_name' => 'attempt-fence.png', 'description_status' => 'ready', 'description' => 'Previous result']);
        $old = new DescribeUploadImage($image->id);
        $this->actingAs(User::factory()->create(['email' => 'attempt-fence@example.test']));
        $this->postJson(route('images.describe', $image))->assertOk()->assertJsonPath('description_status', 'pending');
        $old->failed(null);
        $this->assertSame('pending', $image->fresh()->description_status);
        $queued = Queue::pushed(DescribeUploadImage::class)->sole();
        $vision = \Mockery::mock(VisionDescription::class);
        $vision->shouldReceive('describe')->once()->andReturnUsing(function () use ($old, $image) {
            $this->assertSame('processing', $image->fresh()->description_status);
            $old->failed(null);
            $this->assertSame('processing', $image->fresh()->description_status);

            return 'New result';
        });
        $queued->handle($vision);
        $queued->failed(null);
        $this->assertSame('ready', $image->fresh()->description_status);
        $this->assertSame('New result', $image->fresh()->description);
        $this->postJson(route('images.describe', $image))->assertOk()->assertJsonPath('description_status', 'pending');
        $queued->failed(null);
        $this->assertSame('pending', $image->fresh()->description_status);
        Queue::pushed(DescribeUploadImage::class)->last()->failed(null);
        $this->assertSame('failed', $image->fresh()->description_status);
    }

    public function test_late_provider_result_cannot_complete_a_replacement_attempt(): void
    {
        Queue::fake();
        $image = UploadImage::factory()->create(['original_name' => 'stale-result.png', 'description_status' => 'ready', 'description' => 'Saved result']);
        $this->actingAs(User::factory()->create(['email' => 'stale-result@example.test']));
        $this->postJson(route('images.describe', $image))->assertOk();
        $old = Queue::pushed(DescribeUploadImage::class)->sole();
        $vision = \Mockery::mock(VisionDescription::class);
        $vision->shouldReceive('describe')->once()->andReturnUsing(function () use ($old, $image) {
            $this->assertSame('processing', $image->fresh()->description_status);
            $old->failed(null);
            $this->postJson(route('images.describe', $image))->assertOk()->assertJsonPath('description_status', 'pending');

            return 'Stale result';
        });
        $old->handle($vision);
        $old->handle($vision);
        $this->assertSame('pending', $image->fresh()->description_status);
        $this->assertSame('Saved result', $image->fresh()->description);
    }

    public function test_large_rotated_photos_are_prepared_with_bounded_memory_and_originals_preserved(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'vision-memory-');
        $canvas = imagecreatetruecolor(5000, 4000);
        imagejpeg($canvas, $path, 85);
        unset($canvas);
        $jpeg = file_get_contents($path);
        try {
            foreach ([1, 3, 5, 6, 7, 8] as $orientation) {
                $tiff = 'II'.pack('v', 42).pack('V', 8).pack('v', 1)
                    .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation).pack('v', 0).pack('V', 0);
                $payload = "Exif\0\0".$tiff;
                $bytes = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
                file_put_contents($path, $bytes);
                $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'memory_limit=128M', base_path('tests/Fixtures/vision-memory.php'), $path]);
                $process->run();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($orientation >= 5 ? [1229, 1536] : [1536, 1229], [$result['width'], $result['height']], 'Orientation '.$orientation);
                $this->assertSame($bytes, file_get_contents($path));
            }
            $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'memory_limit=64M', base_path('tests/Fixtures/vision-memory.php'), $path]);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame(['failure' => 'unsupported_image'], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            unlink($path);
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

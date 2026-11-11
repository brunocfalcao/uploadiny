<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CorrectUploadComments;
use App\Jobs\DescribeUploadImage;
use App\Jobs\DiscardIncompleteUpload;
use App\Project;
use App\Services\CommentCorrection;
use App\Services\VisionFailure;
use App\UploadChunk;
use App\UploadImage;
use App\UploadinyTokenAbility;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CommentCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_correction_runs_through_the_sudo_bridge_with_text_on_stdin_only(): void
    {
        Process::fake(['*' => Process::result(output: '{"text":"Cantonese menu doesn\'t load.","model":"gpt-6-luna"}')]);

        $corrected = app(CommentCorrection::class)->correct('Canton menu doesnt load.', 'Restaurant app', 'Ordering flow');

        $this->assertSame("Cantonese menu doesn't load.", $corrected);
        Process::assertRan(function ($process): bool {
            $payload = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);

            return $process->command === ['/usr/bin/sudo', '-n', '-H', '-u', 'olloma-agent', '/usr/local/libexec/uploadiny-correct.py']
                && $process->timeout === 80
                && $payload === ['feedback' => 'Canton menu doesnt load.', 'project' => ['name' => 'Restaurant app', 'description' => 'Ordering flow']];
        });
    }

    #[TestWith(['PRIVATE invalid JSON'])]
    #[TestWith(['{}'])]
    #[TestWith(['{"text":"   ","model":"gpt-6-luna"}'])]
    #[TestWith(['{"text":"Menu fixed.","model":"gpt-6-astra"}'])]
    #[TestWith(['{"text":"Menu\\u0000 fixed.","model":"gpt-6-luna"}'])]
    #[TestWith(['{"text":"Menu fixed with a much longer invented sentence about unrelated product areas that the owner never said.","model":"gpt-6-luna"}'])]
    public function test_malformed_empty_wrong_model_and_implausible_answers_fail_closed(string $output): void
    {
        Process::fake(['*' => Process::result(output: $output)]);

        try {
            app(CommentCorrection::class)->correct('Menu doesnt load.', null, null);
            $this->fail('Invalid correction must be refused.');
        } catch (VisionFailure $error) {
            $this->assertContains($error->category, ['malformed_response', 'implausible_correction']);
            $this->assertStringNotContainsString('PRIVATE', $error->getMessage());
        }
    }

    public function test_bridge_refusal_fails_closed(): void
    {
        Process::fake(['*' => Process::result(output: 'PRIVATE gateway detail', exitCode: 1)]);

        try {
            app(CommentCorrection::class)->correct('Menu doesnt load.', null, null);
            $this->fail('A failed bridge run must be refused.');
        } catch (VisionFailure $error) {
            $this->assertSame('provider_refusal', $error->category);
            $this->assertStringNotContainsString('PRIVATE', $error->getMessage());
        }
    }

    public function test_missing_configuration_never_starts_a_process(): void
    {
        Process::fake();
        config(['services.uploadiny.comment_correction_binary' => '']);

        try {
            app(CommentCorrection::class)->correct('Menu doesnt load.', null, null);
            $this->fail('Missing configuration must be refused.');
        } catch (VisionFailure $error) {
            $this->assertSame('missing_configuration', $error->category);
        }
        Process::assertNothingRan();
    }

    public function test_job_does_not_call_the_bridge_when_comments_were_edited_after_queueing(): void
    {
        Process::fake();
        $image = UploadImage::factory()->create(['comments' => 'Website edit wins', 'feedback_revision' => 1]);

        (new CorrectUploadComments($image->id, 'Dictated text', 0))->handle(app(CommentCorrection::class));

        Process::assertNothingRan();
        $this->assertSame(['Website edit wins', 1], [$image->fresh()->comments, $image->fresh()->feedback_revision]);
    }

    public function test_job_leaves_feedback_untouched_when_the_bridge_fails(): void
    {
        Process::fake(['*' => Process::result(output: 'PRIVATE gateway detail', exitCode: 1)]);
        $image = UploadImage::factory()->create(['comments' => 'Canton menu doesnt load.', 'feedback_revision' => 0]);

        (new CorrectUploadComments($image->id, 'Canton menu doesnt load.', 0))->handle(app(CommentCorrection::class));

        $image->refresh();
        $this->assertSame(['Canton menu doesnt load.', 0, 'pending'], [$image->comments, $image->feedback_revision, $image->description_status]);
    }

    public function test_successful_correction_replaces_feedback_and_bumps_the_revision(): void
    {
        Process::fake(['*' => Process::result(output: '{"text":"Canton menu doesn\'t load.","model":"gpt-6-luna"}')]);
        $image = UploadImage::factory()->create(['comments' => 'Cantonese menu doesnt load.', 'feedback_revision' => 0]);

        (new CorrectUploadComments($image->id, 'Cantonese menu doesnt load.', 0))->handle(app(CommentCorrection::class));

        $image->refresh();
        $this->assertSame(["Canton menu doesn't load.", 1], [$image->comments, $image->feedback_revision]);
        $this->assertNotNull($image->feedback_updated_at);
    }

    public function test_unchanged_correction_writes_nothing(): void
    {
        Process::fake(['*' => Process::result(output: '{"text":"Menu does not load.","model":"gpt-6-luna"}')]);
        $image = UploadImage::factory()->create(['comments' => 'Menu does not load.', 'feedback_revision' => 0]);

        (new CorrectUploadComments($image->id, 'Menu does not load.', 0))->handle(app(CommentCorrection::class));

        $this->assertSame(0, $image->fresh()->feedback_revision);
    }

    public function test_phone_feedback_queues_a_correction_but_website_and_blank_feedback_do_not(): void
    {
        Storage::fake('local');
        Queue::fake([CorrectUploadComments::class, DescribeUploadImage::class, DiscardIncompleteUpload::class]);
        $user = User::factory()->create();
        $project = Project::factory()->create(['slug' => 'dictation']);
        $token = $user->createToken('phone', UploadinyTokenAbility::phone())->plainTextToken;

        $phone = $this->withToken($token)->postJson(route('api.chunks.start', $project), ['image_count' => 2])->assertCreated()->json('id');
        $this->withToken($token)->postJson(route('api.chunks.append', $phone), ['file' => UploadedFile::fake()->image('a.png'), 'comments' => 'Cantonese doesnt appear'])->assertCreated();
        $this->withToken($token)->postJson(route('api.chunks.append', $phone), ['file' => UploadedFile::fake()->image('b.png'), 'comments' => '  '])->assertCreated();

        Queue::assertPushed(CorrectUploadComments::class, 1);
        Queue::assertPushed(CorrectUploadComments::class, fn (CorrectUploadComments $job): bool => $job->originalComments === 'Cantonese doesnt appear' && $job->feedbackRevision === 0);

        $this->app['auth']->forgetGuards();
        $draft = UploadChunk::factory()->create(['status' => 'uploading', 'upload_project_id' => $project->id, 'expected_images' => 1]);
        $this->actingAs($user)->postJson(route('chunks.append', $draft), ['file' => UploadedFile::fake()->image('c.png'), 'comments' => 'Website text stays'])->assertCreated();

        Queue::assertPushed(CorrectUploadComments::class, 1);
    }

    public function test_job_skips_blank_feedback_and_missing_images_without_a_bridge_call(): void
    {
        Process::fake();
        $image = UploadImage::factory()->create(['comments' => '   ', 'feedback_revision' => 0]);

        (new CorrectUploadComments($image->id, '   ', 0))->handle(app(CommentCorrection::class));
        (new CorrectUploadComments(999999, 'Dictated text', 0))->handle(app(CommentCorrection::class));

        Process::assertNothingRan();
    }
}

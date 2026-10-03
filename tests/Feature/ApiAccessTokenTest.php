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
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ApiAccessTokenTest extends TestCase
{
    use RefreshDatabase;

    private function withFreshToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->withToken($token);
    }

    private function phoneToken(User $user, ?\DateTimeInterface $expiresAt = null): string
    {
        return $user->createToken('test-phone', UploadinyTokenAbility::phone(), $expiresAt)->plainTextToken;
    }

    private function agentToken(User $user, ?\DateTimeInterface $expiresAt = null): string
    {
        return $user->createToken('test-agent', UploadinyTokenAbility::agent(), $expiresAt)->plainTextToken;
    }

    public function test_api_requires_a_real_bearer_token_and_rejects_the_old_static_key(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $this->getJson(route('api.projects.index'))->assertUnauthorized();
        $this->withToken('legacy-static-device-token')->getJson(route('api.projects.index'))->assertUnauthorized();
        $this->actingAs($user)->getJson(route('api.projects.index'))->assertUnauthorized();
    }

    public function test_phone_token_lists_projects_and_uploads_but_cannot_read_feedback_or_private_files(): void
    {
        Storage::fake('local');
        Queue::fake([DescribeUploadImage::class]);
        $user = User::factory()->create();
        $project = Project::factory()->create(['slug' => 'phone-scope']);
        $existing = UploadImage::factory()->create(['project_id' => $project->id]);
        Storage::disk('local')->put($existing->path, 'private-image');
        $token = $this->phoneToken($user);

        $this->withToken($token)->getJson(route('api.projects.index'))->assertOk()->assertJsonPath('projects.0.slug', 'phone-scope');
        $this->withToken($token)->getJson(route('api.projects.latest', $project))->assertForbidden();
        $this->withToken($token)->get(route('api.images.download', $existing))->assertForbidden();
        $this->withToken($token)->get(route('api.images.annotated', $existing))->assertForbidden();
        $this->withToken($token)->postJson(route('api.chunks.store', $project), ['files' => [UploadedFile::fake()->image('phone.png')]])->assertCreated()->assertJsonCount(1, 'images');
        Queue::assertPushed(DescribeUploadImage::class);
    }

    public function test_agent_token_reads_feedback_and_private_files_but_cannot_upload_or_cancel(): void
    {
        Storage::fake('local');
        Queue::fake([DescribeUploadImage::class]);
        $user = User::factory()->create();
        $project = Project::factory()->create(['slug' => 'agent-scope']);
        $image = UploadImage::factory()->create(['project_id' => $project->id]);
        Storage::disk('local')->put($image->path, 'private-image');
        $token = $this->agentToken($user);

        $this->withToken($token)->getJson(route('api.projects.latest', $project))->assertOk();
        $download = $this->withToken($token)->get(route('api.images.download', $image))->assertDownload($image->name)->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', (string) $download->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        $this->withToken($token)->postJson(route('api.chunks.store', $project), ['files' => [UploadedFile::fake()->image('forbidden.png')]])->assertForbidden();
        $this->withToken($token)->deleteJson(route('api.chunks.cancel', $image->chunk))->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_revoked_and_expired_tokens_are_rejected(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $revoked = $this->phoneToken($user);
        $user->tokens()->firstOrFail()->delete();
        $expired = $this->phoneToken($user, now()->subMinute());

        $this->withToken($revoked)->getJson(route('api.projects.index'))->assertUnauthorized();
        $this->withToken($expired)->getJson(route('api.projects.index'))->assertUnauthorized();
    }

    public function test_device_sign_in_replaces_only_its_prior_phone_token_and_keeps_the_website_session_active(): void
    {
        $user = User::factory()->create(['email' => 'iphone@example.test']);
        $agentToken = $this->agentToken($user);
        $this->post('/login', ['email' => $user->email, 'password' => 'test-password-123'])->assertRedirect('/');

        $first = $this->postJson(route('api.device-tokens.store'), [
            'email' => $user->email,
            'password' => 'test-password-123',
            'abilities' => UploadinyTokenAbility::agent(),
        ])->assertCreated()->assertJsonStructure(['token', 'expires_at'])->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');

        $second = $this->postJson(route('api.device-tokens.store'), [
            'email' => $user->email,
            'password' => 'test-password-123',
        ])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->get('/')->assertOk();
        $this->assertSame(1, $user->tokens()->where('name', 'iphone-share-extension')->count());
        $this->assertSame(1, $user->tokens()->where('name', 'test-agent')->count());
        $this->assertSame(UploadinyTokenAbility::phone(), $user->tokens()->where('name', 'iphone-share-extension')->sole()->abilities);
        $this->assertSame(UploadinyTokenAbility::agent(), PersonalAccessToken::findToken($agentToken)?->abilities);
        $this->withFreshToken($first->json('token'))->getJson(route('api.projects.index'))->assertUnauthorized();
        $this->withFreshToken($second->json('token'))->getJson(route('api.projects.index'))->assertOk();
        $this->withFreshToken($second->json('token'))->getJson(route('api.projects.latest', Project::factory()->create()))->assertForbidden();
        $this->withFreshToken($agentToken)->getJson(route('api.projects.latest', Project::factory()->create()))->assertOk();
    }

    public function test_agent_command_rotates_only_the_agent_token_and_can_revoke_it(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $phoneToken = $this->phoneToken($user);

        $this->artisan('uploadiny:agent-token', ['--expires' => 14])->assertSuccessful();
        $first = Storage::disk('local')->get('credentials/uploadiny-agent-token.txt');
        $this->assertSame(1, $user->tokens()->where('name', 'coding-agent')->count());
        $this->assertSame(1, $user->tokens()->where('name', 'test-phone')->count());

        $this->artisan('uploadiny:agent-token', ['--expires' => 14])->assertSuccessful();
        $second = Storage::disk('local')->get('credentials/uploadiny-agent-token.txt');
        $this->assertNotSame($first, $second);
        $this->assertSame(1, $user->tokens()->where('name', 'coding-agent')->count());
        $this->withFreshToken($first)->getJson(route('api.projects.index'))->assertUnauthorized();
        $this->withFreshToken($second)->getJson(route('api.projects.index'))->assertOk();
        $this->withFreshToken($phoneToken)->getJson(route('api.projects.index'))->assertOk();

        $this->artisan('uploadiny:agent-token', ['--revoke' => true])->assertSuccessful();
        $this->assertSame(0, PersonalAccessToken::query()->where('name', 'coding-agent')->count());
        $this->withFreshToken($second)->getJson(route('api.projects.index'))->assertUnauthorized();
        $this->withFreshToken($phoneToken)->getJson(route('api.projects.index'))->assertOk();
    }

    public function test_failed_credentials_are_throttled_and_malformed_email_input_is_a_validation_error(): void
    {
        $user = User::factory()->create(['email' => 'throttle@example.test']);
        $server = ['REMOTE_ADDR' => '203.0.113.7'];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables($server)->postJson(route('api.device-tokens.store'), ['email' => ' THROTTLE@example.test ', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->withServerVariables($server)->postJson(route('api.device-tokens.store'), ['email' => 'throttle@example.test', 'password' => 'wrong'])->assertTooManyRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.8'])->postJson(route('api.device-tokens.store'), ['email' => ['not-an-email'], 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSame('throttle@example.test', $user->email);
    }

    public function test_failed_website_login_is_throttled_with_the_same_normalized_identity_key(): void
    {
        $user = User::factory()->create(['email' => 'web-throttle@example.test']);
        $server = ['REMOTE_ADDR' => '203.0.113.9'];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables($server)->post('/login', ['email' => ' WEB-THROTTLE@example.test ', 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->withServerVariables($server)->post('/login', ['email' => 'web-throttle@example.test', 'password' => 'wrong'])->assertTooManyRequests();

        $this->assertGuest();
        $this->assertSame('web-throttle@example.test', $user->email);
    }
}

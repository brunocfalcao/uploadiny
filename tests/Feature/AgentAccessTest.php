<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AgentAccess;
use App\UploadinyTokenAbility;
use App\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Mockery;
use Tests\TestCase;

class AgentAccessTest extends TestCase
{
    use RefreshDatabase;

    private function freshBearer(string $value): static
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->withToken($value);
    }

    public function test_key_management_requires_a_website_session(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'key-session@example.test']);
        $token = $user->createToken(AgentAccess::TOKEN_NAME, UploadinyTokenAbility::agent());
        $this->assertSame(1, $user->tokens()->where('name', AgentAccess::TOKEN_NAME)->count());

        $this->get(route('agent-access.show'))->assertRedirect(route('login'));
        $this->withToken($token->plainTextToken)->post(route('agent-access.store'))->assertRedirect(route('login'));
        $this->delete(route('agent-access.destroy'))->assertRedirect(route('login'));

        $this->assertSame(1, $user->tokens()->where('name', AgentAccess::TOKEN_NAME)->count());
        Storage::disk('local')->assertMissing('credentials/uploadiny-agent-token.txt');
    }

    public function test_owner_generates_a_nonexpiring_agent_key_and_can_copy_it_from_the_private_page(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'key-generate@example.test']);
        $this->assertSame(0, $user->tokens()->where('name', AgentAccess::TOKEN_NAME)->count());
        $this->actingAs($user)->get(route('agent-access.show'))->assertOk()->assertSee('No active API key');

        $this->post(route('agent-access.store'), ['abilities' => ['*'], 'expires_at' => now()->subDay()->toIso8601String()])->assertRedirect(route('agent-access.show'));

        $value = Storage::disk('local')->get('credentials/uploadiny-agent-token.txt');
        $token = PersonalAccessToken::findToken($value);
        $this->assertSame(AgentAccess::TOKEN_NAME, $token?->name);
        $this->assertSame(UploadinyTokenAbility::agent(), $token?->abilities);
        $this->assertNull($token?->expires_at);
        $this->assertSame(1, $user->tokens()->where('name', AgentAccess::TOKEN_NAME)->count());
        $this->assertSame(0600, fileperms(Storage::disk('local')->path('credentials/uploadiny-agent-token.txt')) & 0777);
        $this->get(route('agent-access.show'))->assertOk()->assertViewHas('apiKey', $value)->assertSee('Copy key')->assertHeader('Cache-Control', 'no-store, private');
        $this->freshBearer($value)->getJson(route('api.projects.index'))->assertOk();
    }

    public function test_rotation_rejects_the_old_key_and_preserves_phone_access(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'key-rotate@example.test']);
        $phone = $user->createToken('iphone-share-extension', UploadinyTokenAbility::phone());
        $this->assertTrue(app(AgentAccess::class)->rotate($user));
        $old = Storage::disk('local')->get('credentials/uploadiny-agent-token.txt');
        $this->freshBearer($old)->getJson(route('api.projects.index'))->assertOk();

        $this->flushHeaders()->actingAs($user)->post(route('agent-access.store'))->assertRedirect(route('agent-access.show'));

        $new = Storage::disk('local')->get('credentials/uploadiny-agent-token.txt');
        $this->assertNotSame($old, $new);
        $this->assertSame(1, $user->tokens()->where('name', AgentAccess::TOKEN_NAME)->count());
        $this->assertSame(UploadinyTokenAbility::phone(), $phone->accessToken->fresh()?->abilities);
        $this->freshBearer($old)->getJson(route('api.projects.index'))->assertUnauthorized();
        $this->freshBearer($new)->getJson(route('api.projects.index'))->assertOk();
        $this->freshBearer($phone->plainTextToken)->getJson(route('api.projects.index'))->assertOk();
    }

    public function test_revocation_disables_agent_access_and_removes_the_private_key_file_only(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'key-revoke@example.test']);
        $phone = $user->createToken('iphone-share-extension', UploadinyTokenAbility::phone());
        $this->assertTrue(app(AgentAccess::class)->rotate($user));
        $value = Storage::disk('local')->get('credentials/uploadiny-agent-token.txt');
        $this->assertSame(1, $user->tokens()->where('name', AgentAccess::TOKEN_NAME)->count());

        $this->actingAs($user)->delete(route('agent-access.destroy'))->assertRedirect(route('agent-access.show'));

        $this->assertSame(0, $user->tokens()->where('name', AgentAccess::TOKEN_NAME)->count());
        Storage::disk('local')->assertMissing('credentials/uploadiny-agent-token.txt');
        $this->get(route('agent-access.show'))->assertSee('No active API key');
        $this->freshBearer($value)->getJson(route('api.projects.index'))->assertUnauthorized();
        $this->freshBearer($phone->plainTextToken)->getJson(route('api.projects.index'))->assertOk();
    }

    public function test_failed_key_storage_leaves_the_previous_key_and_phone_token_usable(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'key-storage@example.test']);
        $phone = $user->createToken('iphone-share-extension', UploadinyTokenAbility::phone());
        $this->assertTrue(app(AgentAccess::class)->rotate($user));
        $files = Storage::disk('local');
        $old = $files->get('credentials/uploadiny-agent-token.txt');
        $this->freshBearer($old)->getJson(route('api.projects.index'))->assertOk();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->with('credentials/.uploadiny-agent-token.next', Mockery::type('string'))->andReturn(false);
        $disk->shouldReceive('delete')->once()->with('credentials/.uploadiny-agent-token.next')->andReturn(true);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $this->flushHeaders()->actingAs($user)->post(route('agent-access.store'))->assertRedirect(route('agent-access.show'))->assertSessionHasErrors('api_key');

        $this->assertSame($old, $files->get('credentials/uploadiny-agent-token.txt'));
        $this->assertSame(1, $user->tokens()->where('name', AgentAccess::TOKEN_NAME)->count());
        $this->freshBearer($old)->getJson(route('api.projects.index'))->assertOk();
        $this->freshBearer($phone->plainTextToken)->getJson(route('api.projects.index'))->assertOk();
    }

    public function test_an_expired_or_revoked_file_is_not_presented_as_an_active_key(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'key-inactive@example.test']);
        $expired = $user->createToken(AgentAccess::TOKEN_NAME, UploadinyTokenAbility::agent(), now()->subMinute());
        Storage::disk('local')->put('credentials/uploadiny-agent-token.txt', $expired->plainTextToken);
        $this->actingAs($user)->get(route('agent-access.show'))->assertViewHas('apiKey', null)->assertDontSee($expired->plainTextToken);
        $expired->accessToken->delete();
        $this->get(route('agent-access.show'))->assertViewHas('apiKey', null);
    }

    public function test_key_rotation_and_revocation_require_csrf_protection(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'key-csrf@example.test']);
        $this->assertTrue(app(AgentAccess::class)->rotate($user));
        $before = Storage::disk('local')->get('credentials/uploadiny-agent-token.txt');
        $this->actingAs($user);
        $environment = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $this->post(route('agent-access.store'))->assertStatus(419);
            $this->delete(route('agent-access.destroy'))->assertStatus(419);
        } finally {
            $this->app['env'] = $environment;
        }
        $this->assertSame($before, Storage::disk('local')->get('credentials/uploadiny-agent-token.txt'));
        $this->freshBearer($before)->getJson(route('api.projects.index'))->assertOk();
    }
}

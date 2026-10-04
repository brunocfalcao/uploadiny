<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(ValidateCsrfToken::class, fn () => new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }

    public function test_stale_login_form_returns_to_sign_in_without_authenticating_or_flashing_password(): void
    {
        $user = User::factory()->create(['email' => 'stale-login@example.test']);
        $this->assertGuest();
        $this->withSession(['_token' => 'fresh-security-token'])->post(route('login.store'), [
            '_token' => 'stale-security-token', 'email' => $user->email, 'password' => 'test-password-123',
        ])->assertStatus(303)->assertRedirect(route('login'))->assertSessionHas('status', 'Your sign-in form expired. Please sign in again.')->assertSessionMissing('_old_input.password');
        $this->assertGuest();
        $this->get(route('login'))->assertOk()->assertSee('Your sign-in form expired. Please sign in again.');
    }

    public function test_fresh_login_token_still_authenticates_normally(): void
    {
        $user = User::factory()->create(['email' => 'fresh-login@example.test']);
        $this->assertGuest();
        $this->withSession(['_token' => 'fresh-security-token'])->post(route('login.store'), [
            '_token' => 'fresh-security-token', 'email' => $user->email, 'password' => 'test-password-123',
        ])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_json_login_and_other_forms_still_reject_invalid_security_tokens(): void
    {
        $this->withSession(['_token' => 'fresh-security-token'])->postJson(route('login.store'), ['_token' => 'stale-security-token'])->assertStatus(419);
        $this->withSession(['_token' => 'fresh-security-token'])->post(route('projects.store'), ['_token' => 'stale-security-token'])->assertStatus(419);
        $this->assertGuest();
    }
}

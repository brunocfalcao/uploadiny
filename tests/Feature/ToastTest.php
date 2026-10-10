<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Project;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class ToastTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_pages_render_confirmations_and_errors_outside_the_content(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'toast-owner@example.test']);
        $project = Project::factory()->create(['slug' => 'toast-project']);
        $this->actingAs($user);
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['name' => 'Toast error <script>alert(1)</script>']));

        foreach ([route('projects.index'), route('projects.show', $project), route('agent-access.show')] as $url) {
            $this->get($url)->assertOk()->assertDontSee('Toast confirmation')->assertDontSee('Toast error');
            $response = $this->withSession(['status' => 'Toast confirmation <script>alert(1)</script>', 'errors' => $errors])->get($url);

            $response->assertOk()->assertSeeInOrder(['</main>', 'class="toast-region"', 'Toast confirmation &lt;script&gt;alert(1)&lt;/script&gt;', 'Toast error &lt;script&gt;alert(1)&lt;/script&gt;'], false)
                ->assertSee('data-transient-notice', false)
                ->assertSee('role="alert" aria-live="assertive"', false)
                ->assertSee('aria-label="Dismiss notification"', false)
                ->assertDontSee('<script>alert(1)</script>', false);
            $this->assertSame(1, substr_count($response->getContent(), 'Toast confirmation'));
            $this->assertSame(1, substr_count($response->getContent(), 'Toast error'));
            $this->flushSession();
        }
    }

    public function test_sign_in_status_uses_a_toast_while_validation_stays_with_the_form(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('Toast login status');
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['email' => 'Toast login validation']));

        $response = $this->withSession(['status' => 'Toast login status', 'errors' => $errors])->get(route('login'));

        $response->assertOk()->assertSeeInOrder(['<form', 'Toast login validation', '</main>', 'class="toast-region"', 'Toast login status'], false);
        $this->assertSame(1, substr_count($response->getContent(), 'Toast login status'));
        $this->assertSame(1, substr_count($response->getContent(), 'Toast login validation'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\UploadImage;
use App\UploadinyTokenAbility;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivateJsonResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_json_and_authentication_errors_cannot_be_stored(): void
    {
        $this->getJson('/api/projects')->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        $user = User::factory()->create(['email' => 'private-json@example.test']);
        $key = $user->createToken('private-json', UploadinyTokenAbility::agent())->plainTextToken;
        $this->withToken($key)->getJson('/api/projects')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $image = UploadImage::factory()->create();
        $this->actingAs($user)->getJson(route('images.show', $image))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }
}

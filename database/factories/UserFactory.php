<?php

declare(strict_types=1);

namespace Database\Factories;

use App\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return ['name' => 'Bruno', 'email' => fake()->unique()->safeEmail(), 'password' => 'test-password-123'];
    }
}

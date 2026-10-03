<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return ['name' => fake()->unique()->word(), 'slug' => fake()->unique()->slug(2), 'description' => null];
    }
}

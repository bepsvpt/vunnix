<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'gitlab_project_id' => fake()->unique()->numberBetween(1, 999999),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'enabled' => false,
            'webhook_configured' => false,
            'webhook_id' => null,
        ];
    }

    public function enabled(): static
    {
        return $this->state(['enabled' => true]);
    }
}

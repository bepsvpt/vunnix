<?php

namespace Database\Factories;

use App\Models\HealthSnapshot;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HealthSnapshot>
 */
class HealthSnapshotFactory extends Factory
{
    protected $model = HealthSnapshot::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'dimension' => fake()->randomElement(['coverage', 'dependency', 'complexity']),
            'score' => fake()->randomFloat(2, 20, 100),
            'details' => [],
            'source_ref' => null,
            'created_at' => now(),
        ];
    }
}

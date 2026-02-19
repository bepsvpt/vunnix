<?php

namespace Database\Factories;

use App\Models\MemoryEntry;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemoryEntry>
 */
class MemoryEntryFactory extends Factory
{
    protected $model = MemoryEntry::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'type' => 'review_pattern',
            'category' => 'false_positive',
            'content' => [
                'pattern' => fake()->sentence(),
                'sample_size' => fake()->numberBetween(20, 60),
            ],
            'confidence' => fake()->numberBetween(40, 95),
            'source_task_id' => Task::factory(),
            'source_meta' => ['mr_iid' => fake()->numberBetween(1, 999)],
            'applied_count' => 0,
            'archived_at' => null,
        ];
    }
}

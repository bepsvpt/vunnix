<?php

namespace Database\Factories;

use App\Models\FindingAcceptance;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FindingAcceptance>
 */
class FindingAcceptanceFactory extends Factory
{
    protected $model = FindingAcceptance::class;

    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'project_id' => Project::factory(),
            'mr_iid' => fake()->numberBetween(1, 200),
            'finding_id' => (string) fake()->numberBetween(1, 9999),
            'file' => fake()->randomElement(['app/Services/TaskDispatcher.php', 'app/Models/Task.php', 'resources/js/pages/AdminPage.vue']),
            'line' => fake()->numberBetween(1, 300),
            'severity' => fake()->randomElement(['critical', 'major', 'minor']),
            'title' => fake()->sentence(6),
            'category' => fake()->randomElement(['performance', 'security', 'style', 'logic']),
            'gitlab_discussion_id' => null,
            'status' => fake()->randomElement(['accepted', 'accepted_auto', 'dismissed']),
            'resolved_at' => null,
            'code_change_correlated' => false,
            'correlated_commit_sha' => null,
            'bulk_resolved' => false,
            'emoji_positive_count' => 0,
            'emoji_negative_count' => 0,
            'emoji_sentiment' => 'neutral',
        ];
    }
}

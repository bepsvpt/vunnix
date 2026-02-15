<?php

namespace Database\Factories;

use App\Enums\TaskOrigin;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'type' => TaskType::CodeReview,
            'origin' => TaskOrigin::Webhook,
            'user_id' => User::factory(),
            'project_id' => Project::factory(),
            'priority' => TaskPriority::Normal,
            'status' => TaskStatus::Received,
            'mr_iid' => fake()->numberBetween(1, 500),
            'commit_sha' => fake()->sha1(),
            'pipeline_status' => null,
        ];
    }

    public function queued(): static
    {
        return $this->state(['status' => TaskStatus::Queued]);
    }

    public function running(): static
    {
        return $this->state([
            'status' => TaskStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state([
            'status' => TaskStatus::Completed,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(['status' => TaskStatus::Failed]);
    }

    public function fromConversation(): static
    {
        return $this->state([
            'origin' => TaskOrigin::Conversation,
            'conversation_id' => fake()->uuid(),
            'mr_iid' => null,
            'commit_sha' => null,
        ]);
    }
}

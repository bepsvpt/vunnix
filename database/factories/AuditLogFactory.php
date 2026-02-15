<?php

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'event_type' => $this->faker->randomElement([
                'conversation_turn', 'task_execution', 'action_dispatch',
                'configuration_change', 'webhook_received', 'auth_event',
            ]),
            'summary' => $this->faker->sentence(),
            'properties' => ['detail' => $this->faker->sentence()],
        ];
    }

    public function conversationTurn(): static
    {
        return $this->state(fn () => [
            'event_type' => 'conversation_turn',
            'summary' => 'Conversation turn recorded',
            'properties' => [
                'user_message' => 'Hello, review my code',
                'ai_response' => 'I will review your code now.',
                'tool_calls' => [],
                'tokens_used' => 150,
                'model' => 'claude-opus-4-6',
            ],
        ]);
    }

    public function taskExecution(): static
    {
        return $this->state(fn () => [
            'event_type' => 'task_execution',
            'summary' => 'Task execution completed',
            'properties' => [
                'task_type' => 'code_review',
                'prompt_sent' => 'Review this merge request...',
                'ai_response' => 'Found 3 issues...',
                'tokens_used' => 500,
                'cost' => 0.015,
                'duration_seconds' => 45,
                'result_status' => 'completed',
            ],
        ]);
    }

    public function configurationChange(): static
    {
        return $this->state(fn () => [
            'event_type' => 'configuration_change',
            'summary' => 'Configuration changed: ai_model',
            'properties' => [
                'key' => 'ai_model',
                'old_value' => 'claude-opus-4-6',
                'new_value' => 'claude-sonnet-4-20250514',
            ],
        ]);
    }

    public function authEvent(): static
    {
        return $this->state(fn () => [
            'event_type' => 'auth_event',
            'summary' => 'User logged in',
            'properties' => [
                'action' => 'login',
            ],
        ]);
    }

    public function webhookReceived(): static
    {
        return $this->state(fn () => [
            'event_type' => 'webhook_received',
            'summary' => 'Webhook received: merge_request',
            'properties' => [
                'gitlab_event_type' => 'merge_request',
                'relevant_ids' => ['mr_iid' => 42],
            ],
        ]);
    }

    public function actionDispatch(): static
    {
        return $this->state(fn () => [
            'event_type' => 'action_dispatch',
            'summary' => 'Action dispatched: code_review',
            'properties' => [
                'action_type' => 'code_review',
                'gitlab_artifact_url' => 'https://gitlab.example.com/project/-/merge_requests/42',
            ],
        ]);
    }
}

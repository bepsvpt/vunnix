<?php

namespace Database\Factories;

use App\Models\DeadLetterEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeadLetterEntry>
 */
class DeadLetterEntryFactory extends Factory
{
    protected $model = DeadLetterEntry::class;

    public function definition(): array
    {
        return [
            'task_record' => ['id' => 1, 'type' => 'code_review', 'status' => 'failed'],
            'failure_reason' => $this->faker->randomElement([
                'max_retries_exceeded', 'expired', 'invalid_request',
                'context_exceeded', 'scheduling_timeout',
            ]),
            'error_details' => 'Test error: ' . $this->faker->sentence(),
            'attempts' => [],
            'dismissed' => false,
            'retried' => false,
            'originally_queued_at' => now()->subHour(),
            'dead_lettered_at' => now(),
        ];
    }
}

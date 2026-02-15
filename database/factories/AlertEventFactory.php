<?php

namespace Database\Factories;

use App\Models\AlertEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

class AlertEventFactory extends Factory
{
    protected $model = AlertEvent::class;

    public function definition(): array
    {
        return [
            'alert_type' => $this->faker->randomElement([
                'api_outage', 'high_failure_rate', 'queue_depth',
                'infrastructure', 'auth_failure', 'disk_usage',
            ]),
            'status' => 'active',
            'severity' => $this->faker->randomElement(['high', 'medium', 'info']),
            'message' => $this->faker->sentence(),
            'context' => [],
            'detected_at' => now(),
        ];
    }

    public function resolved(): static
    {
        return $this->state([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);
    }

    public function notified(): static
    {
        return $this->state([
            'notified_at' => now(),
        ]);
    }
}

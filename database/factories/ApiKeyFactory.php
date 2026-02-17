<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApiKeyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->words(2, true),
            'key' => hash('sha256', $this->faker->uuid()),
            'last_used_at' => null,
            'last_ip' => null,
            'expires_at' => null,
            'revoked' => false,
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked' => true,
            'revoked_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subDay(),
        ]);
    }
}

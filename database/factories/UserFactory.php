<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => null,
            'remember_token' => Str::random(10),
            'gitlab_id' => fake()->unique()->numberBetween(1, 999999),
            'username' => fake()->unique()->userName(),
            'avatar_url' => fake()->imageUrl(),
            'oauth_provider' => 'gitlab',
            'oauth_token' => Str::random(40),
            'oauth_refresh_token' => Str::random(40),
            'oauth_token_expires_at' => now()->addHours(2),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }
}

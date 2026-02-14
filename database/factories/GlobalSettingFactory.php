<?php

namespace Database\Factories;

use App\Models\GlobalSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GlobalSetting>
 */
class GlobalSettingFactory extends Factory
{
    protected $model = GlobalSetting::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'value' => fake()->word(),
            'type' => 'string',
            'description' => fake()->sentence(),
        ];
    }

    public function boolean(bool $value = true): static
    {
        return $this->state([
            'value' => $value,
            'type' => 'boolean',
        ]);
    }

    public function integer(int $value = 0): static
    {
        return $this->state([
            'value' => $value,
            'type' => 'integer',
        ]);
    }

    public function json(array $value = []): static
    {
        return $this->state([
            'value' => $value,
            'type' => 'json',
        ]);
    }
}

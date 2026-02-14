<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectConfig;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProjectConfig>
 */
class ProjectConfigFactory extends Factory
{
    protected $model = ProjectConfig::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'webhook_secret' => Str::random(40),
            'webhook_token_validation' => true,
            'settings' => [],
        ];
    }

    public function withoutWebhookValidation(): static
    {
        return $this->state(['webhook_token_validation' => false]);
    }
}

<?php

namespace App\Services;

use App\Models\GlobalSetting;
use App\Models\Project;
use App\Models\ProjectConfig;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class ProjectConfigService
{
    private const CACHE_PREFIX = 'project_config:';
    private const CACHE_TTL_MINUTES = 60;

    /**
     * Configurable setting keys with their types.
     * Matches the .vunnix.toml schema from §3.7.
     */
    public static function settingKeys(): array
    {
        return [
            'ai_model' => 'string',
            'ai_language' => 'string',
            'timeout_minutes' => 'integer',
            'max_tokens' => 'integer',
            'code_review.auto_review' => 'boolean',
            'code_review.auto_review_on_push' => 'boolean',
            'code_review.severity_threshold' => 'string',
            'feature_dev.enabled' => 'boolean',
            'feature_dev.branch_prefix' => 'string',
            'feature_dev.auto_create_mr' => 'boolean',
            'conversation.enabled' => 'boolean',
            'conversation.max_history_messages' => 'integer',
            'conversation.tool_use_gitlab' => 'boolean',
            'ui_adjustment.dev_server_command' => 'string',
            'ui_adjustment.screenshot_base_url' => 'string',
            'ui_adjustment.screenshot_wait_ms' => 'integer',
            'labels.auto_label' => 'boolean',
            'labels.risk_labels' => 'boolean',
        ];
    }

    /**
     * Get a resolved config value: project override → global → default.
     */
    public function get(Project $project, string $key, mixed $default = null): mixed
    {
        $settings = $this->getProjectSettings($project);

        $value = Arr::get($settings, $key);
        if ($value !== null) {
            return $value;
        }

        // Fall back to global setting (top-level keys only)
        $topKey = explode('.', $key)[0];
        if ($topKey === $key) {
            return GlobalSetting::get($key, $default);
        }

        return $default;
    }

    /**
     * Set a project-level override. Pass null to remove the override.
     */
    public function set(Project $project, string $key, mixed $value): void
    {
        $config = $project->projectConfig;
        if (! $config) {
            $config = $project->projectConfig()->create(['settings' => []]);
        }

        $settings = $config->settings ?? [];

        if ($value === null) {
            Arr::forget($settings, $key);
        } else {
            Arr::set($settings, $key, $value);
        }

        $config->update(['settings' => $settings]);

        Cache::forget(self::CACHE_PREFIX . $project->id);
    }

    /**
     * Bulk-update project settings from a flat key → value map.
     * Keys with null values are removed (reset to global/default).
     */
    public function bulkSet(Project $project, array $overrides): void
    {
        $config = $project->projectConfig;
        if (! $config) {
            $config = $project->projectConfig()->create(['settings' => []]);
        }

        $settings = $config->settings ?? [];

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                Arr::forget($settings, $key);
            } else {
                Arr::set($settings, $key, $value);
            }
        }

        $config->update(['settings' => $settings]);

        Cache::forget(self::CACHE_PREFIX . $project->id);
    }

    /**
     * Get all effective settings for a project with source indicators.
     * Returns: ['key' => ['value' => mixed, 'source' => 'project'|'global'|'default']]
     */
    public function allEffective(Project $project): array
    {
        $projectSettings = $this->getProjectSettings($project);
        $globalDefaults = GlobalSetting::defaults();
        $result = [];

        // Start with hardcoded defaults
        foreach ($globalDefaults as $key => $value) {
            $result[$key] = ['value' => $value, 'source' => 'default'];
        }

        // Layer global DB settings on top
        foreach (array_keys($globalDefaults) as $key) {
            $globalValue = GlobalSetting::get($key);
            if ($globalValue !== ($globalDefaults[$key] ?? null)) {
                $result[$key] = ['value' => $globalValue, 'source' => 'global'];
            }
        }

        // Layer project overrides on top
        foreach (Arr::dot($projectSettings) as $key => $value) {
            $result[$key] = ['value' => $value, 'source' => 'project'];
        }

        return $result;
    }

    /**
     * Get raw project settings from cache or DB.
     */
    private function getProjectSettings(Project $project): array
    {
        return Cache::remember(
            self::CACHE_PREFIX . $project->id,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            function () use ($project) {
                return $project->projectConfig?->settings ?? [];
            }
        );
    }
}

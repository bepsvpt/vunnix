<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProjectEnablementService
{
    private const MAINTAINER_ACCESS_LEVEL = 40;

    private const AI_LABELS = [
        ['name' => 'ai::reviewed', 'color' => '#428BCA', 'description' => 'AI code review completed'],
        ['name' => 'ai::risk-high', 'color' => '#D9534F', 'description' => 'AI assessed high risk'],
        ['name' => 'ai::risk-medium', 'color' => '#F0AD4E', 'description' => 'AI assessed medium risk'],
        ['name' => 'ai::risk-low', 'color' => '#5CB85C', 'description' => 'AI assessed low risk'],
        ['name' => 'ai::security', 'color' => '#D9534F', 'description' => 'AI flagged security concern'],
        ['name' => 'ai::created', 'color' => '#6F42C1', 'description' => 'Created by AI'],
    ];

    public function __construct(
        private readonly GitLabClient $gitLab,
    ) {}

    /**
     * Enable a project in Vunnix.
     *
     * Steps:
     * 1. Verify bot has Maintainer role (D129)
     * 2. Create webhook with secret token
     * 3. Create pipeline trigger token for CI execution
     * 4. Pre-create ai::* labels
     *
     * @return array{success: bool, error?: string, warnings: array<string>}
     */
    public function enable(Project $project): array
    {
        $warnings = [];

        // 1. Resolve bot user ID and verify membership
        $botUserId = $this->resolveBotUserId();
        if ($botUserId === null) {
            return ['success' => false, 'error' => 'Could not determine bot account user ID. Check GITLAB_BOT_TOKEN configuration.', 'warnings' => []];
        }

        $member = $this->gitLab->getProjectMember($project->gitlab_project_id, $botUserId);

        if ($member === null) {
            return [
                'success' => false,
                'error' => "The Vunnix bot account is not a member of this GitLab project. Add the bot as a Maintainer to project #{$project->gitlab_project_id}.",
                'warnings' => [],
            ];
        }

        if ($member['access_level'] < self::MAINTAINER_ACCESS_LEVEL) {
            $levelName = $this->accessLevelName($member['access_level']);

            return [
                'success' => false,
                'error' => "The bot account has {$levelName} access but requires Maintainer (or higher). Update the bot's role in GitLab project #{$project->gitlab_project_id}.",
                'warnings' => [],
            ];
        }

        // 2. Create webhook
        $secret = Str::random(40);
        $webhookUrl = rtrim(config('app.url'), '/').'/api/webhook';

        try {
            $webhook = $this->gitLab->createWebhook($project->gitlab_project_id, $webhookUrl, $secret, [
                'merge_requests_events' => true,
                'note_events' => true,
                'issues_events' => true,
                'push_events' => true,
            ]);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => 'Failed to create GitLab webhook: '.$e->getMessage(),
                'warnings' => $warnings,
            ];
        }

        // 3. Create pipeline trigger token for CI-based task execution
        $triggerToken = null;
        try {
            $trigger = $this->gitLab->createPipelineTrigger(
                $project->gitlab_project_id,
                'Vunnix task executor',
            );
            $triggerToken = $trigger['token'];
        } catch (Throwable $e) {
            $warnings[] = 'Failed to create CI pipeline trigger token: '.$e->getMessage().'. You can create one manually in GitLab Settings > CI/CD > Pipeline trigger tokens.';
            Log::warning('Failed to create pipeline trigger', [
                'project_id' => $project->gitlab_project_id,
                'error' => $e->getMessage(),
            ]);
        }

        // 4. Pre-create ai::* labels (idempotent — 409 = already exists)
        foreach (self::AI_LABELS as $label) {
            try {
                $this->gitLab->createProjectLabel(
                    $project->gitlab_project_id,
                    $label['name'],
                    $label['color'],
                    $label['description'],
                );
            } catch (Throwable $e) {
                Log::warning("Failed to create label {$label['name']}", [
                    'project_id' => $project->gitlab_project_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 5. Update project state
        $project->update([
            'enabled' => true,
            'webhook_configured' => true,
            'webhook_id' => $webhook['id'],
        ]);

        // Store webhook secret and trigger token in config
        $configData = ['webhook_secret' => $secret];
        if ($triggerToken) {
            $configData['ci_trigger_token'] = $triggerToken;
        }

        $config = $project->projectConfig;
        if ($config) {
            $config->update($configData);
        } else {
            $project->projectConfig()->create($configData);
        }

        Log::info('Project enabled', ['project_id' => $project->id, 'gitlab_project_id' => $project->gitlab_project_id]);

        return ['success' => true, 'warnings' => $warnings];
    }

    /**
     * Disable a project in Vunnix.
     *
     * Removes the webhook from GitLab, marks project as disabled.
     * Data is preserved in read-only mode (D60).
     *
     * @return array{success: bool, error?: string, warnings: array<string>}
     */
    public function disable(Project $project): array
    {
        // Remove webhook if one was configured
        if ($project->webhook_id) {
            try {
                $this->gitLab->deleteWebhook($project->gitlab_project_id, $project->webhook_id);
            } catch (Throwable $e) {
                Log::warning('Failed to remove webhook during project disable', [
                    'project_id' => $project->id,
                    'webhook_id' => $project->webhook_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $project->update([
            'enabled' => false,
            'webhook_configured' => false,
            'webhook_id' => null,
        ]);

        Log::info('Project disabled', ['project_id' => $project->id]);

        return ['success' => true, 'warnings' => []];
    }

    /**
     * Get the bot account's GitLab user ID.
     *
     * Uses config value if set, otherwise queries GitLab /user endpoint.
     */
    private function resolveBotUserId(): ?int
    {
        $configured = config('services.gitlab.bot_account_id');
        if ($configured) {
            return (int) $configured;
        }

        try {
            $response = $this->gitLab->getCurrentUser();

            return $response['id'] ?? null;
        } catch (Throwable $e) {
            Log::error('Failed to resolve bot user ID', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function accessLevelName(int $level): string
    {
        return match (true) {
            $level >= 50 => 'Owner',
            $level >= 40 => 'Maintainer',
            $level >= 30 => 'Developer',
            $level >= 20 => 'Reporter',
            $level >= 10 => 'Guest',
            default => "level {$level}",
        };
    }
}

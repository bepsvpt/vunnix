<?php

namespace App\Http\Middleware;

use App\Models\ProjectConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookToken
{
    /**
     * Validate the X-Gitlab-Token header against stored project webhook secrets.
     *
     * On success, merges the resolved Project into the request as `webhook_project`
     * so the controller doesn't need to repeat the lookup.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Gitlab-Token');

        if ($token === null || $token === '') {
            Log::warning('Webhook request missing X-Gitlab-Token header', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Missing webhook token.'], 401);
        }

        $projectConfig = $this->findProjectConfigByToken($token);

        if (! $projectConfig instanceof \App\Models\ProjectConfig) {
            Log::warning('Webhook request with invalid X-Gitlab-Token', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Invalid webhook token.'], 401);
        }

        $project = $projectConfig->project;

        if (! $project->enabled) {
            Log::warning('Webhook received for disabled project', [
                'project_id' => $projectConfig->project_id,
            ]);

            return response()->json(['error' => 'Project not enabled.'], 403);
        }

        // Make the resolved project available to the controller
        $request->merge(['webhook_project' => $project]);

        return $next($request);
    }

    /**
     * Find the ProjectConfig whose decrypted webhook_secret matches the token.
     *
     * Since webhook_secret uses Laravel's `encrypted` cast, we cannot query by
     * value — each row must be loaded and decrypted for comparison. This is
     * acceptable for the self-hosted GitLab Free use case (limited projects).
     */
    private function findProjectConfigByToken(string $token): ?ProjectConfig
    {
        return ProjectConfig::whereNotNull('webhook_secret')
            ->where('webhook_token_validation', true)
            ->with('project')
            ->get()
            ->first(fn (ProjectConfig $config): bool => $config->webhook_secret === $token);
    }
}

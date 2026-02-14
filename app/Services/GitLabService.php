<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitLabService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.gitlab.host', 'https://gitlab.com'), '/');
    }

    /**
     * Fetch all projects the user has membership in from GitLab API.
     * Paginates through all pages (100 per page).
     *
     * @return array<int, array>
     */
    public function getUserProjects(string $token): array
    {
        $allProjects = [];
        $page = 1;

        do {
            $response = Http::withToken($token)
                ->get("{$this->baseUrl}/api/v4/projects", [
                    'membership' => 'true',
                    'per_page' => 100,
                    'page' => $page,
                ]);

            if (! $response->successful()) {
                Log::warning('GitLab API: failed to fetch user projects', [
                    'status' => $response->status(),
                    'page' => $page,
                ]);

                return [];
            }

            $projects = $response->json();
            $allProjects = array_merge($allProjects, $projects);

            $nextPage = $response->header('x-next-page');
            $page = $nextPage ? (int) $nextPage : null;
        } while ($page);

        return $allProjects;
    }

    /**
     * Resolve the effective access level for a GitLab project.
     * Takes the higher of project_access and group_access.
     */
    public function resolveAccessLevel(array $project): int
    {
        $projectAccess = $project['permissions']['project_access']['access_level'] ?? 0;
        $groupAccess = $project['permissions']['group_access']['access_level'] ?? 0;

        return max($projectAccess, $groupAccess);
    }
}

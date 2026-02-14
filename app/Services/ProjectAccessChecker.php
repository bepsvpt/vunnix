<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Verifies that a user has access to a GitLab project before tool execution.
 *
 * Maps GitLab project IDs (used in API URLs) to Vunnix Project models,
 * then checks user membership via the project_user pivot table.
 *
 * Used by all Conversation Engine tools (T53) to enforce cross-project
 * access control per D28.
 *
 * When called from tools, Auth::user() is resolved internally so tools
 * don't need a facade dependency — the checker is mocked in unit tests.
 * Feature tests may pass $user explicitly to avoid Auth setup.
 */
class ProjectAccessChecker
{
    /**
     * Check whether the given (or authenticated) user has access to the GitLab project.
     *
     * Returns null if the user has access, or a rejection message string
     * if access is denied. This follows the tool convention of returning
     * error strings rather than throwing exceptions.
     *
     * @param  int        $gitlabProjectId  The GitLab project ID to check access for.
     * @param  User|null  $user             The user to check. Falls back to Auth::user() if null.
     */
    public function check(int $gitlabProjectId, ?User $user = null): ?string
    {
        $user = $user ?? Auth::user();

        if ($user === null) {
            return 'Access denied: no authenticated user.';
        }

        $project = Project::where('gitlab_project_id', $gitlabProjectId)->first();

        if ($project === null) {
            return "Access denied: project {$gitlabProjectId} is not registered in Vunnix.";
        }

        if (! $project->enabled) {
            return "Access denied: project {$gitlabProjectId} is not enabled.";
        }

        $isMember = $user->projects()->where('projects.id', $project->id)->exists();

        if (! $isMember) {
            return "Access denied: you do not have access to this project.";
        }

        return null;
    }
}

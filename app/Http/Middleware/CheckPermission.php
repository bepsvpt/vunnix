<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * Checks that the authenticated user has the specified permission
     * on the project resolved from the route. The project is resolved
     * from either a route model binding ({project}) or a project_id
     * query/body parameter.
     *
     * Usage in routes: ->middleware('permission:chat.access')
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $project = $this->resolveProject($request);

        if (! $project instanceof \App\Models\Project) {
            abort(403, 'No project context provided.');
        }

        if (! $user->hasPermission($permission, $project)) {
            abort(403, 'You do not have the required permission.');
        }

        return $next($request);
    }

    private function resolveProject(Request $request): ?Project
    {
        // First, try route model binding
        if ($request->route('project') instanceof Project) {
            return $request->route('project');
        }

        // Then, try a project_id parameter (route, query, or body)
        $projectId = $request->route('project') ?? $request->input('project_id');

        if ($projectId) {
            return Project::where('id', $projectId)->first();
        }

        return null;
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    /**
     * Transform the user into the auth state payload for the SPA.
     *
     * Returns user profile + accessible projects, each annotated
     * with the user's roles and permissions on that project.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $projects = $this->accessibleProjects();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'username' => $this->username,
            'avatar_url' => $this->avatar_url,
            'projects' => $projects->map(function (Project $project) {
                $roles = $this->rolesForProject($project);
                $permissions = $this->permissionsForProject($project);

                return [
                    'id' => $project->id,
                    'gitlab_project_id' => $project->gitlab_project_id,
                    'name' => $project->name,
                    'slug' => $project->slug,
                    'roles' => $roles->pluck('name')->values()->all(),
                    'permissions' => $permissions->pluck('name')->values()->all(),
                ];
            })->values()->all(),
        ];
    }
}

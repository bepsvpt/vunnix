<?php

namespace App\Models;

use App\Services\GitLabService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string|null $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int $gitlab_id
 * @property string $username
 * @property string|null $avatar_url
 * @property string $oauth_provider
 * @property string|null $oauth_token
 * @property string|null $oauth_refresh_token
 * @property \Illuminate\Support\Carbon|null $oauth_token_expires_at
 * @property-read Collection<int, \App\Models\ApiKey> $apiKeys
 * @property-read int|null $api_keys_count
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, \App\Models\Project> $projects
 * @property-read int|null $projects_count
 * @property-read Collection<int, \App\Models\Role> $roles
 * @property-read int|null $roles_count
 *
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 *
 * @mixin \Eloquent
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'gitlab_id',
        'username',
        'avatar_url',
        'oauth_provider',
        'oauth_token',
        'oauth_refresh_token',
        'oauth_token_expires_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'oauth_token',
        'oauth_refresh_token',
    ];

    /**
     * @return array{
     *   email_verified_at: 'datetime',
     *   password: 'hashed',
     *   gitlab_id: 'integer',
     *   oauth_token_expires_at: 'datetime',
     * }
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'gitlab_id' => 'integer',
            'oauth_token_expires_at' => 'datetime',
        ];
    }

    /**
     * Return empty string instead of null for OAuth users (no password).
     * Prevents hash_hmac() deprecation warning in SessionGuard::hashPasswordForCookie().
     */
    public function getAuthPassword(): string
    {
        return $this->password ?? '';
    }

    /** @return BelongsToMany<Project, $this> */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)
            ->withPivot('gitlab_access_level', 'synced_at')
            ->withTimestamps();
    }

    /** @return HasMany<ApiKey, $this> */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function accessibleProjects(): Collection
    {
        return $this->projects()->where('enabled', true)->get();
    }

    public function gitlabAccessLevel(Project $project): ?int
    {
        $pivot = $this->projects()->where('projects.id', $project->id)->first();

        return $pivot?->pivot->gitlab_access_level;
    }

    /**
     * Sync GitLab project memberships into the project_user pivot table.
     * Only syncs projects that are registered in Vunnix's projects table.
     * On API failure, existing memberships are preserved (no destructive action).
     */
    /**
     * Get all roles assigned to the user (across all projects).
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')
            ->withPivot('project_id', 'assigned_by')
            ->withTimestamps();
    }

    /**
     * Get roles for a specific project.
     */
    public function rolesForProject(Project $project): Collection
    {
        return $this->roles()->wherePivot('project_id', $project->id)->get();
    }

    /**
     * Check if the user has a given role on a specific project.
     */
    public function hasRole(string $roleName, Project $project): bool
    {
        return $this->roles()
            ->where('name', $roleName)
            ->wherePivot('project_id', $project->id)
            ->exists();
    }

    /**
     * Check if the user has a given permission on a specific project.
     * Resolves through the user's roles on that project.
     */
    public function hasPermission(string $permissionName, Project $project): bool
    {
        return DB::table('role_user')
            ->join('role_permission', 'role_user.role_id', '=', 'role_permission.role_id')
            ->join('permissions', 'role_permission.permission_id', '=', 'permissions.id')
            ->where('role_user.user_id', $this->id)
            ->where('role_user.project_id', $project->id)
            ->where('permissions.name', $permissionName)
            ->exists();
    }

    /**
     * Get all permission names the user has on a specific project.
     */
    public function permissionsForProject(Project $project): Collection
    {
        return Permission::query()
            ->select('permissions.*')
            ->join('role_permission', 'permissions.id', '=', 'role_permission.permission_id')
            ->join('role_user', 'role_permission.role_id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $this->id)
            ->where('role_user.project_id', $project->id)
            ->distinct()
            ->get();
    }

    /**
     * Assign a role to the user on a specific project.
     */
    public function assignRole(Role $role, Project $project, ?User $assignedBy = null): void
    {
        $this->roles()->attach($role->id, [
            'project_id' => $project->id,
            'assigned_by' => $assignedBy?->id,
        ]);
        $this->unsetRelation('roles');
    }

    /**
     * Remove a role from the user on a specific project.
     */
    public function removeRole(Role $role, Project $project): void
    {
        $this->roles()
            ->wherePivot('project_id', $project->id)
            ->detach($role->id);
        $this->unsetRelation('roles');
    }

    public function syncMemberships(): void
    {
        $service = app(GitLabService::class);
        $gitlabProjects = $service->getUserProjects($this->oauth_token);

        // On API failure, don't wipe existing memberships
        if (empty($gitlabProjects)) {
            return;
        }

        // Build a map: gitlab_project_id => access_level
        $gitlabMap = [];
        foreach ($gitlabProjects as $gp) {
            $gitlabMap[$gp['id']] = $service->resolveAccessLevel($gp);
        }

        // Find which Vunnix projects match the user's GitLab memberships
        $vunnixProjects = Project::whereIn('gitlab_project_id', array_keys($gitlabMap))->get();

        // Build sync data: project_id => pivot attributes
        $syncData = [];
        foreach ($vunnixProjects as $project) {
            $syncData[$project->id] = [
                'gitlab_access_level' => $gitlabMap[$project->gitlab_project_id],
                'synced_at' => now(),
            ];
        }

        // Sync replaces all existing pivot rows: adds new, updates existing, removes missing
        $this->projects()->sync($syncData);
        $this->unsetRelation('projects');
    }
}

<?php

namespace App\Models;

use App\Services\GitLabService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
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

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)
            ->withPivot('gitlab_access_level', 'synced_at')
            ->withTimestamps();
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

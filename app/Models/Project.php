<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Throwable;

/**
 * @property int $id
 * @property int $gitlab_project_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $enabled
 * @property bool $webhook_configured
 * @property int|null $webhook_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\ProjectConfig|null $projectConfig
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\MemoryEntry> $memoryEntries
 * @property-read int|null $memory_entries_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Role> $roles
 * @property-read int|null $roles_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 *
 * @method static Builder<static>|Project enabled()
 * @method static \Database\Factories\ProjectFactory factory($count = null, $state = [])
 * @method static Builder<static>|Project newModelQuery()
 * @method static Builder<static>|Project newQuery()
 * @method static Builder<static>|Project query()
 *
 * @mixin \Eloquent
 */
class Project extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'gitlab_project_id',
        'name',
        'slug',
        'description',
        'enabled',
        'webhook_configured',
        'webhook_id',
    ];

    /** @return BelongsToMany<User, $this, ProjectUserPivot> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(ProjectUserPivot::class)
            ->withPivot('gitlab_access_level', 'synced_at')
            ->withTimestamps();
    }

    /** @return HasOne<ProjectConfig, $this> */
    public function projectConfig(): HasOne
    {
        return $this->hasOne(ProjectConfig::class);
    }

    /** @return HasMany<Role, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /** @return HasMany<MemoryEntry, $this> */
    public function memoryEntries(): HasMany
    {
        return $this->hasMany(MemoryEntry::class);
    }

    public function defaultRole(): ?Role
    {
        return $this->roles()->where('is_default', true)->first();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * Resolve the project's GitLab web URL (e.g. https://gitlab.com/group/project).
     *
     * Tries the cache first. On a cache miss (e.g. after Docker restart), fetches
     * from the GitLab API and caches the result forever so only one API call is
     * ever made per cache lifetime. Returns null only if the API call fails.
     */
    public function gitlabWebUrl(): ?string
    {
        $cacheKey = "project.{$this->id}.gitlab_web_url";

        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $data = app(\App\Services\GitLabClient::class)->getProject($this->gitlab_project_id);
            $url = $data['web_url'] ?? null;
            if ($url !== null) {
                \Illuminate\Support\Facades\Cache::forever($cacheKey, $url);
            }

            return $url;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *   gitlab_project_id: 'integer',
     *   enabled: 'boolean',
     *   webhook_configured: 'boolean',
     * }
     */
    protected function casts(): array
    {
        return [
            'gitlab_project_id' => 'integer',
            'enabled' => 'boolean',
            'webhook_configured' => 'boolean',
        ];
    }
}

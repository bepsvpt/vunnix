<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
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

    public function defaultRole(): ?Role
    {
        return $this->roles()->where('is_default', true)->first();
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
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

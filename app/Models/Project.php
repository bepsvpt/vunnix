<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    protected function casts(): array
    {
        return [
            'gitlab_project_id' => 'integer',
            'enabled' => 'boolean',
            'webhook_configured' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('gitlab_access_level', 'synced_at')
            ->withTimestamps();
    }

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
}

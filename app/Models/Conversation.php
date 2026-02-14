<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Conversation extends Model
{
    use HasFactory;

    protected $table = 'agent_conversations';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'title',
        'user_id',
        'project_id',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Conversation $conversation) {
            if (! $conversation->id) {
                $conversation->id = (string) Str::uuid7();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'conversation_id')->latestOfMany();
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'conversation_projects');
    }

    /**
     * Get all project IDs associated with this conversation
     * (primary project_id + any additional pivot projects).
     */
    public function allProjectIds(): array
    {
        $ids = $this->projects()->pluck('projects.id')->toArray();
        if ($this->project_id && ! in_array($this->project_id, $ids)) {
            $ids[] = $this->project_id;
        }

        return $ids;
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * Scope to conversations accessible by a user.
     * A user can access conversations belonging to projects they are a member of,
     * either via the primary project_id or additional projects in the pivot table.
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        $projectIds = $user->projects()->pluck('projects.id');

        return $query->where(function (Builder $q) use ($projectIds) {
            $q->whereIn('project_id', $projectIds)
                ->orWhereHas('projects', function (Builder $sub) use ($projectIds) {
                    $sub->whereIn('projects.id', $projectIds);
                });
        });
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}

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

/**
 * @property string $id
 * @property int $user_id
 * @property string $title
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $project_id
 * @property string|null $title_search
 * @property \Illuminate\Support\Carbon|null $archived_at
 * @property-read \App\Models\Message|null $latestMessage
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Message> $messages
 * @property-read int|null $messages_count
 * @property-read \App\Models\Project|null $project
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Project> $projects
 * @property-read int|null $projects_count
 * @property-read \App\Models\User|null $user
 *
 * @method static Builder<static>|Conversation accessibleBy(\App\Models\User $user)
 * @method static Builder<static>|Conversation archived()
 * @method static \Database\Factories\ConversationFactory factory($count = null, $state = [])
 * @method static Builder<static>|Conversation forProject(int $projectId)
 * @method static Builder<static>|Conversation newModelQuery()
 * @method static Builder<static>|Conversation newQuery()
 * @method static Builder<static>|Conversation notArchived()
 * @method static Builder<static>|Conversation query()
 *
 * @mixin \Eloquent
 */
class Conversation extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $table = 'agent_conversations';

    protected $keyType = 'string';

    protected $fillable = [
        'title',
        'user_id',
        'project_id',
        'archived_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (Conversation $conversation): void {
            if (! $conversation->id) {
                $conversation->id = (string) Str::uuid7();
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    /** @return HasOne<Message, $this> */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'conversation_id')->latestOfMany();
    }

    /** @return BelongsToMany<Project, $this> */
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

        return $query->where(function (Builder $q) use ($projectIds): void {
            $q->whereIn('project_id', $projectIds)
                ->orWhereHas('projects', function (Builder $sub) use ($projectIds): void {
                    $sub->whereIn('projects.id', $projectIds);
                });
        });
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @return array{
     *   archived_at: 'datetime',
     * }
     */
    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $event_type
 * @property int|null $user_id
 * @property int|null $project_id
 * @property int|null $task_id
 * @property string|null $conversation_id
 * @property string $summary
 * @property array<array-key, mixed>|null $properties
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Project|null $project
 * @property-read \App\Models\Task|null $task
 * @property-read \App\Models\User|null $user
 *
 * @method static \Database\Factories\AuditLogFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog query()
 *
 * @mixin \Eloquent
 */
class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_type',
        'user_id',
        'project_id',
        'task_id',
        'conversation_id',
        'summary',
        'properties',
        'ip_address',
        'user_agent',
    ];

    // ─── Relationships ──────────────────────────────────────────────

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

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return array{
     *   properties: 'array',
     * }
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }
}

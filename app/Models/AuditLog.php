<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}

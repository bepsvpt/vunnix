<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property array<array-key, mixed> $task_record
 * @property string $failure_reason
 * @property string|null $error_details
 * @property array<array-key, mixed> $attempts
 * @property bool $dismissed
 * @property \Illuminate\Support\Carbon|null $dismissed_at
 * @property int|null $dismissed_by
 * @property \Illuminate\Support\Carbon $originally_queued_at
 * @property \Illuminate\Support\Carbon $dead_lettered_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $task_id
 * @property bool $retried
 * @property \Illuminate\Support\Carbon|null $retried_at
 * @property int|null $retried_by
 * @property int|null $retried_task_id
 * @property-read \App\Models\User|null $dismissedBy
 * @property-read \App\Models\User|null $retriedBy
 * @property-read \App\Models\Task|null $retriedTask
 * @property-read \App\Models\Task|null $task
 *
 * @method static Builder<static>|DeadLetterEntry active()
 * @method static \Database\Factories\DeadLetterEntryFactory factory($count = null, $state = [])
 * @method static Builder<static>|DeadLetterEntry newModelQuery()
 * @method static Builder<static>|DeadLetterEntry newQuery()
 * @method static Builder<static>|DeadLetterEntry query()
 *
 * @mixin \Eloquent
 */
class DeadLetterEntry extends Model
{
    use HasFactory;

    protected $table = 'dead_letter_queue';

    protected $fillable = [
        'task_id',
        'task_record',
        'failure_reason',
        'error_details',
        'attempts',
        'dismissed',
        'dismissed_at',
        'dismissed_by',
        'retried',
        'retried_at',
        'retried_by',
        'retried_task_id',
        'originally_queued_at',
        'dead_lettered_at',
    ];

    /**
     * @return array{
     *   task_record: 'array',
     *   attempts: 'array',
     *   dismissed: 'boolean',
     *   retried: 'boolean',
     *   originally_queued_at: 'datetime',
     *   dead_lettered_at: 'datetime',
     *   dismissed_at: 'datetime',
     *   retried_at: 'datetime',
     * }
     */
    protected function casts(): array
    {
        return [
            'task_record' => 'array',
            'attempts' => 'array',
            'dismissed' => 'boolean',
            'retried' => 'boolean',
            'originally_queued_at' => 'datetime',
            'dead_lettered_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'retried_at' => 'datetime',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<User, $this> */
    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function retriedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retried_by');
    }

    /** @return BelongsTo<Task, $this> */
    public function retriedTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'retried_task_id');
    }

    // ─── Scopes ─────────────────────────────────────────────────────

    /**
     * Active DLQ entries: not dismissed and not retried.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('dismissed', false)->where('retried', false);
    }
}

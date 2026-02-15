<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }

    public function retriedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retried_by');
    }

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

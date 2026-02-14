<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeadLetterEntry extends Model
{
    protected $table = 'dead_letter_queue';

    protected $fillable = [
        'task_record',
        'failure_reason',
        'error_details',
        'attempts',
        'dismissed',
        'dismissed_at',
        'dismissed_by',
        'originally_queued_at',
        'dead_lettered_at',
    ];

    protected function casts(): array
    {
        return [
            'task_record' => 'array',
            'attempts' => 'array',
            'dismissed' => 'boolean',
            'originally_queued_at' => 'datetime',
            'dead_lettered_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────

    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }
}

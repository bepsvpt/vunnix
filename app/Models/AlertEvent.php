<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AlertEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'alert_type',
        'status',
        'severity',
        'message',
        'context',
        'detected_at',
        'resolved_at',
        'notified_at',
        'recovery_notified_at',
    ];

    /**
     * @return array{
     *   context: 'array',
     *   detected_at: 'datetime',
     *   resolved_at: 'datetime',
     *   notified_at: 'datetime',
     *   recovery_notified_at: 'datetime',
     * }
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
            'notified_at' => 'datetime',
            'recovery_notified_at' => 'datetime',
        ];
    }

    /**
     * Scope: currently active (unresolved) alert events.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope: alerts of a specific type.
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('alert_type', $type);
    }
}

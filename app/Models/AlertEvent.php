<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $alert_type
 * @property string $status
 * @property string $severity
 * @property string $message
 * @property array<array-key, mixed>|null $context
 * @property \Illuminate\Support\Carbon $detected_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property \Illuminate\Support\Carbon|null $notified_at
 * @property \Illuminate\Support\Carbon|null $recovery_notified_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static Builder<static>|AlertEvent active()
 * @method static \Database\Factories\AlertEventFactory factory($count = null, $state = [])
 * @method static Builder<static>|AlertEvent newModelQuery()
 * @method static Builder<static>|AlertEvent newQuery()
 * @method static Builder<static>|AlertEvent ofType(string $type)
 * @method static Builder<static>|AlertEvent query()
 *
 * @mixin \Eloquent
 */
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
}

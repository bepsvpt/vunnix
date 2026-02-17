<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $rule
 * @property string $severity
 * @property string $message
 * @property array<array-key, mixed> $context
 * @property bool $acknowledged
 * @property \Illuminate\Support\Carbon|null $acknowledged_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CostAlert active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CostAlert newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CostAlert newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CostAlert query()
 *
 * @mixin \Eloquent
 */
class CostAlert extends Model
{
    protected $fillable = [
        'rule',
        'severity',
        'message',
        'context',
        'acknowledged',
        'acknowledged_at',
    ];

    public function scopeActive($query)
    {
        return $query->where('acknowledged', false);
    }

    /**
     * @return array{
     *   context: 'array',
     *   acknowledged: 'boolean',
     *   acknowledged_at: 'datetime',
     * }
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'acknowledged' => 'boolean',
            'acknowledged_at' => 'datetime',
        ];
    }
}

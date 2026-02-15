<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OverrelianceAlert extends Model
{
    protected $fillable = [
        'rule',
        'severity',
        'message',
        'context',
        'acknowledged',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'acknowledged' => 'boolean',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('acknowledged', false);
    }
}

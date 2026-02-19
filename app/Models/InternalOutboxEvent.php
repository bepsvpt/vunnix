<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class InternalOutboxEvent extends Model
{
    protected $fillable = [
        'event_id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'schema_version',
        'payload',
        'occurred_at',
        'idempotency_key',
        'status',
        'attempts',
        'available_at',
        'dispatched_at',
        'failed_at',
        'last_error',
    ];

    public function markDispatched(?Carbon $at = null): void
    {
        $this->status = 'dispatched';
        $this->dispatched_at = $at ?? now();
        $this->save();
    }

    public function markFailed(string $error, ?Carbon $at = null): void
    {
        $this->status = 'failed';
        $this->failed_at = $at ?? now();
        $this->last_error = $error;
        $this->attempts++;
        $this->save();
    }

    public function markPendingRetry(?Carbon $availableAt = null, ?string $error = null): void
    {
        $this->status = 'pending';
        $this->available_at = $availableAt ?? now();
        if ($error !== null && $error !== '') {
            $this->last_error = $error;
        }
        $this->attempts++;
        $this->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'available_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'failed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}

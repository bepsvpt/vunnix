<?php

namespace App\Modules\TaskOrchestration\Infrastructure\Outbox;

use App\Models\InternalOutboxEvent;
use App\Modules\Shared\Domain\InternalEvent;
use Illuminate\Support\Facades\DB;

class OutboxWriter
{
    public function write(InternalEvent $event, ?string $idempotencyKey = null): InternalOutboxEvent
    {
        return DB::transaction(function () use ($event, $idempotencyKey): InternalOutboxEvent {
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                /** @var InternalOutboxEvent|null $existing */
                $existing = InternalOutboxEvent::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing instanceof InternalOutboxEvent) {
                    return $existing;
                }
            }

            $attributes = $event->toArray();
            $attributes['idempotency_key'] = $idempotencyKey;
            $attributes['status'] = 'pending';
            $attributes['attempts'] = 0;

            return InternalOutboxEvent::create($attributes);
        });
    }
}

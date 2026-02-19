<?php

namespace App\Modules\Shared\Domain;

use Illuminate\Support\Str;

class InternalEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly string $aggregateType,
        public readonly string $aggregateId,
        public readonly int $schemaVersion,
        public readonly array $payload,
        public readonly string $occurredAt,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function make(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        int $schemaVersion = 1,
    ): self {
        return new self(
            eventId: (string) Str::uuid(),
            eventType: $eventType,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            schemaVersion: $schemaVersion,
            payload: $payload,
            occurredAt: now()->toIso8601String(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'schema_version' => $this->schemaVersion,
            'payload' => $this->payload,
            'occurred_at' => $this->occurredAt,
        ];
    }
}

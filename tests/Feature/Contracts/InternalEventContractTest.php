<?php

use App\Modules\Shared\Domain\InternalEvent;

it('conforms to internal event envelope contract', function (): void {
    $payload = [
        'task_id' => 123,
        'status' => 'running',
    ];

    $event = InternalEvent::make(
        eventType: 'task.status.changed',
        aggregateType: 'task',
        aggregateId: '123',
        payload: $payload,
        schemaVersion: 1,
    )->toArray();

    expect($event)->toHaveKeys([
        'event_id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'schema_version',
        'payload',
        'occurred_at',
    ]);

    expect($event['event_type'])->toBe('task.status.changed')
        ->and($event['aggregate_type'])->toBe('task')
        ->and($event['aggregate_id'])->toBe('123')
        ->and($event['schema_version'])->toBe(1)
        ->and($event['payload'])->toBe($payload);
});

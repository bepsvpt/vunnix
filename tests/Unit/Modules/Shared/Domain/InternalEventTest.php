<?php

use App\Modules\Shared\Domain\InternalEvent;

it('builds internal event envelope with defaults', function (): void {
    $event = InternalEvent::make(
        eventType: 'task.status.changed',
        aggregateType: 'task',
        aggregateId: '123',
        payload: ['status' => 'running'],
    );

    $serialized = $event->toArray();

    expect($serialized['event_id'])->not->toBe('')
        ->and($serialized['event_type'])->toBe('task.status.changed')
        ->and($serialized['aggregate_type'])->toBe('task')
        ->and($serialized['aggregate_id'])->toBe('123')
        ->and($serialized['schema_version'])->toBe(1)
        ->and($serialized['payload'])->toBe(['status' => 'running'])
        ->and($serialized['occurred_at'])->not->toBe('');
});

<?php

use App\Jobs\DeliverOutboxEvents;
use App\Models\InternalOutboxEvent;
use App\Modules\Shared\Domain\InternalEvent;
use App\Modules\TaskOrchestration\Infrastructure\Outbox\OutboxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('deduplicates outbox writes by idempotency key', function (): void {
    $writer = new OutboxWriter;
    $event = InternalEvent::make(
        eventType: 'task.status.changed',
        aggregateType: 'task',
        aggregateId: 'task-1',
        payload: ['status' => 'running'],
    );

    $first = $writer->write($event, 'task-1:running');
    $second = $writer->write($event, 'task-1:running');

    expect($first->id)->toBe($second->id)
        ->and(InternalOutboxEvent::query()->count())->toBe(1);
});

it('delivers pending outbox events and marks them dispatched', function (): void {
    InternalOutboxEvent::create([
        'event_id' => (string) Str::uuid(),
        'event_type' => 'task.status.changed',
        'aggregate_type' => 'task',
        'aggregate_id' => 'task-1',
        'schema_version' => 1,
        'payload' => ['status' => 'running'],
        'occurred_at' => now(),
        'status' => 'pending',
        'attempts' => 0,
    ]);

    (new DeliverOutboxEvents)->handle();

    $outbox = InternalOutboxEvent::query()->firstOrFail();

    expect($outbox->status)->toBe('dispatched')
        ->and($outbox->dispatched_at)->not->toBeNull()
        ->and($outbox->attempts)->toBe(1);
});

it('skips outbox events that are no longer pending when locked', function (): void {
    $event = InternalOutboxEvent::create([
        'event_id' => (string) Str::uuid(),
        'event_type' => 'task.status.changed',
        'aggregate_type' => 'task',
        'aggregate_id' => 'task-1',
        'schema_version' => 1,
        'payload' => ['status' => 'running'],
        'occurred_at' => now(),
        'status' => 'processing',
        'attempts' => 1,
    ]);

    (new DeliverOutboxEvents)->handle();

    $event->refresh();

    expect($event->status)->toBe('processing')
        ->and($event->dispatched_at)->toBeNull()
        ->and($event->attempts)->toBe(1);
});

it('marks event pending retry when delivery throws', function (): void {
    $event = InternalOutboxEvent::create([
        'event_id' => (string) Str::uuid(),
        'event_type' => 'task.status.changed',
        'aggregate_type' => 'task',
        'aggregate_id' => 'task-1',
        'schema_version' => 1,
        'payload' => ['status' => 'running'],
        'occurred_at' => now(),
        'status' => 'pending',
        'attempts' => 0,
    ]);

    Log::shouldReceive('info')->once()->andThrow(new RuntimeException('delivery failure'));

    (new DeliverOutboxEvents)->handle();

    $event->refresh();

    expect($event->status)->toBe('pending')
        ->and($event->attempts)->toBe(2)
        ->and($event->last_error)->toContain('delivery failure')
        ->and($event->available_at)->not->toBeNull();
});

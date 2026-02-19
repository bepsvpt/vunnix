<?php

use App\Models\InternalOutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('marks outbox event as dispatched', function (): void {
    $event = InternalOutboxEvent::create([
        'event_id' => (string) Str::uuid(),
        'event_type' => 'task.status.changed',
        'aggregate_type' => 'task',
        'aggregate_id' => 'task-1',
        'schema_version' => 1,
        'payload' => ['status' => 'running'],
        'occurred_at' => now(),
        'status' => 'pending',
    ]);

    $event->markDispatched();
    $event->refresh();

    expect($event->status)->toBe('dispatched')
        ->and($event->dispatched_at)->not->toBeNull();
});

it('marks outbox event as failed and increments attempts', function (): void {
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

    $event->markFailed('network error');
    $event->refresh();

    expect($event->status)->toBe('failed')
        ->and($event->last_error)->toBe('network error')
        ->and($event->attempts)->toBe(1)
        ->and($event->failed_at)->not->toBeNull();
});

it('marks outbox event pending retry and increments attempts', function (): void {
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

    $event->markPendingRetry(now()->addMinute(), 'temporary failure');
    $event->refresh();

    expect($event->status)->toBe('pending')
        ->and($event->last_error)->toBe('temporary failure')
        ->and($event->attempts)->toBe(1)
        ->and($event->available_at)->not->toBeNull();
});

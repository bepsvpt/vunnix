<?php

use App\Jobs\DeliverOutboxEvents;
use App\Models\InternalOutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('replays failed outbox events and dispatches delivery job', function (): void {
    Queue::fake();

    $event = InternalOutboxEvent::create([
        'event_id' => (string) Str::uuid(),
        'event_type' => 'task.result.processed',
        'aggregate_type' => 'task',
        'aggregate_id' => '1',
        'schema_version' => 1,
        'payload' => ['task_id' => 1],
        'occurred_at' => now(),
        'status' => 'failed',
        'failed_at' => now(),
    ]);

    $this->artisan('outbox:replay --failed-only')
        ->assertSuccessful();

    $event->refresh();

    expect($event->status)->toBe('pending')
        ->and($event->failed_at)->toBeNull()
        ->and($event->available_at)->not->toBeNull();

    Queue::assertPushed(DeliverOutboxEvents::class);
});

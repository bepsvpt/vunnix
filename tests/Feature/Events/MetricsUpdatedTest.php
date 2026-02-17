<?php

use App\Events\MetricsUpdated;
use Illuminate\Broadcasting\PrivateChannel;

test('MetricsUpdated broadcasts on the metrics channel for the given project', function (): void {
    $event = new MetricsUpdated(projectId: 10, data: ['tasks_today' => 5]);

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe('private-metrics.10');
});

test('MetricsUpdated includes project_id, data, and timestamp in broadcast payload', function (): void {
    $event = new MetricsUpdated(projectId: 10, data: ['tasks_today' => 5]);

    $payload = $event->broadcastWith();

    expect($payload)->toHaveKeys(['project_id', 'data', 'timestamp']);
    expect($payload['project_id'])->toBe(10);
    expect($payload['data'])->toBe(['tasks_today' => 5]);
});

test('MetricsUpdated has the correct broadcast event name', function (): void {
    $event = new MetricsUpdated(projectId: 10, data: []);

    expect($event->broadcastAs())->toBe('metrics.updated');
});

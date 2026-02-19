<?php

use App\Events\HealthSnapshotRecorded;
use App\Models\HealthSnapshot;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('builds broadcast payload and channel from snapshot', function (): void {
    $project = Project::factory()->create();
    $snapshot = HealthSnapshot::factory()->create([
        'project_id' => $project->id,
        'dimension' => 'coverage',
        'score' => 88.5,
        'created_at' => now(),
    ]);

    $event = new HealthSnapshotRecorded($snapshot, 'down');

    expect($event->broadcastOn()[0]->name)->toBe("private-project.{$project->id}.health");
    expect($event->broadcastAs())->toBe('health.snapshot.recorded');
    expect($event->broadcastQueue())->toBe('vunnix-server');
    expect($event->broadcastWith()['dimension'])->toBe('coverage');
    expect($event->broadcastWith()['trend_direction'])->toBe('down');
});

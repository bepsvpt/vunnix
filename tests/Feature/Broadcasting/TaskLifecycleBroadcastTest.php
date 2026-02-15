<?php

use App\Enums\TaskStatus;
use App\Events\TaskStatusChanged;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('task completing dispatches broadcast with status and result summary', function () {
    Event::fake([TaskStatusChanged::class]);

    $task = Task::factory()->create([
        'status' => TaskStatus::Running,
        'type' => 'code_review',
        'pipeline_id' => 99,
    ]);

    $task->result = ['summary' => 'All checks passed'];
    $task->transitionTo(TaskStatus::Completed);

    Event::assertDispatched(TaskStatusChanged::class, function ($event) use ($task) {
        $data = $event->broadcastWith();

        return $event->task->id === $task->id
            && $data['status'] === 'completed'
            && $data['type'] === 'code_review'
            && $data['pipeline_id'] === 99
            && $data['result_summary'] === 'All checks passed'
            && $data['project_id'] === $task->project_id;
    });
});

test('task failing dispatches broadcast with failed status', function () {
    Event::fake([TaskStatusChanged::class]);

    $task = Task::factory()->create(['status' => TaskStatus::Running]);

    $task->transitionTo(TaskStatus::Failed, 'Runner timeout');

    Event::assertDispatched(TaskStatusChanged::class, function ($event) {
        return $event->task->status === TaskStatus::Failed
            && $event->broadcastWith()['status'] === 'failed';
    });
});

test('activity feed channel receives task status events', function () {
    Event::fake([TaskStatusChanged::class]);

    $task = Task::factory()->create(['status' => TaskStatus::Queued]);

    $task->transitionTo(TaskStatus::Running);

    Event::assertDispatched(TaskStatusChanged::class, function ($event) use ($task) {
        $channels = collect($event->broadcastOn())->map->name;
        return $channels->contains("private-project.{$task->project_id}.activity");
    });
});

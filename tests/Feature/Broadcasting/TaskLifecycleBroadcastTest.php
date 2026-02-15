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

test('includes pipeline_status in broadcast payload', function () {
    Event::fake([TaskStatusChanged::class]);

    $task = Task::factory()->create([
        'status' => TaskStatus::Queued,
        'pipeline_id' => 12345,
        'pipeline_status' => 'pending',
    ]);

    $task->transitionTo(TaskStatus::Running);

    Event::assertDispatched(TaskStatusChanged::class, function ($event) {
        $payload = $event->broadcastWith();
        return array_key_exists('pipeline_status', $payload)
            && $payload['pipeline_status'] === 'pending';
    });
});

test('includes null pipeline_status when not set', function () {
    Event::fake([TaskStatusChanged::class]);

    $task = Task::factory()->create([
        'status' => TaskStatus::Queued,
        'pipeline_id' => null,
        'pipeline_status' => null,
    ]);

    $task->transitionTo(TaskStatus::Running);

    Event::assertDispatched(TaskStatusChanged::class, function ($event) {
        $payload = $event->broadcastWith();
        return array_key_exists('pipeline_status', $payload)
            && $payload['pipeline_status'] === null;
    });
});

test('includes title, started_at, and conversation_id in broadcast payload', function () {
    Event::fake([TaskStatusChanged::class]);

    $task = Task::factory()->create([
        'status' => TaskStatus::Queued,
        'conversation_id' => 'conv-abc-123',
        'result' => ['title' => 'Implement payment flow'],
    ]);

    $task->transitionTo(TaskStatus::Running);

    Event::assertDispatched(TaskStatusChanged::class, function ($event) {
        $payload = $event->broadcastWith();
        return array_key_exists('title', $payload)
            && $payload['title'] === 'Implement payment flow'
            && array_key_exists('started_at', $payload)
            && $payload['started_at'] !== null
            && array_key_exists('conversation_id', $payload)
            && $payload['conversation_id'] === 'conv-abc-123';
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

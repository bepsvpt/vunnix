<?php

use App\Events\TaskStatusChanged;
use App\Models\Task;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('TaskStatusChanged broadcasts on private task channel', function () {
    $task = Task::factory()->create(['status' => 'completed']);

    $event = new TaskStatusChanged($task);

    expect($event->broadcastOn())
        ->toBeArray()
        ->toHaveCount(2);

    $channels = $event->broadcastOn();
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe("private-task.{$task->id}");
    expect($channels[1])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[1]->name)->toBe("private-project.{$task->project_id}.activity");
});

test('TaskStatusChanged payload includes status and task summary', function () {
    $task = Task::factory()->create([
        'status' => 'completed',
        'type' => 'code_review',
        'pipeline_id' => 12345,
    ]);

    $event = new TaskStatusChanged($task);
    $data = $event->broadcastWith();

    expect($data)->toHaveKeys(['task_id', 'status', 'type', 'project_id', 'pipeline_id', 'timestamp']);
    expect($data['task_id'])->toBe($task->id);
    expect($data['status'])->toBe('completed');
    expect($data['type'])->toBe('code_review');
    expect($data['project_id'])->toBe($task->project_id);
    expect($data['pipeline_id'])->toBe(12345);
});

test('TaskStatusChanged event name is task.status.changed', function () {
    $task = Task::factory()->create();

    $event = new TaskStatusChanged($task);

    expect($event->broadcastAs())->toBe('task.status.changed');
});

<?php

use App\Enums\TaskStatus;
use App\Events\TaskStatusChanged;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('TaskObserver dispatches TaskStatusChanged on status transition', function (): void {
    Event::fake([TaskStatusChanged::class]);

    $task = Task::factory()->create(['status' => TaskStatus::Queued]);

    $task->transitionTo(TaskStatus::Running);

    Event::assertDispatched(TaskStatusChanged::class, function ($event) use ($task): bool {
        return $event->task->id === $task->id
            && $event->task->status === TaskStatus::Running;
    });
});

test('TaskObserver does not dispatch TaskStatusChanged when non-status field changes', function (): void {
    Event::fake([TaskStatusChanged::class]);

    $task = Task::factory()->create(['status' => TaskStatus::Running]);

    $task->update(['tokens_used' => 500]);

    Event::assertNotDispatched(TaskStatusChanged::class);
});

test('TaskObserver dispatches on terminal transitions with result data', function (): void {
    Event::fake([TaskStatusChanged::class]);

    $task = Task::factory()->create(['status' => TaskStatus::Running]);

    $task->result = ['summary' => 'Review complete', 'severity' => 'clean'];
    $task->transitionTo(TaskStatus::Completed);

    Event::assertDispatched(TaskStatusChanged::class, function ($event): bool {
        return $event->task->status === TaskStatus::Completed;
    });
});

<?php

use App\Enums\TaskStatus;
use App\Events\TaskStatusChanged;
use App\Listeners\SendTaskCompletionNotification;
use App\Models\Task;
use App\Services\AlertEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('returns early for non-terminal task statuses', function (): void {
    $task = Task::factory()->create(['status' => TaskStatus::Running, 'started_at' => now()]);

    $alertService = Mockery::mock(AlertEventService::class);
    $alertService->shouldNotReceive('notifyTaskCompletion');

    $listener = new SendTaskCompletionNotification($alertService);
    $listener->handle(new TaskStatusChanged($task));
});

it('returns early when cache key already exists (notification already sent)', function (): void {
    $task = Task::factory()->create(['status' => TaskStatus::Completed, 'started_at' => now(), 'completed_at' => now()]);

    // Pre-populate cache to simulate already-sent notification
    Cache::put("task_chat_notified:{$task->id}", true, now()->addHours(24));

    $alertService = Mockery::mock(AlertEventService::class);
    $alertService->shouldNotReceive('notifyTaskCompletion');

    $listener = new SendTaskCompletionNotification($alertService);
    $listener->handle(new TaskStatusChanged($task));
});

it('sends notification for completed task and sets cache', function (): void {
    $task = Task::factory()->create(['status' => TaskStatus::Completed, 'started_at' => now(), 'completed_at' => now()]);

    $alertService = Mockery::mock(AlertEventService::class);
    $alertService->shouldReceive('notifyTaskCompletion')
        ->once()
        ->with(Mockery::on(fn ($t) => $t->id === $task->id));

    $listener = new SendTaskCompletionNotification($alertService);
    $listener->handle(new TaskStatusChanged($task));

    expect(Cache::has("task_chat_notified:{$task->id}"))->toBeTrue();
});

it('sends notification for failed task', function (): void {
    $task = Task::factory()->create(['status' => TaskStatus::Failed, 'error_reason' => 'timeout']);

    $alertService = Mockery::mock(AlertEventService::class);
    $alertService->shouldReceive('notifyTaskCompletion')
        ->once()
        ->with(Mockery::on(fn ($t) => $t->id === $task->id));

    $listener = new SendTaskCompletionNotification($alertService);
    $listener->handle(new TaskStatusChanged($task));

    expect(Cache::has("task_chat_notified:{$task->id}"))->toBeTrue();
});

it('logs warning when notifyTaskCompletion throws an exception', function (): void {
    $task = Task::factory()->create(['status' => TaskStatus::Completed, 'started_at' => now(), 'completed_at' => now()]);

    $alertService = Mockery::mock(AlertEventService::class);
    $alertService->shouldReceive('notifyTaskCompletion')
        ->once()
        ->andThrow(new RuntimeException('Connection refused'));

    Log::shouldReceive('warning')
        ->once()
        ->with('SendTaskCompletionNotification: failed to send', Mockery::on(function (array $context) use ($task): bool {
            return $context['task_id'] === $task->id
                && $context['error'] === 'Connection refused';
        }));

    $listener = new SendTaskCompletionNotification($alertService);
    $listener->handle(new TaskStatusChanged($task));

    // Cache should NOT be set because the notification failed
    expect(Cache::has("task_chat_notified:{$task->id}"))->toBeFalse();
});

it('does not send notification for superseded task', function (): void {
    $task = Task::factory()->create(['status' => TaskStatus::Superseded]);

    $alertService = Mockery::mock(AlertEventService::class);
    $alertService->shouldNotReceive('notifyTaskCompletion');

    $listener = new SendTaskCompletionNotification($alertService);
    $listener->handle(new TaskStatusChanged($task));
});

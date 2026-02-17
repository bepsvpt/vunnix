<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\PostFailureComment;
use App\Models\DeadLetterEntry;
use App\Models\Task;
use App\Services\FailureHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ─── Creates DLQ entry on permanent failure ──────────────────────

it('creates a DLQ entry when a task fails permanently', function (): void {
    Queue::fake();

    $task = Task::factory()->create([
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'retry_count' => 3,
    ]);

    $handler = new FailureHandler;
    $handler->handlePermanentFailure(
        task: $task,
        failureReason: 'max_retries_exceeded',
        errorDetails: 'HTTP 503: Service Unavailable',
        attempts: [
            ['attempt' => 1, 'timestamp' => '2026-02-14T10:00:00Z', 'error' => 'HTTP 503'],
            ['attempt' => 2, 'timestamp' => '2026-02-14T10:00:30Z', 'error' => 'HTTP 503'],
            ['attempt' => 3, 'timestamp' => '2026-02-14T10:02:30Z', 'error' => 'HTTP 503'],
        ],
    );

    // Task should be Failed
    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed);
    expect($task->error_reason)->toBe('max_retries_exceeded');

    // DLQ entry should exist
    expect(DeadLetterEntry::count())->toBe(1);

    $entry = DeadLetterEntry::first();
    expect($entry->failure_reason)->toBe('max_retries_exceeded');
    expect($entry->error_details)->toBe('HTTP 503: Service Unavailable');
    expect($entry->attempts)->toHaveCount(3);
    expect($entry->task_record)->toBeArray();
    expect($entry->task_record['id'])->toBe($task->id);
    expect($entry->dead_lettered_at)->not->toBeNull();
});

// ─── Dispatches failure comment job ──────────────────────────────

it('dispatches PostFailureComment job on permanent failure', function (): void {
    Queue::fake();

    $task = Task::factory()->create([
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
    ]);

    $handler = new FailureHandler;
    $handler->handlePermanentFailure(
        task: $task,
        failureReason: 'max_retries_exceeded',
        errorDetails: 'HTTP 503',
        attempts: [],
    );

    Queue::assertPushed(PostFailureComment::class, function ($job) use ($task) {
        return $job->taskId === $task->id
            && $job->failureReason === 'max_retries_exceeded'
            && $job->errorDetails === 'HTTP 503';
    });
});

// ─── Skips failure comment for tasks with no MR and no Issue ─────

it('does not dispatch PostFailureComment for tasks without MR or Issue', function (): void {
    Queue::fake();

    $task = Task::factory()->create([
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => null,
        'issue_iid' => null,
    ]);

    $handler = new FailureHandler;
    $handler->handlePermanentFailure(
        task: $task,
        failureReason: 'max_retries_exceeded',
        errorDetails: 'HTTP 503',
        attempts: [],
    );

    Queue::assertNotPushed(PostFailureComment::class);

    // DLQ entry should still be created
    expect(DeadLetterEntry::count())->toBe(1);
});

// ─── Handles task already in terminal state ──────────────────────

it('does not transition or DLQ a task already in terminal state', function (): void {
    Queue::fake();

    $task = Task::factory()->create([
        'status' => TaskStatus::Completed,
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
    ]);

    $handler = new FailureHandler;
    $handler->handlePermanentFailure(
        task: $task,
        failureReason: 'max_retries_exceeded',
        errorDetails: 'HTTP 503',
        attempts: [],
    );

    // Should not create DLQ entry — task already completed
    expect(DeadLetterEntry::count())->toBe(0);
    Queue::assertNotPushed(PostFailureComment::class);

    // Status should remain Completed
    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Completed);
});

// ─── Snapshots full task record ──────────────────────────────────

it('snapshots the full task record including relationships', function (): void {
    Queue::fake();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
        'commit_sha' => 'abc123',
        'retry_count' => 3,
    ]);

    $handler = new FailureHandler;
    $handler->handlePermanentFailure(
        task: $task,
        failureReason: 'max_retries_exceeded',
        errorDetails: 'HTTP 503',
        attempts: [],
    );

    $entry = DeadLetterEntry::first();
    expect($entry->task_record['type'])->toBe('code_review');
    expect($entry->task_record['mr_iid'])->toBe(42);
    expect($entry->task_record['commit_sha'])->toBe('abc123');
    expect($entry->originally_queued_at)->not->toBeNull();
});

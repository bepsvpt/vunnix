<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Exceptions\GitLabApiException;
use App\Jobs\PostFailureComment;
use App\Jobs\ProcessTask;
use App\Models\DeadLetterEntry;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ─── failed() creates DLQ entry ──────────────────────────────────

it('creates a DLQ entry when ProcessTask fails permanently', function () {
    Queue::fake();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
    ]);

    $exception = new GitLabApiException(
        message: 'HTTP 503: Service Unavailable',
        statusCode: 503,
        responseBody: 'Service Unavailable',
        context: 'triggerPipeline',
    );

    $job = new ProcessTask($task->id);
    $job->failed($exception);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed);
    expect($task->error_reason)->toBe('max_retries_exceeded');

    expect(DeadLetterEntry::count())->toBe(1);
    $entry = DeadLetterEntry::first();
    expect($entry->failure_reason)->toBe('max_retries_exceeded');
    expect($entry->error_details)->toContain('503');
});

// ─── failed() classifies 400 as invalid_request ─────────────────

it('classifies 400 errors as invalid_request in DLQ', function () {
    Queue::fake();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
    ]);

    $exception = new GitLabApiException(
        message: 'HTTP 400: Bad Request',
        statusCode: 400,
        responseBody: '{"error":"invalid"}',
        context: 'triggerPipeline',
    );

    $job = new ProcessTask($task->id);
    $job->failed($exception);

    $entry = DeadLetterEntry::first();
    expect($entry->failure_reason)->toBe('invalid_request');
});

// ─── failed() dispatches PostFailureComment ──────────────────────

it('dispatches PostFailureComment when task has MR', function () {
    Queue::fake();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
    ]);

    $exception = new GitLabApiException(
        message: 'HTTP 503',
        statusCode: 503,
        responseBody: '',
        context: 'test',
    );

    $job = new ProcessTask($task->id);
    $job->failed($exception);

    Queue::assertPushed(PostFailureComment::class, function ($job) use ($task) {
        return $job->taskId === $task->id;
    });
});

// ─── failed() handles non-GitLabApiException ─────────────────────

it('handles non-GitLabApiException in failed() method', function () {
    Queue::fake();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
    ]);

    $exception = new \RuntimeException('Something unexpected happened');

    $job = new ProcessTask($task->id);
    $job->failed($exception);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed);

    $entry = DeadLetterEntry::first();
    expect($entry->failure_reason)->toBe('max_retries_exceeded');
    expect($entry->error_details)->toContain('Something unexpected happened');
});

// ─── failed() skips if task not found ────────────────────────────

it('does not throw if task does not exist', function () {
    Queue::fake();

    $job = new ProcessTask(999999);
    $job->failed(new \RuntimeException('test'));

    // Should not throw — no DLQ entry either
    expect(DeadLetterEntry::count())->toBe(0);
});

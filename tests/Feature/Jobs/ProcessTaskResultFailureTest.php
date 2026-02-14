<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Exceptions\GitLabApiException;
use App\Jobs\PostFailureComment;
use App\Jobs\ProcessTaskResult;
use App\Models\DeadLetterEntry;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ─── failed() creates DLQ entry ──────────────────────────────────

it('creates a DLQ entry when ProcessTaskResult fails permanently', function () {
    Queue::fake();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
    ]);

    $exception = new GitLabApiException(
        message: 'HTTP 500',
        statusCode: 500,
        responseBody: 'Internal Server Error',
        context: 'processResult',
    );

    $job = new ProcessTaskResult($task->id);
    $job->failed($exception);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed);

    expect(DeadLetterEntry::count())->toBe(1);
    $entry = DeadLetterEntry::first();
    expect($entry->failure_reason)->toBe('max_retries_exceeded');
});

// ─── failed() dispatches PostFailureComment ──────────────────────

it('dispatches PostFailureComment when ProcessTaskResult fails', function () {
    Queue::fake();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
    ]);

    $exception = new \RuntimeException('Processing error');

    $job = new ProcessTaskResult($task->id);
    $job->failed($exception);

    Queue::assertPushed(PostFailureComment::class);
});

// ─── failed() classifies 400 as invalid_request ─────────────────

it('classifies 400 errors as invalid_request', function () {
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
        context: 'processResult',
    );

    $job = new ProcessTaskResult($task->id);
    $job->failed($exception);

    $entry = DeadLetterEntry::first();
    expect($entry->failure_reason)->toBe('invalid_request');
});

// ─── failed() skips if task not found ────────────────────────────

it('does not throw if task does not exist', function () {
    Queue::fake();

    $job = new ProcessTaskResult(999999);
    $job->failed(new \RuntimeException('test'));

    expect(DeadLetterEntry::count())->toBe(0);
});

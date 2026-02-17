<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\ProcessTask;
use App\Models\DeadLetterEntry;
use App\Models\Task;
use App\Models\User;
use App\Services\DeadLetterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ─── Retry creates new task and marks DLQ entry ──────────────────

it('retries a DLQ entry by creating a new queued task', function (): void {
    Queue::fake();

    $originalTask = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Failed,
        'mr_iid' => 42,
        'commit_sha' => 'abc123',
        'retry_count' => 3,
        'error_reason' => 'max_retries_exceeded',
        'started_at' => now()->subMinutes(10),
    ]);

    $entry = DeadLetterEntry::create([
        'task_id' => $originalTask->id,
        'task_record' => $originalTask->toArray(),
        'failure_reason' => 'max_retries_exceeded',
        'error_details' => 'HTTP 503',
        'attempts' => [
            ['attempt' => 1, 'timestamp' => '2026-02-14T10:00:00Z', 'error' => 'HTTP 503'],
        ],
        'originally_queued_at' => $originalTask->created_at,
        'dead_lettered_at' => now(),
    ]);

    $admin = User::factory()->create();

    $service = new DeadLetterService;
    $newTask = $service->retry($entry, $admin);

    // DLQ entry should be marked as retried
    $entry->refresh();
    expect($entry->retried)->toBeTrue();
    expect($entry->retried_at)->not->toBeNull();
    expect($entry->retried_by)->toBe($admin->id);
    expect($entry->retried_task_id)->toBe($newTask->id);

    // New task should be created and queued
    expect($newTask->type)->toBe(TaskType::CodeReview);
    expect($newTask->status)->toBe(TaskStatus::Queued);
    expect($newTask->project_id)->toBe($originalTask->project_id);
    expect($newTask->mr_iid)->toBe(42);
    expect($newTask->commit_sha)->toBe('abc123');
    expect($newTask->retry_count)->toBeNull();
    expect($newTask->error_reason)->toBeNull();

    // ProcessTask job should be dispatched
    Queue::assertPushed(ProcessTask::class, fn ($job): bool => $job->taskId === $newTask->id);
});

// ─── Retry fails for already retried entry ───────────────────────

it('throws when retrying an already retried DLQ entry', function (): void {
    $entry = DeadLetterEntry::factory()->create([
        'retried' => true,
        'retried_at' => now(),
    ]);

    $admin = User::factory()->create();
    $service = new DeadLetterService;

    $service->retry($entry, $admin);
})->throws(\LogicException::class, 'already been retried');

// ─── Retry fails for dismissed entry ─────────────────────────────

it('throws when retrying a dismissed DLQ entry', function (): void {
    $entry = DeadLetterEntry::factory()->create([
        'dismissed' => true,
        'dismissed_at' => now(),
    ]);

    $admin = User::factory()->create();
    $service = new DeadLetterService;

    $service->retry($entry, $admin);
})->throws(\LogicException::class, 'dismissed');

// ─── Dismiss marks entry as acknowledged ─────────────────────────

it('dismisses a DLQ entry', function (): void {
    $entry = DeadLetterEntry::factory()->create([
        'dismissed' => false,
    ]);

    $admin = User::factory()->create();
    $service = new DeadLetterService;
    $service->dismiss($entry, $admin);

    $entry->refresh();
    expect($entry->dismissed)->toBeTrue();
    expect($entry->dismissed_at)->not->toBeNull();
    expect($entry->dismissed_by)->toBe($admin->id);
});

// ─── Dismiss fails for already dismissed entry ───────────────────

it('throws when dismissing an already dismissed DLQ entry', function (): void {
    $entry = DeadLetterEntry::factory()->create([
        'dismissed' => true,
        'dismissed_at' => now(),
    ]);

    $admin = User::factory()->create();
    $service = new DeadLetterService;

    $service->dismiss($entry, $admin);
})->throws(\LogicException::class, 'already been dismissed');

// ─── Dismiss fails for retried entry ─────────────────────────────

it('throws when dismissing a retried DLQ entry', function (): void {
    $entry = DeadLetterEntry::factory()->create([
        'retried' => true,
        'retried_at' => now(),
    ]);

    $admin = User::factory()->create();
    $service = new DeadLetterService;

    $service->dismiss($entry, $admin);
})->throws(\LogicException::class, 'retried');

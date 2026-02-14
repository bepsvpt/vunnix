<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\PostPlaceholderComment;
use App\Models\Task;
use App\Services\GitLabClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// ─── Posts placeholder and stores note ID ────────────────────────

it('posts placeholder comment to GitLab and stores the note ID', function () {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response([
            'id' => 5555,
            'body' => '🤖 AI Review in progress…',
        ], 201),
    ]);

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
        'comment_id' => null,
    ]);

    $job = new PostPlaceholderComment($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/notes')
            && $request['body'] === '🤖 AI Review in progress…';
    });

    $task->refresh();
    expect($task->comment_id)->toBe(5555);
});

// ─── Skips if task not found ────────────────────────────────────

it('returns early if the task does not exist', function () {
    Http::fake();

    $job = new PostPlaceholderComment(999999);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Skips if task has no MR IID ────────────────────────────────

it('returns early if the task has no mr_iid', function () {
    Http::fake();

    $task = Task::factory()->create([
        'type' => TaskType::IssueDiscussion,
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => null,
    ]);

    $job = new PostPlaceholderComment($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Skips if comment_id already exists (idempotent) ────────────

it('skips posting if task already has a comment_id', function () {
    Http::fake();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
        'comment_id' => 9999,
    ]);

    $job = new PostPlaceholderComment($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();

    $task->refresh();
    expect($task->comment_id)->toBe(9999);
});

// ─── Best-effort: failure does not throw ────────────────────────

it('logs warning but does not throw when GitLab API fails', function () {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response([], 500),
    ]);

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
        'comment_id' => null,
    ]);

    // Should not throw — best-effort
    $job = new PostPlaceholderComment($task->id);
    $job->handle(app(GitLabClient::class));

    $task->refresh();
    expect($task->comment_id)->toBeNull();
});

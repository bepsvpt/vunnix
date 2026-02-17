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

it('posts placeholder comment to GitLab and stores the note ID', function (): void {
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

    Http::assertSent(function (array $request): bool {
        return str_contains($request->url(), '/notes')
            && $request['body'] === '🤖 AI Review in progress…';
    });

    $task->refresh();
    expect($task->comment_id)->toBe(5555);
});

// ─── Skips if task not found ────────────────────────────────────

it('returns early if the task does not exist', function (): void {
    Http::fake();

    $job = new PostPlaceholderComment(999999);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Skips if task has no MR IID ────────────────────────────────

it('returns early if the task has no mr_iid', function (): void {
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

it('skips posting if task already has a comment_id', function (): void {
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

// ─── Incremental review: reuse previous comment_id (T40) ────────

it('reuses previous review comment_id for incremental review on same MR', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes/*' => Http::response([
            'id' => 99001,
            'body' => '🤖 AI Review in progress… (re-reviewing after new commits)',
        ], 200),
    ]);

    $previousTask = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(30),
        'mr_iid' => 42,
        'comment_id' => 99001,
    ]);

    $newTask = Task::factory()->create([
        'project_id' => $previousTask->project_id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
        'comment_id' => null,
    ]);

    $job = new PostPlaceholderComment($newTask->id);
    $job->handle(app(GitLabClient::class));

    $newTask->refresh();
    expect($newTask->comment_id)->toBe(99001);

    // Should PUT (update) existing note, not POST (create) new one
    Http::assertSent(function ($request): bool {
        return $request->method() === 'PUT'
            && str_contains($request->url(), '/notes/99001');
    });
    Http::assertNotSent(function ($request): bool {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/notes');
    });
});

it('falls back to creating new placeholder when updating previous comment fails', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes/99001' => Http::response([], 404),
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response([
            'id' => 8888,
            'body' => '🤖 AI Review in progress…',
        ], 201),
    ]);

    $previousTask = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(30),
        'mr_iid' => 42,
        'comment_id' => 99001,
    ]);

    $newTask = Task::factory()->create([
        'project_id' => $previousTask->project_id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
        'comment_id' => null,
    ]);

    $job = new PostPlaceholderComment($newTask->id);
    $job->handle(app(GitLabClient::class));

    $newTask->refresh();
    expect($newTask->comment_id)->toBe(8888);
});

// ─── Best-effort: failure does not throw ────────────────────────

it('logs warning but does not throw when GitLab API fails', function (): void {
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

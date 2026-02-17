<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\PostFailureComment;
use App\Models\Task;
use App\Services\GitLabClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// ─── Posts failure comment on MR ─────────────────────────────────

it('posts a failure comment on the MR', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response([
            'id' => 1234,
            'body' => 'mocked',
        ], 201),
    ]);

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Failed,
        'mr_iid' => 42,
        'error_reason' => 'max_retries_exceeded',
    ]);

    $job = new PostFailureComment(
        taskId: $task->id,
        failureReason: 'max_retries_exceeded',
        errorDetails: 'HTTP 503: Service Unavailable',
    );
    $job->handle(app(GitLabClient::class));

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return str_contains($request->url(), '/notes')
            && str_contains($request['body'], '🤖 AI review failed')
            && str_contains($request['body'], 'Service Unavailable');
    });
});

// ─── Updates placeholder comment in-place on failure ─────────────

it('updates placeholder comment in-place when comment_id exists', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes/*' => Http::response([
            'id' => 5555,
            'body' => 'updated',
        ], 200),
    ]);

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Failed,
        'mr_iid' => 42,
        'comment_id' => 5555,
        'error_reason' => 'max_retries_exceeded',
    ]);

    $job = new PostFailureComment(
        taskId: $task->id,
        failureReason: 'max_retries_exceeded',
        errorDetails: 'HTTP 503: Service Unavailable',
    );
    $job->handle(app(GitLabClient::class));

    // Should use PUT (update), not POST (create)
    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return $request->method() === 'PUT'
            && str_contains($request->url(), '/notes/5555')
            && str_contains($request['body'], '🤖 AI review failed');
    });
});

// ─── Posts failure comment on Issue ──────────────────────────────

it('posts a failure comment on an Issue when task has issue_iid', function (): void {
    Http::fake([
        '*/api/v4/projects/*/issues/*/notes' => Http::response([
            'id' => 5678,
            'body' => 'mocked',
        ], 201),
    ]);

    $task = Task::factory()->create([
        'type' => TaskType::IssueDiscussion,
        'status' => TaskStatus::Failed,
        'mr_iid' => null,
        'issue_iid' => 10,
        'error_reason' => 'invalid_request',
    ]);

    $job = new PostFailureComment(
        taskId: $task->id,
        failureReason: 'invalid_request',
        errorDetails: 'HTTP 400: Bad Request',
    );
    $job->handle(app(GitLabClient::class));

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return str_contains($request->url(), '/issues/')
            && str_contains($request->url(), '/notes')
            && str_contains($request['body'], '🤖 AI review failed');
    });
});

// ─── Skips if task not found ─────────────────────────────────────

it('returns early if the task does not exist', function (): void {
    Http::fake();

    $job = new PostFailureComment(
        taskId: 999999,
        failureReason: 'max_retries_exceeded',
        errorDetails: 'HTTP 503',
    );
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Skips if no MR and no Issue ─────────────────────────────────

it('returns early if task has no MR and no Issue', function (): void {
    Http::fake();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Failed,
        'mr_iid' => null,
        'issue_iid' => null,
    ]);

    $job = new PostFailureComment(
        taskId: $task->id,
        failureReason: 'max_retries_exceeded',
        errorDetails: 'HTTP 503',
    );
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Failure comment includes human-readable reason ──────────────

it('formats the failure reason as human-readable text', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response([
            'id' => 1234,
            'body' => 'mocked',
        ], 201),
    ]);

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Failed,
        'mr_iid' => 42,
    ]);

    $job = new PostFailureComment(
        taskId: $task->id,
        failureReason: 'context_exceeded',
        errorDetails: 'Input too large for context window',
    );
    $job->handle(app(GitLabClient::class));

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        $body = $request['body'];

        return str_contains($body, 'too large')
            || str_contains($body, 'context window');
    });
});

// ─── Best-effort: logs but does not re-throw on GitLab API error ─

it('catches and logs GitLab API errors without re-throwing', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response('Server Error', 500),
    ]);

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Failed,
        'mr_iid' => 42,
    ]);

    // Should not throw
    $job = new PostFailureComment(
        taskId: $task->id,
        failureReason: 'max_retries_exceeded',
        errorDetails: 'HTTP 503',
    );
    $job->handle(app(GitLabClient::class));

    // If we get here without exception, the test passes
    expect(true)->toBeTrue();
});

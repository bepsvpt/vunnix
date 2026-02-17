<?php

use App\Exceptions\GitLabApiException;
use App\Jobs\PostIssueComment;
use App\Models\Task;
use App\Services\GitLabClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

// ─── Success path ────────────────────────────────────────────

it('posts issue comment to GitLab and stores note ID', function (): void {
    Http::fake([
        '*/api/v4/projects/*/issues/*/notes' => Http::response([
            'id' => 4321,
            'body' => 'mocked',
        ], 201),
    ]);

    $task = Task::factory()->running()->create([
        'mr_iid' => null,
        'issue_iid' => 15,
        'result' => [
            'response' => 'Here is the analysis of this issue.',
        ],
    ]);

    $job = new PostIssueComment($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return str_contains($request->url(), '/issues/15/notes')
            && str_contains($request['body'], 'AI Response')
            && str_contains($request['body'], 'Here is the analysis of this issue.');
    });

    $task->refresh();
    expect($task->comment_id)->toBe(4321);
});

// ─── Task not found ──────────────────────────────────────────

it('logs warning and returns when task is not found', function (): void {
    Http::fake();

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'PostIssueComment: task not found'
                && $context['task_id'] === 999999;
        });

    $job = new PostIssueComment(999999);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Task has no issue_iid ───────────────────────────────────

it('logs info and returns when task has no issue_iid', function (): void {
    Http::fake();

    $task = Task::factory()->running()->create([
        'mr_iid' => null,
        'issue_iid' => null,
        'result' => ['response' => 'test'],
    ]);

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context) use ($task): bool {
            return $message === 'PostIssueComment: task has no Issue, skipping'
                && $context['task_id'] === $task->id;
        });

    $job = new PostIssueComment($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Task has no result ──────────────────────────────────────

it('logs info and returns when task has no result', function (): void {
    Http::fake();

    $task = Task::factory()->running()->create([
        'mr_iid' => null,
        'issue_iid' => 15,
        'result' => null,
    ]);

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context) use ($task): bool {
            return $message === 'PostIssueComment: task has no result, skipping'
                && $context['task_id'] === $task->id;
        });

    $job = new PostIssueComment($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── GitLab API throws ───────────────────────────────────────

it('logs warning and rethrows when GitLab API fails', function (): void {
    Http::fake([
        '*/api/v4/projects/*/issues/*/notes' => Http::response('Server Error', 500),
    ]);

    $task = Task::factory()->running()->create([
        'mr_iid' => null,
        'issue_iid' => 15,
        'result' => [
            'response' => 'Some analysis.',
        ],
    ]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'GitLab API error');
        });

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($task): bool {
            return $message === 'PostIssueComment: failed to post comment'
                && $context['task_id'] === $task->id;
        });

    $job = new PostIssueComment($task->id);

    expect(fn () => $job->handle(app(GitLabClient::class)))
        ->toThrow(GitLabApiException::class);
});

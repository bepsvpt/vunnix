<?php

use App\Exceptions\GitLabApiException;
use App\Jobs\PostAnswerComment;
use App\Models\Task;
use App\Services\GitLabClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

// ─── Success path ────────────────────────────────────────────

it('posts answer comment to GitLab MR and stores note ID', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response([
            'id' => 5678,
            'body' => 'mocked',
        ], 201),
    ]);

    $task = Task::factory()->running()->create([
        'mr_iid' => 42,
        'result' => [
            'question' => 'What does this function do?',
            'answer' => 'It processes the input data.',
        ],
    ]);

    $job = new PostAnswerComment($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return str_contains($request->url(), '/merge_requests/42/notes')
            && str_contains($request['body'], 'Answer')
            && str_contains($request['body'], 'What does this function do?')
            && str_contains($request['body'], 'It processes the input data.');
    });

    $task->refresh();
    expect($task->comment_id)->toBe(5678);
});

// ─── Task not found ──────────────────────────────────────────

it('logs warning and returns when task is not found', function (): void {
    Http::fake();

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'PostAnswerComment: task not found'
                && $context['task_id'] === 999999;
        });

    $job = new PostAnswerComment(999999);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Task has no MR ──────────────────────────────────────────

it('logs info and returns when task has no MR', function (): void {
    Http::fake();

    $task = Task::factory()->running()->create([
        'mr_iid' => null,
        'result' => ['question' => 'test', 'answer' => 'test'],
    ]);

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context) use ($task): bool {
            return $message === 'PostAnswerComment: task has no MR, skipping'
                && $context['task_id'] === $task->id;
        });

    $job = new PostAnswerComment($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Task has no result ──────────────────────────────────────

it('logs info and returns when task has no result', function (): void {
    Http::fake();

    $task = Task::factory()->running()->create([
        'mr_iid' => 42,
        'result' => null,
    ]);

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context) use ($task): bool {
            return $message === 'PostAnswerComment: task has no result, skipping'
                && $context['task_id'] === $task->id;
        });

    $job = new PostAnswerComment($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── GitLab API throws ───────────────────────────────────────

it('logs warning and rethrows when GitLab API fails', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response('Server Error', 500),
    ]);

    $task = Task::factory()->running()->create([
        'mr_iid' => 42,
        'result' => [
            'question' => 'What does this do?',
            'answer' => 'Something.',
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
            return $message === 'PostAnswerComment: failed to post comment'
                && $context['task_id'] === $task->id;
        });

    $job = new PostAnswerComment($task->id);

    expect(fn () => $job->handle(app(GitLabClient::class)))
        ->toThrow(GitLabApiException::class);
});

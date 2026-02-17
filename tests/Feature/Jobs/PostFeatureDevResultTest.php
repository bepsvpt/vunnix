<?php

use App\Exceptions\GitLabApiException;
use App\Jobs\PostFeatureDevResult;
use App\Models\Task;
use App\Services\GitLabClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('updates existing MR instead of creating new one when existing_mr_iid is set', function (): void {
    $task = Task::factory()->running()->create([
        'type' => 'ui_adjustment',
        'mr_iid' => 456,
        'issue_iid' => 10,
        'result' => [
            'existing_mr_iid' => 456,
            'branch' => 'ai/fix-card-padding',
            'mr_title' => 'Fix card padding',
            'mr_description' => 'Reduced padding on mobile',
            'files_changed' => [
                ['path' => 'styles/card.css', 'action' => 'modified', 'summary' => 'Padding fix'],
            ],
        ],
    ]);

    Http::fake([
        // Should call PUT (update), not POST (create)
        '*/merge_requests/456' => Http::response([
            'iid' => 456,
            'title' => 'Fix card padding',
            'web_url' => 'https://gitlab.example.com/-/merge_requests/456',
        ], 200),
        '*/issues/10/notes' => Http::response(['id' => 999], 201),
    ]);

    $job = new PostFeatureDevResult($task->id);
    $job->handle(app(GitLabClient::class));

    // MR IID should remain unchanged (456, not a new one)
    $task->refresh();
    expect($task->mr_iid)->toBe(456);

    // Should have called PUT (update), not POST (create) for the MR
    Http::assertSent(function ($request): bool {
        return $request->method() === 'PUT'
            && str_contains($request->url(), 'merge_requests/456');
    });

    Http::assertNotSent(function ($request): bool {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'merge_requests');
    });
});

it('skips issue summary when task has no issue_iid (conversation origin)', function (): void {
    $task = Task::factory()->running()->create([
        'type' => 'ui_adjustment',
        'mr_iid' => null,
        'issue_iid' => null,
        'conversation_id' => 'conv-designer-123',
        'result' => [
            'branch' => 'ai/fix-padding',
            'mr_title' => 'Fix card padding',
            'mr_description' => 'Reduced padding',
            'files_changed' => [],
        ],
    ]);

    Http::fake([
        '*/merge_requests' => Http::response([
            'iid' => 789,
            'title' => 'Fix card padding',
        ], 201),
    ]);

    $job = new PostFeatureDevResult($task->id);
    $job->handle(app(GitLabClient::class));

    $task->refresh();
    expect($task->mr_iid)->toBe(789);

    // Should NOT call the issue notes endpoint
    Http::assertNotSent(function ($request): bool {
        return str_contains($request->url(), '/notes');
    });
});

it('creates new MR when existing_mr_iid is not set', function (): void {
    $task = Task::factory()->running()->create([
        'type' => 'feature_dev',
        'mr_iid' => null,
        'issue_iid' => 10,
        'result' => [
            'branch' => 'ai/new-feature',
            'mr_title' => 'Add new feature',
            'mr_description' => 'Feature description',
            'files_changed' => [],
        ],
    ]);

    Http::fake([
        '*/merge_requests' => Http::response([
            'iid' => 789,
            'title' => 'Add new feature',
        ], 201),
        '*/issues/10/notes' => Http::response(['id' => 111], 201),
    ]);

    $job = new PostFeatureDevResult($task->id);
    $job->handle(app(GitLabClient::class));

    $task->refresh();
    expect($task->mr_iid)->toBe(789);

    Http::assertSent(function ($request): bool {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'merge_requests');
    });
});

// ─── Task not found ──────────────────────────────────────────

it('logs warning and returns when task is not found', function (): void {
    Http::fake();

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'PostFeatureDevResult: task not found'
                && $context['task_id'] === 999999;
        });

    $job = new PostFeatureDevResult(999999);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Task has no result ──────────────────────────────────────

it('logs info and returns when task has no result', function (): void {
    Http::fake();

    $task = Task::factory()->running()->create([
        'type' => 'feature_dev',
        'result' => null,
    ]);

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context) use ($task): bool {
            return $message === 'PostFeatureDevResult: task has no result, skipping'
                && $context['task_id'] === $task->id;
        });

    $job = new PostFeatureDevResult($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Missing branch/mr_title returns null ────────────────────

it('logs warning and returns when result is missing branch', function (): void {
    Http::fake();

    $task = Task::factory()->running()->create([
        'type' => 'feature_dev',
        'result' => [
            'mr_title' => 'Some title',
            'mr_description' => 'Some description',
        ],
    ]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($task): bool {
            return $message === 'PostFeatureDevResult: missing branch or mr_title in result'
                && $context['task_id'] === $task->id;
        });

    $job = new PostFeatureDevResult($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

it('logs warning and returns when result is missing mr_title', function (): void {
    Http::fake();

    $task = Task::factory()->running()->create([
        'type' => 'feature_dev',
        'result' => [
            'branch' => 'ai/some-branch',
            'mr_description' => 'Some description',
        ],
    ]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($task): bool {
            return $message === 'PostFeatureDevResult: missing branch or mr_title in result'
                && $context['task_id'] === $task->id;
        });

    $job = new PostFeatureDevResult($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

it('logs warning and returns when branch is empty string', function (): void {
    Http::fake();

    $task = Task::factory()->running()->create([
        'type' => 'feature_dev',
        'result' => [
            'branch' => '',
            'mr_title' => 'Some title',
        ],
    ]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($task): bool {
            return $message === 'PostFeatureDevResult: missing branch or mr_title in result'
                && $context['task_id'] === $task->id;
        });

    $job = new PostFeatureDevResult($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── MR creation exception ───────────────────────────────────

it('logs warning and rethrows when MR creation fails', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests' => Http::response('Server Error', 500),
    ]);

    $task = Task::factory()->running()->create([
        'type' => 'feature_dev',
        'mr_iid' => null,
        'issue_iid' => 10,
        'result' => [
            'branch' => 'ai/new-feature',
            'mr_title' => 'Add new feature',
            'mr_description' => 'Feature description',
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
            return $message === 'PostFeatureDevResult: failed to create merge request'
                && $context['task_id'] === $task->id
                && $context['branch'] === 'ai/new-feature';
        });

    $job = new PostFeatureDevResult($task->id);

    expect(fn () => $job->handle(app(GitLabClient::class)))
        ->toThrow(GitLabApiException::class);
});

// ─── MR update exception (designer iteration) ───────────────

it('logs warning and rethrows when MR update fails', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/456' => Http::response('Server Error', 500),
    ]);

    $task = Task::factory()->running()->create([
        'type' => 'ui_adjustment',
        'mr_iid' => 456,
        'issue_iid' => 10,
        'result' => [
            'existing_mr_iid' => 456,
            'branch' => 'ai/fix-card-padding',
            'mr_title' => 'Fix card padding',
            'mr_description' => 'Updated description',
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
            return $message === 'PostFeatureDevResult: failed to update existing MR'
                && $context['task_id'] === $task->id
                && $context['mr_iid'] === 456;
        });

    $job = new PostFeatureDevResult($task->id);

    expect(fn () => $job->handle(app(GitLabClient::class)))
        ->toThrow(GitLabApiException::class);
});

// ─── Issue summary posting exception ─────────────────────────

it('logs warning and rethrows when issue summary posting fails', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests' => Http::response([
            'iid' => 789,
            'title' => 'Add new feature',
        ], 201),
        '*/api/v4/projects/*/issues/*/notes' => Http::response('Server Error', 500),
    ]);

    $task = Task::factory()->running()->create([
        'type' => 'feature_dev',
        'mr_iid' => null,
        'issue_iid' => 10,
        'result' => [
            'branch' => 'ai/new-feature',
            'mr_title' => 'Add new feature',
            'mr_description' => 'Feature description',
        ],
    ]);

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'PostFeatureDevResult: merge request created';
        });

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'GitLab API error');
        });

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($task): bool {
            return $message === 'PostFeatureDevResult: failed to post issue summary'
                && $context['task_id'] === $task->id;
        });

    $job = new PostFeatureDevResult($task->id);

    expect(fn () => $job->handle(app(GitLabClient::class)))
        ->toThrow(GitLabApiException::class);
});

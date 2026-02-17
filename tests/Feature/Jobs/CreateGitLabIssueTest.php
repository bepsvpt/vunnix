<?php

use App\Enums\TaskOrigin;
use App\Enums\TaskType;
use App\Jobs\CreateGitLabIssue;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// ─── Core: creates GitLab Issue from task result metadata ────

it('creates a GitLab Issue via bot PAT and stores issue_iid on task', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 200]);

    $task = Task::factory()->running()->create([
        'type' => TaskType::PrdCreation,
        'origin' => TaskOrigin::Conversation,
        'project_id' => $project->id,
        'mr_iid' => null,
        'result' => [
            'action_type' => 'create_issue',
            'title' => 'Add user notification preferences',
            'description' => "## Problem\nUsers can't control notification settings.\n\n## Solution\nAdd a preferences page.",
            'assignee_id' => 42,
            'labels' => ['feature', 'ai::created'],
            'dispatched_from' => 'conversation',
        ],
    ]);

    Http::fake([
        '*/api/v4/projects/200/issues' => Http::response([
            'iid' => 15,
            'id' => 1001,
            'title' => 'Add user notification preferences',
            'web_url' => 'https://gitlab.example.com/project/issues/15',
        ], 201),
    ]);

    $job = new CreateGitLabIssue($task->id);
    $job->handle(app(\App\Services\GitLabClient::class));

    $task->refresh();

    expect($task->issue_iid)->toBe(15);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return str_contains($request->url(), '/projects/200/issues')
            && $request['title'] === 'Add user notification preferences'
            && str_contains($request['description'], 'notification settings')
            && $request['assignee_ids'] === [42]
            && $request['labels'] === 'feature,ai::created';
    });
});

it('creates Issue without assignee when assignee_id is not provided', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 300]);

    $task = Task::factory()->running()->create([
        'type' => TaskType::PrdCreation,
        'origin' => TaskOrigin::Conversation,
        'project_id' => $project->id,
        'mr_iid' => null,
        'result' => [
            'action_type' => 'create_issue',
            'title' => 'Simple issue without assignee',
            'description' => 'A basic issue.',
            'dispatched_from' => 'conversation',
        ],
    ]);

    Http::fake([
        '*/api/v4/projects/300/issues' => Http::response([
            'iid' => 7,
            'id' => 1002,
            'title' => 'Simple issue without assignee',
            'web_url' => 'https://gitlab.example.com/project/issues/7',
        ], 201),
    ]);

    $job = new CreateGitLabIssue($task->id);
    $job->handle(app(\App\Services\GitLabClient::class));

    $task->refresh();

    expect($task->issue_iid)->toBe(7);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return str_contains($request->url(), '/projects/300/issues')
            && $request['title'] === 'Simple issue without assignee'
            && ! isset($request['assignee_ids']);
    });
});

it('creates Issue without labels when labels are not provided', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 400]);

    $task = Task::factory()->running()->create([
        'type' => TaskType::PrdCreation,
        'origin' => TaskOrigin::Conversation,
        'project_id' => $project->id,
        'mr_iid' => null,
        'result' => [
            'action_type' => 'create_issue',
            'title' => 'Issue without labels',
            'description' => 'No labels here.',
            'dispatched_from' => 'conversation',
        ],
    ]);

    Http::fake([
        '*/api/v4/projects/400/issues' => Http::response([
            'iid' => 3,
            'id' => 1003,
            'title' => 'Issue without labels',
            'web_url' => 'https://gitlab.example.com/project/issues/3',
        ], 201),
    ]);

    $job = new CreateGitLabIssue($task->id);
    $job->handle(app(\App\Services\GitLabClient::class));

    $task->refresh();

    expect($task->issue_iid)->toBe(3);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return str_contains($request->url(), '/projects/400/issues')
            && ! isset($request['labels']);
    });
});

// ─── Error handling ─────────────────────────────────────────

it('skips if task not found', function (): void {
    Http::fake();

    $job = new CreateGitLabIssue(99999);
    $job->handle(app(\App\Services\GitLabClient::class));

    Http::assertNothingSent();
});

it('skips if task has no result', function (): void {
    Http::fake();

    $task = Task::factory()->running()->create([
        'type' => TaskType::PrdCreation,
        'mr_iid' => null,
        'result' => null,
    ]);

    $job = new CreateGitLabIssue($task->id);
    $job->handle(app(\App\Services\GitLabClient::class));

    Http::assertNothingSent();
});

it('rethrows GitLab API errors for retry', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 500]);

    $task = Task::factory()->running()->create([
        'type' => TaskType::PrdCreation,
        'origin' => TaskOrigin::Conversation,
        'project_id' => $project->id,
        'mr_iid' => null,
        'result' => [
            'action_type' => 'create_issue',
            'title' => 'Will fail',
            'description' => 'This will trigger an API error.',
            'dispatched_from' => 'conversation',
        ],
    ]);

    Http::fake([
        '*/api/v4/projects/500/issues' => Http::response(['error' => 'Forbidden'], 403),
    ]);

    $job = new CreateGitLabIssue($task->id);

    expect(fn () => $job->handle(app(\App\Services\GitLabClient::class)))
        ->toThrow(\App\Exceptions\GitLabApiException::class);
});

// ─── Queue configuration ────────────────────────────────────

it('uses the vunnix-server queue', function (): void {
    $job = new CreateGitLabIssue(1);

    expect($job->queue)->toBe('vunnix-server');
});

it('has retry with backoff middleware', function (): void {
    $job = new CreateGitLabIssue(1);

    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(\App\Jobs\Middleware\RetryWithBackoff::class);
});

// ─── Result metadata enrichment ─────────────────────────────

it('stores gitlab_issue_url in task result after creation', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 600]);

    $task = Task::factory()->running()->create([
        'type' => TaskType::PrdCreation,
        'origin' => TaskOrigin::Conversation,
        'project_id' => $project->id,
        'mr_iid' => null,
        'result' => [
            'action_type' => 'create_issue',
            'title' => 'Track result URL',
            'description' => 'Should store web_url in result.',
            'dispatched_from' => 'conversation',
        ],
    ]);

    Http::fake([
        '*/api/v4/projects/600/issues' => Http::response([
            'iid' => 22,
            'id' => 1004,
            'title' => 'Track result URL',
            'web_url' => 'https://gitlab.example.com/my-project/issues/22',
        ], 201),
    ]);

    $job = new CreateGitLabIssue($task->id);
    $job->handle(app(\App\Services\GitLabClient::class));

    $task->refresh();

    expect($task->result['gitlab_issue_url'])
        ->toBe('https://gitlab.example.com/my-project/issues/22');
});

<?php

/**
 * T56: Server-side execution integration test.
 *
 * Exercises the full pipeline for PrdCreation (create Issue) actions:
 * TaskDispatcher → ProcessTaskResult → CreateGitLabIssue → GitLab API.
 *
 * Uses the sync queue driver so all dispatched jobs run inline,
 * with Http::fake() to capture the GitLab API call.
 */

use App\Enums\TaskOrigin;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\Task;
use App\Services\TaskDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// ─── Full pipeline: TaskDispatcher → ProcessTaskResult → CreateGitLabIssue → GitLab API ─────

it('executes the full server-side pipeline: dispatch → process → create GitLab Issue', function () {
    $project = Project::factory()->create(['gitlab_project_id' => 777]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::PrdCreation,
        'origin' => TaskOrigin::Conversation,
        'project_id' => $project->id,
        'mr_iid' => null,
        'issue_iid' => null,
        'conversation_id' => 'conv-integration-test',
        'result' => [
            'action_type' => 'create_issue',
            'title' => 'Add notification preferences',
            'description' => "## Problem\nUsers cannot manage their notification preferences.\n\n## Solution\nAdd a dedicated settings page.",
            'assignee_id' => 42,
            'labels' => ['feature', 'ai::created'],
            'dispatched_from' => 'conversation',
        ],
    ]);

    Http::fake([
        '*/api/v4/projects/777/issues' => Http::response([
            'iid' => 23,
            'id' => 5001,
            'title' => 'Add notification preferences',
            'web_url' => 'https://gitlab.example.com/my-project/issues/23',
        ], 201),
    ]);

    // Dispatch via TaskDispatcher — the sync queue driver runs the full chain inline:
    // TaskDispatcher::dispatch() → ProcessTaskResult → CreateGitLabIssue → GitLab API
    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    // Task should transition: Queued → Running → Completed, with issue_iid stored
    expect($task->status)->toBe(TaskStatus::Completed);
    expect($task->issue_iid)->toBe(23);
    expect($task->result['gitlab_issue_url'])->toBe('https://gitlab.example.com/my-project/issues/23');

    // GitLab API was called with correct payload
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/projects/777/issues')
            && $request['title'] === 'Add notification preferences'
            && str_contains($request['description'], 'notification preferences')
            && $request['assignee_ids'] === [42]
            && $request['labels'] === 'feature,ai::created';
    });
});

it('completes full pipeline without assignee or labels when not provided', function () {
    $project = Project::factory()->create(['gitlab_project_id' => 888]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::PrdCreation,
        'origin' => TaskOrigin::Conversation,
        'project_id' => $project->id,
        'mr_iid' => null,
        'issue_iid' => null,
        'result' => [
            'action_type' => 'create_issue',
            'title' => 'Minimal Issue',
            'description' => 'A simple description.',
            'dispatched_from' => 'conversation',
        ],
    ]);

    Http::fake([
        '*/api/v4/projects/888/issues' => Http::response([
            'iid' => 5,
            'id' => 5002,
            'title' => 'Minimal Issue',
            'web_url' => 'https://gitlab.example.com/project/issues/5',
        ], 201),
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Completed);
    expect($task->issue_iid)->toBe(5);

    // Verify no assignee_ids or labels were sent
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/projects/888/issues')
            && $request['title'] === 'Minimal Issue'
            && ! isset($request['assignee_ids'])
            && ! isset($request['labels']);
    });
});

it('does not trigger a CI pipeline for server-side PrdCreation tasks', function () {
    $project = Project::factory()->create(['gitlab_project_id' => 999]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::PrdCreation,
        'origin' => TaskOrigin::Conversation,
        'project_id' => $project->id,
        'mr_iid' => null,
        'issue_iid' => null,
        'result' => [
            'action_type' => 'create_issue',
            'title' => 'No pipeline needed',
            'description' => 'This should not trigger a pipeline.',
            'dispatched_from' => 'conversation',
        ],
    ]);

    Http::fake([
        // Only the Issue creation endpoint — no pipeline trigger endpoint
        '*/api/v4/projects/999/issues' => Http::response([
            'iid' => 1,
            'id' => 5003,
            'title' => 'No pipeline needed',
            'web_url' => 'https://gitlab.example.com/project/issues/1',
        ], 201),
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    // No pipeline trigger calls were made (only the Issue creation)
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/issues');
    });

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), '/trigger/pipeline')
            || str_contains($request->url(), '/pipeline');
    });
});

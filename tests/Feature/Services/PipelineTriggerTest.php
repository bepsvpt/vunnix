<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Models\Task;
use App\Services\TaskDispatcher;
use App\Services\TaskTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// ─── Pipeline trigger API call ──────────────────────────────────

it('triggers GitLab pipeline with correct variables for runner task', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 200]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token-abc',
    ]);

    Http::fake([
        '*/api/v4/projects/200/merge_requests/5/changes' => Http::response([
            'changes' => [
                ['new_path' => 'app/Models/User.php'],
            ],
        ]),
        '*/api/v4/projects/200/trigger/pipeline' => Http::response([
            'id' => 12345,
            'status' => 'pending',
            'web_url' => 'https://gitlab.com/project/-/pipelines/12345',
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::CodeReview,
        'project_id' => $project->id,
        'mr_iid' => 5,
        'commit_sha' => 'abc123def456',
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    // Verify pipeline trigger was called
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'trigger/pipeline')) {
            return false;
        }

        $data = $request->data();

        return $data['token'] === 'test-trigger-token-abc'
            && isset($data['variables[VUNNIX_TASK_ID]'])
            && isset($data['variables[VUNNIX_TASK_TYPE]'])
            && isset($data['variables[VUNNIX_STRATEGY]'])
            && isset($data['variables[VUNNIX_SKILLS]'])
            && isset($data['variables[VUNNIX_TOKEN]'])
            && isset($data['variables[VUNNIX_API_URL]']);
    });
});

it('passes correct pipeline variable values', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 200]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'trigger-token-xyz',
    ]);

    Http::fake([
        '*/api/v4/projects/200/merge_requests/5/changes' => Http::response([
            'changes' => [
                ['new_path' => 'app/Models/User.php'],
            ],
        ]),
        '*/api/v4/projects/200/trigger/pipeline' => Http::response([
            'id' => 99,
            'status' => 'pending',
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::CodeReview,
        'project_id' => $project->id,
        'mr_iid' => 5,
        'commit_sha' => 'abc123',
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    Http::assertSent(function ($request) use ($task) {
        if (! str_contains($request->url(), 'trigger/pipeline')) {
            return false;
        }

        $data = $request->data();

        return $data['variables[VUNNIX_TASK_ID]'] === (string) $task->id
            && $data['variables[VUNNIX_TASK_TYPE]'] === 'code_review'
            && $data['variables[VUNNIX_STRATEGY]'] === 'backend-review'
            && $data['variables[VUNNIX_SKILLS]'] === 'backend-review';
    });
});

it('uses MR source branch as pipeline ref', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 200]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/200/merge_requests/5' => Http::response([
            'iid' => 5,
            'source_branch' => 'feature/my-branch',
        ]),
        '*/api/v4/projects/200/merge_requests/5/changes' => Http::response([
            'changes' => [['new_path' => 'app/Models/User.php']],
        ]),
        '*/api/v4/projects/200/trigger/pipeline' => Http::response([
            'id' => 50,
            'status' => 'pending',
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::CodeReview,
        'project_id' => $project->id,
        'mr_iid' => 5,
        'commit_sha' => 'deadbeef123456',
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'trigger/pipeline')) {
            return false;
        }

        return $request->data()['ref'] === 'feature/my-branch';
    });
});

// ─── Pipeline ID storage ────────────────────────────────────────

it('stores pipeline_id on task after successful trigger', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 200]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/200/merge_requests/5/changes' => Http::response([
            'changes' => [['new_path' => 'app/Models/User.php']],
        ]),
        '*/api/v4/projects/200/trigger/pipeline' => Http::response([
            'id' => 77777,
            'status' => 'pending',
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::CodeReview,
        'project_id' => $project->id,
        'mr_iid' => 5,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->pipeline_id)->toBe(77777);
});

// ─── Task token generation ──────────────────────────────────────

it('generates a valid task token as pipeline variable', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 200]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/200/merge_requests/5/changes' => Http::response([
            'changes' => [['new_path' => 'app/Models/User.php']],
        ]),
        '*/api/v4/projects/200/trigger/pipeline' => Http::response([
            'id' => 100,
            'status' => 'pending',
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::CodeReview,
        'project_id' => $project->id,
        'mr_iid' => 5,
    ]);

    $capturedToken = null;
    Http::fake(function ($request) use (&$capturedToken) {
        if (str_contains($request->url(), 'trigger/pipeline')) {
            $capturedToken = $request->data()['variables[VUNNIX_TOKEN]'] ?? null;

            return Http::response(['id' => 100, 'status' => 'pending']);
        }

        // MR changes endpoint
        return Http::response([
            'changes' => [['new_path' => 'app/Models/User.php']],
        ]);
    });

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    expect($capturedToken)->not->toBeNull();

    // Validate the token is legit using the service
    $tokenService = app(TaskTokenService::class);
    expect($tokenService->validate($capturedToken, taskId: $task->id))->toBeTrue();
});

// ─── Missing trigger token ──────────────────────────────────────

it('fails task when project has no ci_trigger_token', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 200]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => null,
    ]);

    Http::fake([
        '*/api/v4/projects/200/merge_requests/5/changes' => Http::response([
            'changes' => [['new_path' => 'app/Models/User.php']],
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::CodeReview,
        'project_id' => $project->id,
        'mr_iid' => 5,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_reason)->toBe('missing_trigger_token');

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'trigger/pipeline');
    });
});

it('fails task when project has no config at all', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 200]);
    // No ProjectConfig created at all

    Http::fake([
        '*/api/v4/projects/200/merge_requests/5/changes' => Http::response([
            'changes' => [['new_path' => 'app/Models/User.php']],
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::CodeReview,
        'project_id' => $project->id,
        'mr_iid' => 5,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_reason)->toBe('missing_trigger_token');
});

// ─── Pipeline trigger failure ───────────────────────────────────

it('fails task when GitLab pipeline trigger API returns error', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 200]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/200/merge_requests/5/changes' => Http::response([
            'changes' => [['new_path' => 'app/Models/User.php']],
        ]),
        '*/api/v4/projects/200/trigger/pipeline' => Http::response(
            ['message' => 'Forbidden'],
            403,
        ),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::CodeReview,
        'project_id' => $project->id,
        'mr_iid' => 5,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_reason)->toBe('pipeline_trigger_failed');
});

// ─── Multi-skill strategy ───────────────────────────────────────

it('passes comma-separated skills for mixed-review strategy', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 200]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/200/merge_requests/5/changes' => Http::response([
            'changes' => [
                ['new_path' => 'app/Http/Controllers/UserController.php'],
                ['new_path' => 'resources/js/components/UserForm.vue'],
            ],
        ]),
        '*/api/v4/projects/200/trigger/pipeline' => Http::response([
            'id' => 100,
            'status' => 'pending',
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::CodeReview,
        'project_id' => $project->id,
        'mr_iid' => 5,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'trigger/pipeline')) {
            return false;
        }

        $data = $request->data();

        return $data['variables[VUNNIX_STRATEGY]'] === 'mixed-review'
            && $data['variables[VUNNIX_SKILLS]'] === 'frontend-review,backend-review';
    });
});

// ─── Server-side tasks skip pipeline trigger ────────────────────

it('does not trigger pipeline for server-side tasks', function (): void {
    Http::fake();

    $task = Task::factory()->queued()->create([
        'type' => TaskType::PrdCreation,
        'mr_iid' => null,
        'issue_iid' => 10,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'trigger/pipeline');
    });

    $task->refresh();
    expect($task->pipeline_id)->toBeNull();
});

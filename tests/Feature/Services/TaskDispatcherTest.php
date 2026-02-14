<?php

use App\Enums\ReviewStrategy;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Models\Task;
use App\Services\GitLabClient;
use App\Services\StrategyResolver;
use App\Services\TaskDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// ─── Execution mode routing ─────────────────────────────────────

it('routes server-side task (PrdCreation) without GitLab API call', function () {
    Http::fake();

    $task = Task::factory()->queued()->create([
        'type' => TaskType::PrdCreation,
        'mr_iid' => null,
        'issue_iid' => 10,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Running);
    Http::assertNothingSent();
});

it('routes runner task (CodeReview) and transitions to running', function () {
    $project = Project::factory()->create(['gitlab_project_id' => 100]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/100/merge_requests/1/changes' => Http::response([
            'changes' => [
                ['new_path' => 'app/Models/User.php', 'old_path' => 'app/Models/User.php'],
            ],
        ]),
        '*/api/v4/projects/100/trigger/pipeline' => Http::response([
            'id' => 1000,
            'status' => 'pending',
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::CodeReview,
        'project_id' => $project->id,
        'mr_iid' => 1,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Running);
});

it('routes runner FeatureDev task to running', function () {
    $project = Project::factory()->create(['gitlab_project_id' => 100]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/100/trigger/pipeline' => Http::response([
            'id' => 1000,
            'status' => 'pending',
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::FeatureDev,
        'project_id' => $project->id,
        'mr_iid' => null,
        'issue_iid' => 5,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Running);
});

// ─── Strategy selection — code review ───────────────────────────

it('selects frontend-review strategy for .vue files', function () {
    $project = Project::factory()->create(['gitlab_project_id' => 100]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/100/merge_requests/1/changes' => Http::response([
            'changes' => [
                ['new_path' => 'src/components/Header.vue'],
                ['new_path' => 'src/pages/Dashboard.vue'],
            ],
        ]),
        '*/api/v4/projects/100/trigger/pipeline' => Http::response([
            'id' => 1000,
            'status' => 'pending',
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::CodeReview,
        'project_id' => $project->id,
        'mr_iid' => 1,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->result['strategy'])->toBe('frontend-review');
});

it('selects backend-review strategy for .php files', function () {
    $project = Project::factory()->create(['gitlab_project_id' => 100]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/100/merge_requests/1/changes' => Http::response([
            'changes' => [
                ['new_path' => 'app/Models/User.php'],
                ['new_path' => 'app/Services/TaskService.php'],
            ],
        ]),
        '*/api/v4/projects/100/trigger/pipeline' => Http::response([
            'id' => 1000,
            'status' => 'pending',
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::CodeReview,
        'project_id' => $project->id,
        'mr_iid' => 1,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->result['strategy'])->toBe('backend-review');
});

it('selects mixed-review strategy for frontend + backend files', function () {
    $project = Project::factory()->create(['gitlab_project_id' => 100]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/100/merge_requests/1/changes' => Http::response([
            'changes' => [
                ['new_path' => 'app/Http/Controllers/UserController.php'],
                ['new_path' => 'resources/js/components/UserForm.vue'],
            ],
        ]),
        '*/api/v4/projects/100/trigger/pipeline' => Http::response([
            'id' => 1000,
            'status' => 'pending',
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::CodeReview,
        'project_id' => $project->id,
        'mr_iid' => 1,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->result['strategy'])->toBe('mixed-review');
});

it('selects security-audit strategy for security-sensitive files', function () {
    $project = Project::factory()->create(['gitlab_project_id' => 100]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/100/merge_requests/1/changes' => Http::response([
            'changes' => [
                ['new_path' => 'config/auth.php'],
                ['new_path' => 'app/Models/User.php'],
            ],
        ]),
        '*/api/v4/projects/100/trigger/pipeline' => Http::response([
            'id' => 1000,
            'status' => 'pending',
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::CodeReview,
        'project_id' => $project->id,
        'mr_iid' => 1,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->result['strategy'])->toBe('security-audit');
});

// ─── Strategy selection — non-review task types ─────────────────

it('uses security-audit strategy for SecurityAudit task type', function () {
    $project = Project::factory()->create(['gitlab_project_id' => 100]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/100/trigger/pipeline' => Http::response([
            'id' => 1000,
            'status' => 'pending',
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::SecurityAudit,
        'project_id' => $project->id,
        'mr_iid' => 1,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->result['strategy'])->toBe('security-audit');
});

it('uses frontend-review strategy for UiAdjustment task type', function () {
    $project = Project::factory()->create(['gitlab_project_id' => 100]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/100/trigger/pipeline' => Http::response([
            'id' => 1000,
            'status' => 'pending',
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::UiAdjustment,
        'project_id' => $project->id,
        'mr_iid' => null,
        'issue_iid' => 1,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->result['strategy'])->toBe('frontend-review');
});

it('uses backend-review strategy for IssueDiscussion task type', function () {
    $project = Project::factory()->create(['gitlab_project_id' => 100]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/100/trigger/pipeline' => Http::response([
            'id' => 1000,
            'status' => 'pending',
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::IssueDiscussion,
        'project_id' => $project->id,
        'mr_iid' => null,
        'issue_iid' => 1,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->result['strategy'])->toBe('backend-review');
});

it('uses backend-review strategy for FeatureDev task type', function () {
    $project = Project::factory()->create(['gitlab_project_id' => 100]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/100/trigger/pipeline' => Http::response([
            'id' => 1000,
            'status' => 'pending',
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::FeatureDev,
        'project_id' => $project->id,
        'mr_iid' => null,
        'issue_iid' => 1,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->result['strategy'])->toBe('backend-review');
});

// ─── Error handling ─────────────────────────────────────────────

it('falls back to mixed-review when GitLab API fails', function () {
    $project = Project::factory()->create(['gitlab_project_id' => 100]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/100/merge_requests/1/changes' => Http::response([], 500),
        '*/api/v4/projects/100/trigger/pipeline' => Http::response([
            'id' => 1000,
            'status' => 'pending',
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::CodeReview,
        'project_id' => $project->id,
        'mr_iid' => 1,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->result['strategy'])->toBe('mixed-review')
        ->and($task->status)->toBe(TaskStatus::Running);
});

// ─── Server-side does not store strategy ────────────────────────

it('does not store strategy for server-side tasks', function () {
    Http::fake();

    $task = Task::factory()->queued()->create([
        'type' => TaskType::PrdCreation,
        'mr_iid' => null,
        'issue_iid' => 10,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->result)->toBeNull();
});

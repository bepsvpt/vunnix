<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\PostPlaceholderComment;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Models\Task;
use App\Services\MemoryInjectionService;
use App\Services\TaskDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ─── Execution mode routing ─────────────────────────────────────

it('routes server-side task (PrdCreation) without GitLab API call', function (): void {
    Queue::fake([\App\Jobs\ProcessTaskResult::class]);
    Http::fake();

    $task = Task::factory()->queued()->create([
        'type' => TaskType::PrdCreation,
        'mr_iid' => null,
        'issue_iid' => 10,
    ]);

    // Pre-seed cache so gitlabWebUrl() returns without an HTTP call.
    // Without this, TaskStatusChanged::broadcastWith() would call the
    // GitLab projects API on cache miss, breaking assertNothingSent().
    Cache::put("project.{$task->project_id}.gitlab_web_url", 'https://gitlab.example.com/project');

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Running);
    Http::assertNothingSent();
});

it('routes runner task (CodeReview) and transitions to running', function (): void {
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
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response(['id' => 1, 'body' => 'placeholder'], 201),
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

it('routes runner FeatureDev task to running', function (): void {
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

it('selects frontend-review strategy for .vue files', function (): void {
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
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response(['id' => 1, 'body' => 'placeholder'], 201),
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

it('selects backend-review strategy for .php files', function (): void {
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
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response(['id' => 1, 'body' => 'placeholder'], 201),
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

it('selects mixed-review strategy for frontend + backend files', function (): void {
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
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response(['id' => 1, 'body' => 'placeholder'], 201),
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

it('selects security-audit strategy for security-sensitive files', function (): void {
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
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response(['id' => 1, 'body' => 'placeholder'], 201),
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

it('uses security-audit strategy for SecurityAudit task type', function (): void {
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
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response(['id' => 1, 'body' => 'placeholder'], 201),
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

it('uses frontend-review strategy for UiAdjustment task type', function (): void {
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

it('uses backend-review strategy for IssueDiscussion task type', function (): void {
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

it('uses backend-review strategy for DeepAnalysis task type', function (): void {
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
        'type' => TaskType::DeepAnalysis,
        'project_id' => $project->id,
        'mr_iid' => null,
        'issue_iid' => null,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->result['strategy'])->toBe('backend-review');
});

it('uses backend-review strategy for FeatureDev task type', function (): void {
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

it('falls back to mixed-review when GitLab API fails', function (): void {
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
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response(['id' => 1, 'body' => 'placeholder'], 201),
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

it('does not store strategy for server-side tasks', function (): void {
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

// ─── T36: Placeholder dispatch ──────────────────────────────────

it('dispatches PostPlaceholderComment for CodeReview with mr_iid', function (): void {
    Queue::fake();

    $project = Project::factory()->create(['gitlab_project_id' => 100]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/100/merge_requests/1/changes' => Http::response([
            'changes' => [
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

    Queue::assertPushed(PostPlaceholderComment::class, function ($job) use ($task): bool {
        return $job->taskId === $task->id;
    });
});

it('dispatches PostPlaceholderComment for SecurityAudit with mr_iid', function (): void {
    Queue::fake();

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

    Queue::assertPushed(PostPlaceholderComment::class, function ($job) use ($task): bool {
        return $job->taskId === $task->id;
    });
});

it('does not dispatch PostPlaceholderComment for tasks without mr_iid', function (): void {
    Queue::fake();

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

    Queue::assertNotPushed(PostPlaceholderComment::class);
});

it('does not dispatch PostPlaceholderComment for non-review task types', function (): void {
    Http::fake();

    $task = Task::factory()->queued()->create([
        'type' => TaskType::PrdCreation,
        'mr_iid' => null,
        'issue_iid' => 10,
    ]);

    // Pre-seed cache so gitlabWebUrl() returns without an HTTP call.
    Cache::put("project.{$task->project_id}.gitlab_web_url", 'https://gitlab.example.com/project');

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    // PrdCreation is server-side — no placeholder needed
    Http::assertNothingSent();
});

// ─── T56: Server-side dispatches ProcessTaskResult ──────────

it('dispatches ProcessTaskResult for server-side PrdCreation tasks', function (): void {
    Queue::fake([\App\Jobs\ProcessTaskResult::class]);
    Http::fake();

    $task = Task::factory()->queued()->create([
        'type' => TaskType::PrdCreation,
        'mr_iid' => null,
        'issue_iid' => null,
        'result' => [
            'action_type' => 'create_issue',
            'title' => 'Test issue',
            'description' => 'Test description.',
            'dispatched_from' => 'conversation',
        ],
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Running);

    Queue::assertPushed(\App\Jobs\ProcessTaskResult::class, function ($job) use ($task): bool {
        return $job->taskId === $task->id;
    });
});

// ─── T92: .vunnix.toml file config ──────────────────────────────

it('reads .vunnix.toml from repo and stores file_config in task result', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 100]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token',
    ]);

    $tomlContent = base64_encode("[general]\nmodel = \"sonnet\"\n\n[code_review]\nauto_review = false");

    Http::fake([
        '*/api/v4/projects/100/repository/files/.vunnix.toml*' => Http::response([
            'content' => $tomlContent,
            'encoding' => 'base64',
        ]),
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
        'commit_sha' => 'abc123',
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->result['file_config'])->toBe([
        'ai_model' => 'sonnet',
        'code_review.auto_review' => false,
    ]);
});

it('gracefully handles missing .vunnix.toml (404)', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 100]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/100/repository/files/.vunnix.toml*' => Http::response(['message' => '404 File Not Found'], 404),
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

    expect($task->status)->toBe(TaskStatus::Running)
        ->and($task->result)->not->toHaveKey('file_config');
});

// ─── Pipeline ref resolution ────────────────────────────────────

it('triggers pipeline with MR source branch name, not commit SHA', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 100]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/100/merge_requests/1/changes' => Http::response([
            'changes' => [['new_path' => 'app/Models/User.php']],
        ]),
        '*/api/v4/projects/100/merge_requests/1' => Http::response([
            'iid' => 1,
            'source_branch' => 'feat/my-feature',
            'target_branch' => 'main',
        ]),
        '*/api/v4/projects/100/trigger/pipeline' => Http::response([
            'id' => 1000,
            'status' => 'pending',
        ]),
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response(['id' => 1], 201),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::CodeReview,
        'project_id' => $project->id,
        'mr_iid' => 1,
        'commit_sha' => 'abc123def456',
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    // Verify pipeline was triggered with branch name, not commit SHA
    Http::assertSent(fn ($req): bool => str_contains($req->url(), '/trigger/pipeline') &&
        ($req->data()['ref'] ?? null) === 'feat/my-feature'
    );
});

it('triggers pipeline with main when task has no MR', function (): void {
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
        'commit_sha' => 'abc123def456',
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    // Verify pipeline was triggered with 'main', not commit SHA
    Http::assertSent(fn ($req): bool => str_contains($req->url(), '/trigger/pipeline') &&
        ($req->data()['ref'] ?? null) === 'main'
    );
});

it('falls back to main when MR source branch lookup fails', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 100]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/100/merge_requests/1/changes' => Http::response([
            'changes' => [['new_path' => 'app/Models/User.php']],
        ]),
        '*/api/v4/projects/100/merge_requests/1' => Http::response([], 500),
        '*/api/v4/projects/100/trigger/pipeline' => Http::response([
            'id' => 1000,
            'status' => 'pending',
        ]),
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response(['id' => 1], 201),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::CodeReview,
        'project_id' => $project->id,
        'mr_iid' => 1,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    // Should fall back to 'main' and still succeed
    Http::assertSent(fn ($req): bool => str_contains($req->url(), '/trigger/pipeline') &&
        ($req->data()['ref'] ?? null) === 'main'
    );

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Running);
});

it('does not read .vunnix.toml for server-side tasks', function (): void {
    Queue::fake([\App\Jobs\ProcessTaskResult::class]);
    Http::fake();

    $task = Task::factory()->queued()->create([
        'type' => TaskType::PrdCreation,
        'mr_iid' => null,
        'issue_iid' => 10,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    // Server-side tasks don't trigger GitLab file reads
    Http::assertNotSent(function ($request): bool {
        return str_contains($request->url(), '.vunnix.toml');
    });
});

it('passes VUNNIX_MEMORY_CONTEXT when review guidance exists', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 100]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token',
    ]);

    $memoryInjection = \Mockery::mock(MemoryInjectionService::class);
    $memoryInjection->shouldReceive('buildReviewGuidance')
        ->once()
        ->andReturn('Focus on logic defects over style.');
    app()->instance(MemoryInjectionService::class, $memoryInjection);

    Http::fake([
        '*/api/v4/projects/100/merge_requests/1/changes' => Http::response([
            'changes' => [['new_path' => 'app/Services/TaskDispatcher.php']],
        ]),
        '*/api/v4/projects/100/merge_requests/1' => Http::response([
            'source_branch' => 'feat/memory',
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

    app(TaskDispatcher::class)->dispatch($task);

    Http::assertSent(
        fn ($request): bool => str_contains($request->url(), '/trigger/pipeline')
            && ($request->data()['variables[VUNNIX_MEMORY_CONTEXT]'] ?? null) === 'Focus on logic defects over style.',
    );
});

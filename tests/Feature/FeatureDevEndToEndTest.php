<?php

/**
 * T44: End-to-end integration test for feature development (ai::develop label).
 *
 * Flow:
 *   Webhook (Issue Hook, ai::develop label added) → EventRouter (feature_dev, low priority)
 *   → Permission check (review.trigger) → TaskDispatchService (FeatureDev)
 *   → ProcessTask → TaskDispatcher (no placeholder, pipeline trigger with VUNNIX_ISSUE_IID)
 *   → [simulated runner result via API]
 *   → ProcessTaskResult → ResultProcessor (FeatureDevSchema) → PostFeatureDevResult
 *   → Creates MR via GitLab API → Posts summary on Issue
 */

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Helper: grant review.trigger permission to a user on a project.
 */
function grantReviewTriggerT44(User $user, Project $project): void
{
    $permission = Permission::firstOrCreate(
        ['name' => 'review.trigger'],
        ['description' => 'Can trigger on-demand review', 'group' => 'review'],
    );
    $role = Role::firstOrCreate(
        ['project_id' => $project->id, 'name' => 'developer'],
        ['description' => 'Developer role', 'is_default' => true],
    );
    $role->permissions()->syncWithoutDetaching([$permission->id]);
    $user->assignRole($role, $project);
}

// ──────────────────────────────────────────────────────────────
//  ai::develop label — full feature dev flow
// ──────────────────────────────────────────────────────────────

it('completes full feature dev flow: label → task → MR created → issue summary', function () {
    // ── 1. Set up project, config, and user with permission ──────

    $project = Project::factory()->enabled()->create([
        'gitlab_project_id' => 44001,
    ]);

    $webhookSecret = 'feature-dev-e2e-secret';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $webhookSecret,
        'ci_trigger_token' => 'ci-trigger-feature-dev',
    ]);

    $user = User::factory()->create(['gitlab_id' => 70]);
    grantReviewTriggerT44($user, $project);

    // ── 2. Http::fake() all GitLab API endpoints ─────────────────

    $pipelineId = 44101;
    $mrIid = 44102;
    $issueNoteId = 44103;

    Http::fake([
        // Trigger pipeline
        '*/api/v4/projects/44001/trigger/pipeline' => Http::response([
            'id' => $pipelineId,
            'status' => 'created',
        ], 201),

        // Create merge request
        '*/api/v4/projects/44001/merge_requests' => Http::response([
            'iid' => $mrIid,
            'title' => 'Add payment processing module',
            'web_url' => 'https://gitlab.example.com/project/merge_requests/44102',
        ], 201),

        // Create issue comment (summary posted to Issue)
        '*/api/v4/projects/44001/issues/8/notes' => Http::response([
            'id' => $issueNoteId,
            'body' => '### 🤖 AI Feature Development Complete...',
        ], 201),
    ]);

    // ── 3. POST webhook with ai::develop label on Issue ──────────

    $webhookResponse = $this->postJson('/webhook', [
        'object_kind' => 'issue',
        'object_attributes' => [
            'iid' => 8,
            'action' => 'update',
            'author_id' => 70,
        ],
        'labels' => [
            ['title' => 'ai::develop'],
            ['title' => 'feature'],
        ],
    ], [
        'X-Gitlab-Token' => $webhookSecret,
        'X-Gitlab-Event' => 'Issue Hook',
        'X-Gitlab-Event-UUID' => 'feature-dev-uuid-001',
    ]);

    // ── 4. Assert webhook accepted + task created ────────────────

    $webhookResponse->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'event_type' => 'issue',
            'intent' => 'feature_dev',
        ])
        ->assertJsonMissing(['permission_denied' => true])
        ->assertJsonStructure(['task_id']);

    $taskId = $webhookResponse->json('task_id');
    expect($taskId)->not->toBeNull();

    // Task was created with correct fields — FeatureDev, Low priority
    $task = Task::find($taskId);
    expect($task)->not->toBeNull();
    expect($task->type)->toBe(TaskType::FeatureDev);
    expect($task->priority)->toBe(TaskPriority::Low);
    expect($task->project_id)->toBe($project->id);
    expect($task->issue_iid)->toBe(8);
    expect($task->mr_iid)->toBeNull(); // MR not created yet
    expect($task->user_id)->toBe($user->id);
    expect($task->result['intent'])->toBe('feature_dev');

    // ── 5. Assert: NO placeholder comment (FeatureDev) ───────────

    $task->refresh();
    expect($task->comment_id)->toBeNull();

    // ── 6. Assert: pipeline was triggered with VUNNIX_ISSUE_IID ──

    expect($task->pipeline_id)->toBe($pipelineId);
    expect($task->status)->toBe(TaskStatus::Running);

    Http::assertSent(function ($request) use ($taskId) {
        if (! str_contains($request->url(), 'trigger/pipeline')) {
            return false;
        }

        $body = $request->data();

        return $body['variables[VUNNIX_TASK_ID]'] === (string) $taskId
            && $body['variables[VUNNIX_TASK_TYPE]'] === 'feature_dev'
            && $body['variables[VUNNIX_INTENT]'] === 'feature_dev'
            && $body['variables[VUNNIX_ISSUE_IID]'] === '8'
            && ! empty($body['variables[VUNNIX_TOKEN]'])
            && ! empty($body['variables[VUNNIX_STRATEGY]']);
    });

    // ── 7. Simulate runner posting feature dev result ─────────────

    $tokenService = app(TaskTokenService::class);
    $taskToken = $tokenService->generate($taskId);

    $resultResponse = $this->postJson(
        "/api/v1/tasks/{$taskId}/result",
        [
            'status' => 'completed',
            'result' => [
                'version' => '1.0',
                'branch' => 'ai/payment-processing',
                'mr_title' => 'Add payment processing module',
                'mr_description' => "## Summary\n\nImplements payment processing with Stripe integration.\n\n## Changes\n- Added PaymentService class\n- Added payment routes\n- Added unit tests",
                'files_changed' => [
                    [
                        'path' => 'app/Services/PaymentService.php',
                        'action' => 'created',
                        'summary' => 'Core payment processing logic with Stripe integration',
                    ],
                    [
                        'path' => 'routes/api.php',
                        'action' => 'modified',
                        'summary' => 'Added /api/v1/payments endpoints',
                    ],
                    [
                        'path' => 'tests/Feature/PaymentServiceTest.php',
                        'action' => 'created',
                        'summary' => 'Unit tests for payment processing',
                    ],
                ],
                'tests_added' => true,
                'notes' => 'Used Stripe PHP SDK v13. All tests pass locally.',
            ],
            'tokens' => [
                'input' => 120000,
                'output' => 25000,
                'thinking' => 8000,
            ],
            'duration_seconds' => 180,
            'prompt_version' => [
                'skill' => 'feature-dev-1.0',
                'claude_md' => 'executor-1.0',
                'schema' => 'feature-dev-1.0',
            ],
        ],
        ['Authorization' => "Bearer {$taskToken}"],
    );

    $resultResponse->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'task_id' => $taskId,
            'task_status' => 'processing',
        ]);

    // ── 8. Assert: MR created via GitLab API ─────────────────────

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/merge_requests') || $request->method() !== 'POST') {
            return false;
        }

        // Exclude discussion thread requests (also contain /merge_requests/ in URL)
        if (str_contains($request->url(), '/discussions')) {
            return false;
        }

        $body = $request->data();

        return ($body['source_branch'] ?? '') === 'ai/payment-processing'
            && ($body['target_branch'] ?? '') === 'main'
            && str_contains($body['title'] ?? '', 'payment processing');
    });

    // ── 9. Assert: summary posted as Issue comment ───────────────

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'issues/8/notes') || $request->method() !== 'POST') {
            return false;
        }

        $body = $request->data()['body'] ?? '';

        return str_contains($body, '🤖 AI Feature Development Complete')
            && str_contains($body, '!44102') // MR reference
            && str_contains($body, 'ai/payment-processing') // Branch name
            && str_contains($body, 'PaymentService'); // File reference
    });

    // ── 10. Assert: NO 3-layer output (no inline threads, no labels) ──

    // No discussion thread creation
    $discussionRequests = collect(Http::recorded())
        ->filter(function ($pair) {
            [$request] = $pair;

            return str_contains($request->url(), '/discussions')
                && $request->method() === 'POST';
        });

    expect($discussionRequests)->toHaveCount(0);

    // ── 11. Assert: task completed with MR IID set ───────────────

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Completed);
    expect($task->completed_at)->not->toBeNull();
    expect($task->mr_iid)->toBe($mrIid); // MR IID stored on task
    expect($task->comment_id)->toBe($issueNoteId); // Issue comment ID stored
    expect($task->tokens_used)->toBe(120000 + 25000 + 8000);
});

// ──────────────────────────────────────────────────────────────
//  Permission denied — user without review.trigger
// ──────────────────────────────────────────────────────────────

it('rejects ai::develop label from user without review.trigger permission', function () {
    $project = Project::factory()->enabled()->create([
        'gitlab_project_id' => 44002,
    ]);

    $webhookSecret = 'feature-dev-perm-denied';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $webhookSecret,
    ]);

    // User exists but has NO review.trigger permission
    User::factory()->create(['gitlab_id' => 71]);

    $response = $this->postJson('/webhook', [
        'object_kind' => 'issue',
        'object_attributes' => [
            'iid' => 12,
            'action' => 'update',
            'author_id' => 71,
        ],
        'labels' => [
            ['title' => 'ai::develop'],
        ],
    ], [
        'X-Gitlab-Token' => $webhookSecret,
        'X-Gitlab-Event' => 'Issue Hook',
        'X-Gitlab-Event-UUID' => 'feature-dev-perm-denied-001',
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'intent' => 'feature_dev',
            'permission_denied' => true,
        ]);

    expect(Task::count())->toBe(0);
});

// ──────────────────────────────────────────────────────────────
//  Issue without ai::develop label — ignored
// ──────────────────────────────────────────────────────────────

it('ignores issue label changes without ai::develop label', function () {
    $project = Project::factory()->enabled()->create([
        'gitlab_project_id' => 44003,
    ]);

    $webhookSecret = 'feature-dev-no-label';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $webhookSecret,
    ]);

    $response = $this->postJson('/webhook', [
        'object_kind' => 'issue',
        'object_attributes' => [
            'iid' => 15,
            'action' => 'update',
            'author_id' => 70,
        ],
        'labels' => [
            ['title' => 'bug'],
            ['title' => 'priority::high'],
        ],
    ], [
        'X-Gitlab-Token' => $webhookSecret,
        'X-Gitlab-Event' => 'Issue Hook',
        'X-Gitlab-Event-UUID' => 'feature-dev-no-label-001',
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'intent' => null,
        ]);

    expect(Task::count())->toBe(0);
});

// ──────────────────────────────────────────────────────────────
//  Low priority — feature dev tasks get low priority
// ──────────────────────────────────────────────────────────────

it('dispatches feature dev tasks with low priority', function () {
    $project = Project::factory()->enabled()->create([
        'gitlab_project_id' => 44004,
    ]);

    $webhookSecret = 'feature-dev-low-priority';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $webhookSecret,
        'ci_trigger_token' => 'ci-trigger-low-prio',
    ]);

    $user = User::factory()->create(['gitlab_id' => 72]);
    grantReviewTriggerT44($user, $project);

    Http::fake([
        '*/api/v4/projects/44004/trigger/pipeline' => Http::response([
            'id' => 44201,
            'status' => 'created',
        ], 201),
    ]);

    $response = $this->postJson('/webhook', [
        'object_kind' => 'issue',
        'object_attributes' => [
            'iid' => 20,
            'action' => 'update',
            'author_id' => 72,
        ],
        'labels' => [
            ['title' => 'ai::develop'],
        ],
    ], [
        'X-Gitlab-Token' => $webhookSecret,
        'X-Gitlab-Event' => 'Issue Hook',
        'X-Gitlab-Event-UUID' => 'feature-dev-low-prio-001',
    ]);

    $taskId = $response->json('task_id');
    $task = Task::find($taskId);

    expect($task->priority)->toBe(TaskPriority::Low);
    expect($task->type)->toBe(TaskType::FeatureDev);
});

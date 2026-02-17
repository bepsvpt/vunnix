<?php

/**
 * T41: End-to-end integration test for the on-demand review flow (@ai review).
 *
 * Verifies the complete chain triggered by a Note Hook containing @ai review:
 *   Webhook (Note Hook) → EventRouter (on_demand_review, high priority)
 *   → Permission check (review.trigger) → EventDeduplicator → TaskDispatchService
 *   → ProcessTask → TaskDispatcher (strategy + placeholder + pipeline trigger)
 *   → [simulated runner result via API]
 *   → ProcessTaskResult → ResultProcessor → PostSummaryComment (update-in-place)
 *   + PostInlineThreads + PostLabelsAndStatus
 *
 * This is the same 3-layer review output as T39 (auto_review), but triggered
 * by an @ai review comment with high priority and permission gating.
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
function grantReviewTrigger(User $user, Project $project): void
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

/**
 * Build a code review result for the on-demand review test.
 */
function onDemandReviewResult(): array
{
    return [
        'version' => '1.0',
        'summary' => [
            'risk_level' => 'medium',
            'total_findings' => 2,
            'findings_by_severity' => [
                'critical' => 0,
                'major' => 1,
                'minor' => 1,
            ],
            'walkthrough' => [
                [
                    'file' => 'app/Services/OrderService.php',
                    'change_summary' => 'Added order processing with inventory check',
                ],
            ],
        ],
        'findings' => [
            [
                'id' => 1,
                'severity' => 'major',
                'category' => 'bug',
                'file' => 'app/Services/OrderService.php',
                'line' => 55,
                'end_line' => 60,
                'title' => 'Race condition in inventory check',
                'description' => 'The inventory check and decrement are not atomic, allowing overselling under concurrent requests.',
                'suggestion' => 'Use a database transaction with SELECT ... FOR UPDATE to lock the inventory row.',
                'labels' => ['bug', 'concurrency'],
            ],
            [
                'id' => 2,
                'severity' => 'minor',
                'category' => 'style',
                'file' => 'app/Services/OrderService.php',
                'line' => 12,
                'end_line' => 12,
                'title' => 'Unused import',
                'description' => 'The Cache import is not used in this file.',
                'suggestion' => 'Remove the unused import.',
                'labels' => ['style'],
            ],
        ],
        'labels' => ['ai::reviewed', 'ai::risk-medium'],
        'commit_status' => 'success',
    ];
}

function onDemandRunnerPayload(array $result): array
{
    return [
        'status' => 'completed',
        'result' => $result,
        'tokens' => [
            'input' => 18000,
            'output' => 3500,
            'thinking' => 700,
        ],
        'duration_seconds' => 52,
        'prompt_version' => [
            'skill' => 'backend-review-1.0',
            'claude_md' => 'executor-1.0',
            'schema' => 'code-review-1.0',
        ],
    ];
}

// ──────────────────────────────────────────────────────────────
//  Main E2E test — happy path
// ──────────────────────────────────────────────────────────────

it('completes full on-demand review flow from @ai review comment to 3-layer GitLab comments', function (): void {
    // ── 1. Set up project, config, and user with permission ──────

    $project = Project::factory()->enabled()->create([
        'gitlab_project_id' => 77777,
    ]);

    $webhookSecret = 'on-demand-e2e-secret';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $webhookSecret,
        'ci_trigger_token' => 'ci-trigger-on-demand',
    ]);

    $user = User::factory()->create(['gitlab_id' => 15]);
    grantReviewTrigger($user, $project);

    // ── 2. Http::fake() all GitLab API endpoints ─────────────────

    $placeholderNoteId = 41001;
    $pipelineId = 41002;

    Http::fake([
        // MR changes → PHP files → backend-review strategy
        '*/api/v4/projects/77777/merge_requests/30/changes' => Http::response([
            'changes' => [
                ['new_path' => 'app/Services/OrderService.php', 'old_path' => 'app/Services/OrderService.php'],
            ],
        ], 200),

        // Create placeholder note
        '*/api/v4/projects/77777/merge_requests/30/notes' => Http::response([
            'id' => $placeholderNoteId,
            'body' => '🤖 AI Review in progress…',
        ], 201),

        // Trigger pipeline
        '*/api/v4/projects/77777/trigger/pipeline' => Http::response([
            'id' => $pipelineId,
            'status' => 'created',
        ], 201),

        // Update note in-place
        '*/api/v4/projects/77777/merge_requests/30/notes/'.$placeholderNoteId => Http::response([
            'id' => $placeholderNoteId,
            'body' => '(updated summary)',
        ], 200),

        // MR details
        '*/api/v4/projects/77777/merge_requests/30' => Http::response([
            'iid' => 30,
            'sha' => 'ondemand-sha-123',
            'diff_refs' => [
                'base_sha' => 'base-od',
                'start_sha' => 'start-od',
                'head_sha' => 'head-od',
            ],
        ], 200),

        // Discussion threads
        '*/api/v4/projects/77777/merge_requests/30/discussions*' => Http::sequence()
            ->push([], 200)                              // GET existing (empty)
            ->push(['id' => 'disc-od-1'], 201),          // POST thread for major finding

        // Commit status
        '*/api/v4/projects/77777/statuses/ondemand-sha-123' => Http::response([
            'id' => 1,
            'status' => 'success',
        ], 201),
    ]);

    // ── 3. POST webhook with @ai review note ─────────────────────

    $webhookResponse = $this->postJson('/webhook', [
        'object_kind' => 'note',
        'object_attributes' => [
            'note' => '@ai review',
            'noteable_type' => 'MergeRequest',
            'author_id' => 15,
        ],
        'merge_request' => [
            'iid' => 30,
        ],
    ], [
        'X-Gitlab-Token' => $webhookSecret,
        'X-Gitlab-Event' => 'Note Hook',
        'X-Gitlab-Event-UUID' => '00000000-0000-0000-0000-e00000000001',
    ]);

    // ── 4. Assert webhook accepted + task created ────────────────

    $webhookResponse->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'event_type' => 'note',
            'intent' => 'on_demand_review',
        ])
        ->assertJsonMissing(['permission_denied' => true])
        ->assertJsonStructure(['task_id']);

    $taskId = $webhookResponse->json('task_id');
    expect($taskId)->not->toBeNull();

    // Task was created with correct fields
    $task = Task::find($taskId);
    expect($task)->not->toBeNull();
    expect($task->type)->toBe(TaskType::CodeReview);
    expect($task->priority)->toBe(TaskPriority::High);
    expect($task->project_id)->toBe($project->id);
    expect($task->mr_iid)->toBe(30);
    expect($task->user_id)->toBe($user->id);

    // ── 5. Assert: placeholder comment was posted ────────────────

    $task->refresh();
    expect($task->comment_id)->toBe($placeholderNoteId);

    // ── 6. Assert: pipeline was triggered ────────────────────────

    expect($task->pipeline_id)->toBe($pipelineId);
    expect($task->status)->toBe(TaskStatus::Running);

    // Verify pipeline trigger included VUNNIX_* variables
    Http::assertSent(function ($request) use ($taskId) {
        if (! str_contains($request->url(), 'trigger/pipeline')) {
            return false;
        }

        $body = $request->data();

        return $body['variables[VUNNIX_TASK_ID]'] === (string) $taskId
            && $body['variables[VUNNIX_TASK_TYPE]'] === 'code_review'
            && ! empty($body['variables[VUNNIX_TOKEN]'])
            && ! empty($body['variables[VUNNIX_STRATEGY]']);
    });

    // ── 7. Simulate runner posting result via API ────────────────

    $tokenService = app(TaskTokenService::class);
    $taskToken = $tokenService->generate($taskId);

    $resultResponse = $this->postJson(
        "/api/v1/tasks/{$taskId}/result",
        onDemandRunnerPayload(onDemandReviewResult()),
        ['Authorization' => "Bearer {$taskToken}"],
    );

    $resultResponse->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'task_id' => $taskId,
            'task_status' => 'processing',
        ]);

    // ── 8. Assert: summary comment updated in-place (Layer 1) ────

    Http::assertSent(function ($request) use ($placeholderNoteId) {
        return str_contains($request->url(), "notes/{$placeholderNoteId}")
            && $request->method() === 'PUT'
            && ! empty($request->data()['body']);
    });

    // ── 9. Assert: inline threads posted for major finding (Layer 2) ──

    $discussionRequests = collect(Http::recorded())
        ->filter(function ($pair) {
            [$request] = $pair;

            return str_contains($request->url(), '/discussions')
                && $request->method() === 'POST';
        });

    // 1 major finding → 1 discussion thread (minor excluded)
    expect($discussionRequests)->toHaveCount(1);

    // ── 10. Assert: labels applied (Layer 3) ─────────────────────

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'merge_requests/30') || $request->method() !== 'PUT') {
            return false;
        }

        $addLabels = $request->data()['add_labels'] ?? '';

        return str_contains($addLabels, 'ai::reviewed')
            && str_contains($addLabels, 'ai::risk-medium');
    });

    // ── 11. Assert: commit status set to success (Layer 3) ───────

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'statuses/ondemand-sha-123')
            && $request->method() === 'POST'
            && ($request->data()['state'] ?? '') === 'success';
    });

    // ── 12. Assert: task completed ───────────────────────────────

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Completed);
    expect($task->completed_at)->not->toBeNull();
    expect($task->tokens_used)->toBe(18000 + 3500 + 700);
});

// ──────────────────────────────────────────────────────────────
//  Permission denied — no task created
// ──────────────────────────────────────────────────────────────

it('rejects @ai review from user without review.trigger permission', function (): void {
    $project = Project::factory()->enabled()->create([
        'gitlab_project_id' => 88888,
    ]);

    $webhookSecret = 'perm-denied-e2e-secret';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $webhookSecret,
    ]);

    // User exists but has NO review.trigger permission
    User::factory()->create(['gitlab_id' => 20]);

    $response = $this->postJson('/webhook', [
        'object_kind' => 'note',
        'object_attributes' => [
            'note' => '@ai review',
            'noteable_type' => 'MergeRequest',
            'author_id' => 20,
        ],
        'merge_request' => [
            'iid' => 10,
        ],
    ], [
        'X-Gitlab-Token' => $webhookSecret,
        'X-Gitlab-Event' => 'Note Hook',
        'X-Gitlab-Event-UUID' => '00000000-0000-0000-0000-e00000000002',
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'intent' => 'on_demand_review',
            'permission_denied' => true,
        ]);

    // No task should be created
    expect(Task::count())->toBe(0);
});

// ──────────────────────────────────────────────────────────────
//  @ai review with surrounding text
// ──────────────────────────────────────────────────────────────

it('recognizes @ai review embedded in a longer comment', function (): void {
    $project = Project::factory()->enabled()->create([
        'gitlab_project_id' => 99999,
    ]);

    $webhookSecret = 'inline-text-secret';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $webhookSecret,
        'ci_trigger_token' => 'ci-trigger-inline',
    ]);

    $user = User::factory()->create(['gitlab_id' => 25]);
    grantReviewTrigger($user, $project);

    Http::fake([
        '*/api/v4/projects/99999/merge_requests/50/changes' => Http::response([
            'changes' => [
                ['new_path' => 'src/index.ts', 'old_path' => 'src/index.ts'],
            ],
        ], 200),
        '*/api/v4/projects/99999/merge_requests/50/notes' => Http::response([
            'id' => 42001, 'body' => 'placeholder',
        ], 201),
        '*/api/v4/projects/99999/trigger/pipeline' => Http::response([
            'id' => 42002, 'status' => 'created',
        ], 201),
    ]);

    $response = $this->postJson('/webhook', [
        'object_kind' => 'note',
        'object_attributes' => [
            'note' => 'Hey team, can someone @ai review this before we merge? Thanks!',
            'noteable_type' => 'MergeRequest',
            'author_id' => 25,
        ],
        'merge_request' => [
            'iid' => 50,
        ],
    ], [
        'X-Gitlab-Token' => $webhookSecret,
        'X-Gitlab-Event' => 'Note Hook',
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'intent' => 'on_demand_review',
        ])
        ->assertJsonStructure(['task_id']);

    $taskId = $response->json('task_id');
    $task = Task::find($taskId);
    expect($task)->not->toBeNull();
    expect($task->priority)->toBe(TaskPriority::High);
    expect($task->type)->toBe(TaskType::CodeReview);
});

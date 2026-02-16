<?php

/**
 * T42: End-to-end integration tests for @ai improve and @ai ask commands.
 *
 * @ai improve:
 *   Webhook (Note Hook) → EventRouter (improve, normal priority)
 *   → Permission check (review.trigger) → TaskDispatchService (CodeReview)
 *   → ProcessTask → TaskDispatcher (strategy + placeholder + pipeline trigger)
 *   → [simulated runner result via API]
 *   → ProcessTaskResult → ResultProcessor → 3-layer output (same as code review)
 *
 * @ai ask "...":
 *   Webhook (Note Hook) → EventRouter (ask_command, normal priority)
 *   → Permission check (review.trigger) → TaskDispatchService (IssueDiscussion)
 *   → ProcessTask → TaskDispatcher (no placeholder, pipeline trigger)
 *   → [simulated runner result via API]
 *   → ProcessTaskResult → ResultProcessor (no schema) → PostAnswerComment
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
function grantReviewTriggerT42(User $user, Project $project): void
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
//  @ai improve — full code review flow with normal priority
// ──────────────────────────────────────────────────────────────

it('completes full @ai improve flow with 3-layer code review output', function () {
    // ── 1. Set up project, config, and user with permission ──────

    $project = Project::factory()->enabled()->create([
        'gitlab_project_id' => 42001,
    ]);

    $webhookSecret = 'improve-e2e-secret';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $webhookSecret,
        'ci_trigger_token' => 'ci-trigger-improve',
    ]);

    $user = User::factory()->create(['gitlab_id' => 50]);
    grantReviewTriggerT42($user, $project);

    // ── 2. Http::fake() all GitLab API endpoints ─────────────────

    $placeholderNoteId = 42101;
    $pipelineId = 42102;

    Http::fake([
        // MR changes → mixed files → mixed-review strategy
        '*/api/v4/projects/42001/merge_requests/10/changes' => Http::response([
            'changes' => [
                ['new_path' => 'app/Services/PaymentService.php', 'old_path' => 'app/Services/PaymentService.php'],
                ['new_path' => 'resources/js/components/Checkout.vue', 'old_path' => 'resources/js/components/Checkout.vue'],
            ],
        ], 200),

        // Create placeholder note
        '*/api/v4/projects/42001/merge_requests/10/notes' => Http::response([
            'id' => $placeholderNoteId,
            'body' => '🤖 AI Review in progress…',
        ], 201),

        // Trigger pipeline
        '*/api/v4/projects/42001/trigger/pipeline' => Http::response([
            'id' => $pipelineId,
            'status' => 'created',
        ], 201),

        // Update note in-place (summary comment)
        '*/api/v4/projects/42001/merge_requests/10/notes/' . $placeholderNoteId => Http::response([
            'id' => $placeholderNoteId,
            'body' => '(updated summary)',
        ], 200),

        // MR details (for inline threads)
        '*/api/v4/projects/42001/merge_requests/10' => Http::response([
            'iid' => 10,
            'sha' => 'improve-sha-abc',
            'diff_refs' => [
                'base_sha' => 'base-imp',
                'start_sha' => 'start-imp',
                'head_sha' => 'head-imp',
            ],
        ], 200),

        // Discussion threads
        '*/api/v4/projects/42001/merge_requests/10/discussions*' => Http::sequence()
            ->push([], 200)                                // GET existing (empty)
            ->push(['id' => 'disc-imp-1'], 201),           // POST thread for finding

        // Commit status
        '*/api/v4/projects/42001/statuses/improve-sha-abc' => Http::response([
            'id' => 1,
            'status' => 'success',
        ], 201),
    ]);

    // ── 3. POST webhook with @ai improve note ─────────────────────

    $webhookResponse = $this->postJson('/webhook', [
        'object_kind' => 'note',
        'object_attributes' => [
            'note' => '@ai improve',
            'noteable_type' => 'MergeRequest',
            'author_id' => 50,
        ],
        'merge_request' => [
            'iid' => 10,
        ],
    ], [
        'X-Gitlab-Token' => $webhookSecret,
        'X-Gitlab-Event' => 'Note Hook',
        'X-Gitlab-Event-UUID' => '00000000-0000-0000-0000-a00000000001',
    ]);

    // ── 4. Assert webhook accepted + task created ────────────────

    $webhookResponse->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'event_type' => 'note',
            'intent' => 'improve',
        ])
        ->assertJsonMissing(['permission_denied' => true])
        ->assertJsonStructure(['task_id']);

    $taskId = $webhookResponse->json('task_id');
    expect($taskId)->not->toBeNull();

    // Task was created with correct fields — CodeReview, Normal priority
    $task = Task::find($taskId);
    expect($task)->not->toBeNull();
    expect($task->type)->toBe(TaskType::CodeReview);
    expect($task->priority)->toBe(TaskPriority::Normal);
    expect($task->project_id)->toBe($project->id);
    expect($task->mr_iid)->toBe(10);
    expect($task->user_id)->toBe($user->id);
    expect($task->result['intent'])->toBe('improve');

    // ── 5. Assert: placeholder comment was posted ────────────────

    $task->refresh();
    expect($task->comment_id)->toBe($placeholderNoteId);

    // ── 6. Assert: pipeline was triggered with VUNNIX_INTENT ─────

    expect($task->pipeline_id)->toBe($pipelineId);
    expect($task->status)->toBe(TaskStatus::Running);

    Http::assertSent(function ($request) use ($taskId) {
        if (! str_contains($request->url(), 'trigger/pipeline')) {
            return false;
        }

        $body = $request->data();

        return $body['variables[VUNNIX_TASK_ID]'] === (string) $taskId
            && $body['variables[VUNNIX_TASK_TYPE]'] === 'code_review'
            && $body['variables[VUNNIX_INTENT]'] === 'improve'
            && ! empty($body['variables[VUNNIX_TOKEN]'])
            && ! empty($body['variables[VUNNIX_STRATEGY]']);
    });

    // ── 7. Simulate runner posting result via API ────────────────

    $tokenService = app(TaskTokenService::class);
    $taskToken = $tokenService->generate($taskId);

    $resultResponse = $this->postJson(
        "/api/v1/tasks/{$taskId}/result",
        [
            'status' => 'completed',
            'result' => [
                'version' => '1.0',
                'summary' => [
                    'risk_level' => 'low',
                    'total_findings' => 1,
                    'findings_by_severity' => [
                        'critical' => 0,
                        'major' => 1,
                        'minor' => 0,
                    ],
                    'walkthrough' => [
                        [
                            'file' => 'app/Services/PaymentService.php',
                            'change_summary' => 'Add retry logic to payment processing',
                        ],
                    ],
                ],
                'findings' => [
                    [
                        'id' => 1,
                        'severity' => 'major',
                        'category' => 'performance',
                        'file' => 'app/Services/PaymentService.php',
                        'line' => 30,
                        'end_line' => 45,
                        'title' => 'Consider using exponential backoff for retries',
                        'description' => 'The current linear retry delay could overwhelm the payment provider during outages.',
                        'suggestion' => 'Replace fixed delay with exponential backoff: delay = baseDelay * 2^attempt.',
                        'labels' => ['improvement'],
                    ],
                ],
                'labels' => ['ai::reviewed', 'ai::risk-low'],
                'commit_status' => 'success',
            ],
            'tokens' => [
                'input' => 15000,
                'output' => 2800,
                'thinking' => 500,
            ],
            'duration_seconds' => 38,
            'prompt_version' => [
                'skill' => 'mixed-review-1.0',
                'claude_md' => 'executor-1.0',
                'schema' => 'code-review-1.0',
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

    expect($discussionRequests)->toHaveCount(1);

    // ── 10. Assert: labels applied (Layer 3) ─────────────────────

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'merge_requests/10') || $request->method() !== 'PUT') {
            return false;
        }

        $addLabels = $request->data()['add_labels'] ?? '';

        return str_contains($addLabels, 'ai::reviewed')
            && str_contains($addLabels, 'ai::risk-low');
    });

    // ── 11. Assert: task completed ───────────────────────────────

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Completed);
    expect($task->completed_at)->not->toBeNull();
    expect($task->tokens_used)->toBe(15000 + 2800 + 500);
});

it('rejects @ai improve from user without review.trigger permission', function () {
    $project = Project::factory()->enabled()->create([
        'gitlab_project_id' => 42002,
    ]);

    $webhookSecret = 'improve-perm-denied';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $webhookSecret,
    ]);

    // User exists but has NO review.trigger permission
    User::factory()->create(['gitlab_id' => 51]);

    $response = $this->postJson('/webhook', [
        'object_kind' => 'note',
        'object_attributes' => [
            'note' => '@ai improve',
            'noteable_type' => 'MergeRequest',
            'author_id' => 51,
        ],
        'merge_request' => [
            'iid' => 10,
        ],
    ], [
        'X-Gitlab-Token' => $webhookSecret,
        'X-Gitlab-Event' => 'Note Hook',
        'X-Gitlab-Event-UUID' => '00000000-0000-0000-0000-a00000000002',
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'intent' => 'improve',
            'permission_denied' => true,
        ]);

    expect(Task::count())->toBe(0);
});

// ──────────────────────────────────────────────────────────────
//  @ai ask — answer posted as MR comment
// ──────────────────────────────────────────────────────────────

it('completes full @ai ask flow with answer posted as MR comment', function () {
    // ── 1. Set up project, config, and user with permission ──────

    $project = Project::factory()->enabled()->create([
        'gitlab_project_id' => 42003,
    ]);

    $webhookSecret = 'ask-e2e-secret';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $webhookSecret,
        'ci_trigger_token' => 'ci-trigger-ask',
    ]);

    $user = User::factory()->create(['gitlab_id' => 55]);
    grantReviewTriggerT42($user, $project);

    // ── 2. Http::fake() all GitLab API endpoints ─────────────────

    $pipelineId = 42201;
    $answerNoteId = 42202;

    Http::fake([
        // Trigger pipeline
        '*/api/v4/projects/42003/trigger/pipeline' => Http::response([
            'id' => $pipelineId,
            'status' => 'created',
        ], 201),

        // Create answer comment (not placeholder — ask_command has no placeholder)
        '*/api/v4/projects/42003/merge_requests/20/notes' => Http::response([
            'id' => $answerNoteId,
            'body' => '### 🤖 Answer...',
        ], 201),
    ]);

    // ── 3. POST webhook with @ai ask "..." note ──────────────────

    $webhookResponse = $this->postJson('/webhook', [
        'object_kind' => 'note',
        'object_attributes' => [
            'note' => '@ai ask "why is this function here?"',
            'noteable_type' => 'MergeRequest',
            'author_id' => 55,
        ],
        'merge_request' => [
            'iid' => 20,
        ],
    ], [
        'X-Gitlab-Token' => $webhookSecret,
        'X-Gitlab-Event' => 'Note Hook',
        'X-Gitlab-Event-UUID' => '00000000-0000-0000-0000-a00000000003',
    ]);

    // ── 4. Assert webhook accepted + task created ────────────────

    $webhookResponse->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'event_type' => 'note',
            'intent' => 'ask_command',
        ])
        ->assertJsonMissing(['permission_denied' => true])
        ->assertJsonStructure(['task_id']);

    $taskId = $webhookResponse->json('task_id');
    expect($taskId)->not->toBeNull();

    // Task created with IssueDiscussion type, Normal priority, question stored
    $task = Task::find($taskId);
    expect($task)->not->toBeNull();
    expect($task->type)->toBe(TaskType::IssueDiscussion);
    expect($task->priority)->toBe(TaskPriority::Normal);
    expect($task->project_id)->toBe($project->id);
    expect($task->mr_iid)->toBe(20);
    expect($task->user_id)->toBe($user->id);
    expect($task->result['intent'])->toBe('ask_command');
    expect($task->result['question'])->toBe('why is this function here?');

    // ── 5. Assert: NO placeholder comment (IssueDiscussion) ──────

    $task->refresh();
    expect($task->comment_id)->toBeNull();

    // ── 6. Assert: pipeline was triggered with question ──────────

    expect($task->pipeline_id)->toBe($pipelineId);
    expect($task->status)->toBe(TaskStatus::Running);

    Http::assertSent(function ($request) use ($taskId) {
        if (! str_contains($request->url(), 'trigger/pipeline')) {
            return false;
        }

        $body = $request->data();

        return $body['variables[VUNNIX_TASK_ID]'] === (string) $taskId
            && $body['variables[VUNNIX_TASK_TYPE]'] === 'issue_discussion'
            && $body['variables[VUNNIX_INTENT]'] === 'ask_command'
            && $body['variables[VUNNIX_QUESTION]'] === 'why is this function here?'
            && ! empty($body['variables[VUNNIX_TOKEN]']);
    });

    // ── 7. Simulate runner posting answer result ─────────────────

    $tokenService = app(TaskTokenService::class);
    $taskToken = $tokenService->generate($taskId);

    $resultResponse = $this->postJson(
        "/api/v1/tasks/{$taskId}/result",
        [
            'status' => 'completed',
            'result' => [
                'answer' => "This `calculateTotal` function exists to aggregate line item prices with tax calculations. It was introduced in MR !15 to replace the inline calculation that was duplicated across three controllers.\n\nThe key reason it's a separate function rather than a method on the `Order` model is that it needs access to the `TaxService` for regional tax rules, which would create a circular dependency if placed on the model.",
            ],
            'tokens' => [
                'input' => 12000,
                'output' => 1500,
                'thinking' => 300,
            ],
            'duration_seconds' => 25,
            'prompt_version' => [
                'skill' => 'backend-review-1.0',
                'claude_md' => 'executor-1.0',
                'schema' => 'ask-command-1.0',
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

    // ── 8. Assert: answer comment posted as new MR note ──────────

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'merge_requests/20/notes') || $request->method() !== 'POST') {
            return false;
        }

        $body = $request->data()['body'] ?? '';

        // Must contain the question and answer
        return str_contains($body, '🤖 Answer')
            && str_contains($body, 'why is this function here?')
            && str_contains($body, 'calculateTotal');
    });

    // ── 9. Assert: NO 3-layer output (no labels, no inline threads) ──

    // No label application (PUT to merge_requests)
    $labelRequests = collect(Http::recorded())
        ->filter(function ($pair) {
            [$request] = $pair;

            return str_contains($request->url(), 'merge_requests/20')
                && $request->method() === 'PUT';
        });

    expect($labelRequests)->toHaveCount(0);

    // No discussion threads
    $discussionRequests = collect(Http::recorded())
        ->filter(function ($pair) {
            [$request] = $pair;

            return str_contains($request->url(), '/discussions')
                && $request->method() === 'POST';
        });

    expect($discussionRequests)->toHaveCount(0);

    // ── 10. Assert: task completed ───────────────────────────────

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Completed);
    expect($task->completed_at)->not->toBeNull();
    expect($task->tokens_used)->toBe(12000 + 1500 + 300);
    // Answer comment ID stored on task
    expect($task->comment_id)->toBe($answerNoteId);
});

it('extracts question text from @ai ask with surrounding context', function () {
    $project = Project::factory()->enabled()->create([
        'gitlab_project_id' => 42004,
    ]);

    $webhookSecret = 'ask-context-secret';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $webhookSecret,
        'ci_trigger_token' => 'ci-trigger-ask-ctx',
    ]);

    $user = User::factory()->create(['gitlab_id' => 56]);
    grantReviewTriggerT42($user, $project);

    Http::fake([
        '*/api/v4/projects/42004/trigger/pipeline' => Http::response([
            'id' => 42301, 'status' => 'created',
        ], 201),
    ]);

    $response = $this->postJson('/webhook', [
        'object_kind' => 'note',
        'object_attributes' => [
            'note' => 'Hey team, @ai ask "can we simplify this error handling?" — I think it might be over-engineered',
            'noteable_type' => 'MergeRequest',
            'author_id' => 56,
        ],
        'merge_request' => [
            'iid' => 25,
        ],
    ], [
        'X-Gitlab-Token' => $webhookSecret,
        'X-Gitlab-Event' => 'Note Hook',
        'X-Gitlab-Event-UUID' => '00000000-0000-0000-0000-a00000000004',
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'intent' => 'ask_command',
        ])
        ->assertJsonStructure(['task_id']);

    $taskId = $response->json('task_id');
    $task = Task::find($taskId);
    expect($task)->not->toBeNull();
    expect($task->result['question'])->toBe('can we simplify this error handling?');
});

it('rejects @ai ask from user without review.trigger permission', function () {
    $project = Project::factory()->enabled()->create([
        'gitlab_project_id' => 42005,
    ]);

    $webhookSecret = 'ask-perm-denied';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $webhookSecret,
    ]);

    User::factory()->create(['gitlab_id' => 57]);

    $response = $this->postJson('/webhook', [
        'object_kind' => 'note',
        'object_attributes' => [
            'note' => '@ai ask "what does this do?"',
            'noteable_type' => 'MergeRequest',
            'author_id' => 57,
        ],
        'merge_request' => [
            'iid' => 30,
        ],
    ], [
        'X-Gitlab-Token' => $webhookSecret,
        'X-Gitlab-Event' => 'Note Hook',
        'X-Gitlab-Event-UUID' => '00000000-0000-0000-0000-a00000000005',
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'intent' => 'ask_command',
            'permission_denied' => true,
        ]);

    expect(Task::count())->toBe(0);
});

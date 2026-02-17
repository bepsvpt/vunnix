<?php

/**
 * T39: End-to-end integration test for the full Path A code review flow.
 *
 * Verifies the complete chain:
 *   Webhook → EventRouter → Deduplicator → TaskDispatchService → ProcessTask
 *   → TaskDispatcher (strategy + placeholder + pipeline trigger)
 *   → [simulated runner result via API]
 *   → ProcessTaskResult → ResultProcessor → PostSummaryComment (update-in-place)
 *   + PostInlineThreads + PostLabelsAndStatus
 *
 * Uses sync queue driver so all jobs run inline, and Http::fake() for all
 * GitLab API calls. No real external calls are made.
 */

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Build a valid code review result payload that passes CodeReviewSchema validation.
 *
 * Includes: 1 critical finding (triggers commit_status=failed), 1 major finding
 * (gets inline thread), and 1 minor finding (no inline thread — Layer 1 only).
 */
function codeReviewResult(): array
{
    return [
        'version' => '1.0',
        'summary' => [
            'risk_level' => 'high',
            'total_findings' => 3,
            'findings_by_severity' => [
                'critical' => 1,
                'major' => 1,
                'minor' => 1,
            ],
            'walkthrough' => [
                [
                    'file' => 'app/Services/PaymentService.php',
                    'change_summary' => 'Added Stripe payment processing with webhook handler',
                ],
                [
                    'file' => 'app/Http/Controllers/CheckoutController.php',
                    'change_summary' => 'New checkout endpoint with cart validation',
                ],
            ],
        ],
        'findings' => [
            [
                'id' => 1,
                'severity' => 'critical',
                'category' => 'security',
                'file' => 'app/Services/PaymentService.php',
                'line' => 42,
                'end_line' => 45,
                'title' => 'SQL injection via raw query',
                'description' => 'User input is interpolated directly into a raw SQL query without parameterization.',
                'suggestion' => 'Use Eloquent query builder or parameterized bindings instead of raw string interpolation.',
                'labels' => ['security', 'sql-injection'],
            ],
            [
                'id' => 2,
                'severity' => 'major',
                'category' => 'bug',
                'file' => 'app/Http/Controllers/CheckoutController.php',
                'line' => 78,
                'end_line' => 82,
                'title' => 'Missing null check on cart items',
                'description' => 'The cart items array is accessed without checking if the cart exists, causing a NullPointerException.',
                'suggestion' => 'Add an early return or null coalescing operator before accessing cart items.',
                'labels' => ['bug'],
            ],
            [
                'id' => 3,
                'severity' => 'minor',
                'category' => 'style',
                'file' => 'app/Services/PaymentService.php',
                'line' => 10,
                'end_line' => 10,
                'title' => 'Unused import',
                'description' => 'The Carbon import on line 10 is not used anywhere in the file.',
                'suggestion' => 'Remove the unused import statement.',
                'labels' => ['style'],
            ],
        ],
        'labels' => ['ai::reviewed', 'ai::risk-high', 'ai::security'],
        'commit_status' => 'failed',
    ];
}

/**
 * Build a complete runner result API payload wrapping a code review result.
 */
function runnerResultPayload(array $result): array
{
    return [
        'status' => 'completed',
        'result' => $result,
        'tokens' => [
            'input' => 15000,
            'output' => 3200,
            'thinking' => 800,
        ],
        'duration_seconds' => 45,
        'prompt_version' => [
            'skill' => 'backend-review-1.0',
            'claude_md' => 'executor-1.0',
            'schema' => 'code-review-1.0',
        ],
    ];
}

// ──────────────────────────────────────────────────────────────
//  Main E2E test
// ──────────────────────────────────────────────────────────────

it('completes full code review flow from webhook to 3-layer GitLab comments', function (): void {
    // ── 1. Set up project, config, and user ──────────────────────

    $project = Project::factory()->enabled()->create([
        'gitlab_project_id' => 12345,
    ]);

    $webhookSecret = 'e2e-test-secret';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $webhookSecret,
        'ci_trigger_token' => 'ci-trigger-token-abc',
    ]);

    // Create user matching the MR author_id in the webhook payload
    $user = User::factory()->create(['gitlab_id' => 7]);

    // ── 2. Http::fake() all GitLab API endpoints ─────────────────
    //
    // The full chain makes these calls in order:
    // (a) getMergeRequestChanges — TaskDispatcher resolves review strategy
    // (b) createMergeRequestNote — PostPlaceholderComment posts placeholder
    // (c) triggerPipeline — TaskDispatcher triggers CI pipeline
    // ... then after runner result is submitted ...
    // (d) updateMergeRequestNote — PostSummaryComment updates placeholder
    // (e) getMergeRequest — PostInlineThreads fetches diff_refs for positioning
    // (f) createMergeRequestDiscussion (×2) — PostInlineThreads posts threads
    // (g) getMergeRequest — PostLabelsAndStatus fetches MR SHA
    // (h) addMergeRequestLabels — PostLabelsAndStatus applies labels
    // (i) setCommitStatus — PostLabelsAndStatus sets commit status

    $placeholderNoteId = 99001;
    $pipelineId = 77001;

    Http::fake([
        // (a) MR changes — return PHP files so StrategyResolver picks backend-review
        '*/api/v4/projects/12345/merge_requests/42/changes' => Http::response([
            'changes' => [
                ['new_path' => 'app/Services/PaymentService.php', 'old_path' => 'app/Services/PaymentService.php'],
                ['new_path' => 'app/Http/Controllers/CheckoutController.php', 'old_path' => 'app/Http/Controllers/CheckoutController.php'],
            ],
        ], 200),

        // (b) Create placeholder note — return the note ID for update-in-place
        '*/api/v4/projects/12345/merge_requests/42/notes' => Http::response([
            'id' => $placeholderNoteId,
            'body' => '🤖 AI Review in progress…',
        ], 201),

        // (c) Trigger pipeline
        '*/api/v4/projects/12345/trigger/pipeline' => Http::response([
            'id' => $pipelineId,
            'status' => 'created',
        ], 201),

        // (d) Update note in-place (PUT) — matches the note update endpoint
        '*/api/v4/projects/12345/merge_requests/42/notes/'.$placeholderNoteId => Http::response([
            'id' => $placeholderNoteId,
            'body' => '(updated summary)',
        ], 200),

        // (e, g) Get MR details — needed by PostInlineThreads + PostLabelsAndStatus
        '*/api/v4/projects/12345/merge_requests/42' => Http::response([
            'iid' => 42,
            'sha' => 'abc123def456',
            'diff_refs' => [
                'base_sha' => 'base000',
                'start_sha' => 'start000',
                'head_sha' => 'head000',
            ],
        ], 200),

        // (f) Create discussion threads (POST to /discussions)
        '*/api/v4/projects/12345/merge_requests/42/discussions' => Http::response([
            'id' => 'discussion-001',
        ], 201),

        // (h) Update MR labels (PUT to /merge_requests/42)
        // Already matched by the getMergeRequest rule above — Laravel Http::fake
        // matches POST/PUT/GET separately when using Http::sequence or callback.
        // Since we used wildcard URL, PUT also matches. This is fine.

        // (i) Set commit status
        '*/api/v4/projects/12345/statuses/abc123def456' => Http::response([
            'id' => 1,
            'status' => 'failed',
        ], 201),
    ]);

    // ── 3. POST webhook with MR open payload ─────────────────────
    //
    // This triggers the full chain:
    // WebhookController → EventRouter (auto_review) → EventDeduplicator →
    // TaskDispatchService → ProcessTask (sync) → TaskDispatcher →
    // PostPlaceholderComment + triggerPipeline

    $webhookResponse = $this->postJson('/webhook', [
        'object_kind' => 'merge_request',
        'object_attributes' => [
            'iid' => 42,
            'action' => 'open',
            'source_branch' => 'feature/checkout',
            'target_branch' => 'main',
            'author_id' => 7,
            'last_commit' => ['id' => 'abc123def456'],
        ],
    ], [
        'X-Gitlab-Token' => $webhookSecret,
        'X-Gitlab-Event' => 'Merge Request Hook',
        'X-Gitlab-Event-UUID' => '00000000-0000-0000-0000-c00000000001',
    ]);

    // ── 4. Assert webhook accepted + task created ────────────────

    $webhookResponse->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'event_type' => 'merge_request',
            'intent' => 'auto_review',
        ])
        ->assertJsonStructure(['task_id']);

    $taskId = $webhookResponse->json('task_id');
    expect($taskId)->not->toBeNull();

    // Task was created in DB with correct fields
    $task = Task::find($taskId);
    expect($task)->not->toBeNull();
    expect($task->type)->toBe(TaskType::CodeReview);
    expect($task->project_id)->toBe($project->id);
    expect($task->mr_iid)->toBe(42);
    expect($task->commit_sha)->toBe('abc123def456');
    expect($task->user_id)->toBe($user->id);

    // ── 5. Assert: placeholder comment was posted ────────────────
    //
    // PostPlaceholderComment runs inline (sync queue) and stores the
    // returned note ID on the task's comment_id field.

    $task->refresh();
    expect($task->comment_id)->toBe($placeholderNoteId);

    // ── 6. Assert: pipeline was triggered ────────────────────────

    expect($task->pipeline_id)->toBe($pipelineId);
    expect($task->status)->toBe(TaskStatus::Running);

    // Verify the pipeline trigger included VUNNIX_* variables
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
    //
    // In production, the GitLab Runner executor would POST the result.
    // We simulate this by generating a valid task token and calling the
    // result API directly.

    $tokenService = app(TaskTokenService::class);
    $taskToken = $tokenService->generate($taskId);

    $resultResponse = $this->postJson(
        "/api/v1/tasks/{$taskId}/result",
        runnerResultPayload(codeReviewResult()),
        ['Authorization' => "Bearer {$taskToken}"],
    );

    $resultResponse->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'task_id' => $taskId,
            'task_status' => 'processing',
        ]);

    // ── 8. Assert: summary comment updated in-place (Layer 1) ────
    //
    // PostSummaryComment should have called updateMergeRequestNote
    // (not createMergeRequestNote) since the task has a comment_id
    // from the placeholder.

    Http::assertSent(function ($request) use ($placeholderNoteId) {
        return str_contains($request->url(), "notes/{$placeholderNoteId}")
            && $request->method() === 'PUT'
            && ! empty($request->data()['body']);
    });

    // ── 9. Assert: inline threads posted for critical/major (Layer 2) ──
    //
    // We have 1 critical + 1 major finding → 2 discussion threads.
    // Minor findings are excluded (only appear in Layer 1 summary).

    $discussionRequests = collect(Http::recorded())
        ->filter(function ($pair) {
            [$request] = $pair;

            return str_contains($request->url(), '/discussions')
                && $request->method() === 'POST';
        });

    expect($discussionRequests)->toHaveCount(2);

    // ── 10. Assert: labels applied (Layer 3) ─────────────────────
    //
    // LabelMapper computes labels independently: ai::reviewed + ai::risk-high + ai::security
    // (security because there's a finding with category=security).

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'merge_requests/42') || $request->method() !== 'PUT') {
            return false;
        }

        $addLabels = $request->data()['add_labels'] ?? '';

        return str_contains($addLabels, 'ai::reviewed')
            && str_contains($addLabels, 'ai::risk-high')
            && str_contains($addLabels, 'ai::security');
    });

    // ── 11. Assert: commit status set to failed (Layer 3) ────────
    //
    // Critical findings → commit_status = 'failed'

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'statuses/abc123def456')
            && $request->method() === 'POST'
            && ($request->data()['state'] ?? '') === 'failed';
    });

    // ── 12. Assert: task completed ───────────────────────────────

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Completed);
    expect($task->completed_at)->not->toBeNull();
    expect($task->tokens_used)->toBe(15000 + 3200 + 800);
});

// ──────────────────────────────────────────────────────────────
//  Placeholder-then-update pattern test
// ──────────────────────────────────────────────────────────────

it('uses placeholder-then-update pattern: creates placeholder then updates in-place', function (): void {
    $project = Project::factory()->enabled()->create([
        'gitlab_project_id' => 55555,
    ]);

    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => 'placeholder-test-secret',
        'ci_trigger_token' => 'trigger-token-placeholder',
    ]);

    User::factory()->create(['gitlab_id' => 7]);

    $placeholderNoteId = 88001;

    Http::fake([
        '*/api/v4/projects/55555/merge_requests/10/changes' => Http::response([
            'changes' => [
                ['new_path' => 'app/Models/Order.php', 'old_path' => 'app/Models/Order.php'],
            ],
        ], 200),

        '*/api/v4/projects/55555/merge_requests/10/notes' => Http::response([
            'id' => $placeholderNoteId,
            'body' => '🤖 AI Review in progress…',
        ], 201),

        '*/api/v4/projects/55555/trigger/pipeline' => Http::response([
            'id' => 90001, 'status' => 'created',
        ], 201),

        '*/api/v4/projects/55555/merge_requests/10/notes/'.$placeholderNoteId => Http::response([
            'id' => $placeholderNoteId,
            'body' => '(summary)',
        ], 200),

        '*/api/v4/projects/55555/merge_requests/10' => Http::response([
            'iid' => 10, 'sha' => 'deadbeef',
            'diff_refs' => ['base_sha' => 'b', 'start_sha' => 's', 'head_sha' => 'h'],
        ], 200),

        '*/api/v4/projects/55555/merge_requests/10/discussions' => Http::response([
            'id' => 'd-001',
        ], 201),

        '*/api/v4/projects/55555/statuses/deadbeef' => Http::response([
            'id' => 1, 'status' => 'success',
        ], 201),
    ]);

    // Send webhook
    $response = $this->postJson('/webhook', [
        'object_kind' => 'merge_request',
        'object_attributes' => [
            'iid' => 10,
            'action' => 'open',
            'source_branch' => 'feature/orders',
            'target_branch' => 'main',
            'author_id' => 7,
            'last_commit' => ['id' => 'deadbeef'],
        ],
    ], [
        'X-Gitlab-Token' => 'placeholder-test-secret',
        'X-Gitlab-Event' => 'Merge Request Hook',
    ]);

    $taskId = $response->json('task_id');
    $task = Task::find($taskId);

    // After webhook: placeholder posted, comment_id stored
    expect($task->comment_id)->toBe($placeholderNoteId);

    // Create a clean result (no critical findings → commit_status = success)
    $cleanResult = codeReviewResult();
    $cleanResult['summary']['risk_level'] = 'low';
    $cleanResult['summary']['findings_by_severity'] = ['critical' => 0, 'major' => 1, 'minor' => 1];
    $cleanResult['findings'] = array_filter(
        $cleanResult['findings'],
        fn ($f) => $f['severity'] !== 'critical',
    );
    $cleanResult['findings'] = array_values($cleanResult['findings']);
    $cleanResult['summary']['total_findings'] = count($cleanResult['findings']);
    $cleanResult['commit_status'] = 'success';
    $cleanResult['labels'] = ['ai::reviewed', 'ai::risk-low'];

    // Submit runner result
    $taskToken = app(TaskTokenService::class)->generate($taskId);
    $this->postJson(
        "/api/v1/tasks/{$taskId}/result",
        runnerResultPayload($cleanResult),
        ['Authorization' => "Bearer {$taskToken}"],
    )->assertOk();

    // The summary comment was updated via PUT to the placeholder note ID
    Http::assertSent(function ($request) use ($placeholderNoteId) {
        return str_contains($request->url(), "notes/{$placeholderNoteId}")
            && $request->method() === 'PUT';
    });

    // No new note was created (only 1 POST to /notes — the placeholder)
    $noteCreateRequests = collect(Http::recorded())
        ->filter(function ($pair) {
            [$request] = $pair;

            return str_contains($request->url(), '/notes')
                && ! str_contains($request->url(), '/notes/')  // exclude /notes/{id}
                && $request->method() === 'POST';
        });

    expect($noteCreateRequests)->toHaveCount(1, 'Only one note POST (placeholder) should be made');
});

// ──────────────────────────────────────────────────────────────
//  Clean review (no critical findings) test
// ──────────────────────────────────────────────────────────────

it('sets commit status to success when no critical findings exist', function (): void {
    $project = Project::factory()->enabled()->create([
        'gitlab_project_id' => 66666,
    ]);

    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => 'clean-review-secret',
        'ci_trigger_token' => 'trigger-token-clean',
    ]);

    User::factory()->create(['gitlab_id' => 7]);

    Http::fake([
        '*/api/v4/projects/66666/merge_requests/5/changes' => Http::response([
            'changes' => [
                ['new_path' => 'resources/views/welcome.vue', 'old_path' => 'resources/views/welcome.vue'],
            ],
        ], 200),

        '*/api/v4/projects/66666/merge_requests/5/notes' => Http::response([
            'id' => 77001, 'body' => 'placeholder',
        ], 201),

        '*/api/v4/projects/66666/trigger/pipeline' => Http::response([
            'id' => 80001, 'status' => 'created',
        ], 201),

        '*/api/v4/projects/66666/merge_requests/5/notes/77001' => Http::response([
            'id' => 77001, 'body' => 'updated',
        ], 200),

        '*/api/v4/projects/66666/merge_requests/5' => Http::response([
            'iid' => 5, 'sha' => 'cleansha123',
            'diff_refs' => ['base_sha' => 'b', 'start_sha' => 's', 'head_sha' => 'h'],
        ], 200),

        '*/api/v4/projects/66666/merge_requests/5/discussions' => Http::response([
            'id' => 'd-002',
        ], 201),

        '*/api/v4/projects/66666/statuses/cleansha123' => Http::response([
            'id' => 1, 'status' => 'success',
        ], 201),
    ]);

    // Webhook
    $response = $this->postJson('/webhook', [
        'object_kind' => 'merge_request',
        'object_attributes' => [
            'iid' => 5,
            'action' => 'open',
            'source_branch' => 'feature/ui-tweak',
            'target_branch' => 'main',
            'author_id' => 7,
            'last_commit' => ['id' => 'cleansha123'],
        ],
    ], [
        'X-Gitlab-Token' => 'clean-review-secret',
        'X-Gitlab-Event' => 'Merge Request Hook',
    ]);

    $taskId = $response->json('task_id');

    // Build a result with only minor findings (no critical/major)
    $cleanResult = [
        'version' => '1.0',
        'summary' => [
            'risk_level' => 'low',
            'total_findings' => 1,
            'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 1],
            'walkthrough' => [
                ['file' => 'resources/views/welcome.vue', 'change_summary' => 'Updated welcome page styling'],
            ],
        ],
        'findings' => [
            [
                'id' => 1,
                'severity' => 'minor',
                'category' => 'style',
                'file' => 'resources/views/welcome.vue',
                'line' => 15,
                'end_line' => 15,
                'title' => 'Inconsistent spacing',
                'description' => 'Inconsistent indentation on line 15.',
                'suggestion' => 'Use 2-space indentation consistently.',
                'labels' => ['style'],
            ],
        ],
        'labels' => ['ai::reviewed', 'ai::risk-low'],
        'commit_status' => 'success',
    ];

    $taskToken = app(TaskTokenService::class)->generate($taskId);
    $this->postJson(
        "/api/v1/tasks/{$taskId}/result",
        runnerResultPayload($cleanResult),
        ['Authorization' => "Bearer {$taskToken}"],
    )->assertOk();

    // No inline threads (no critical/major findings)
    $discussionRequests = collect(Http::recorded())
        ->filter(function ($pair) {
            [$request] = $pair;

            return str_contains($request->url(), '/discussions')
                && $request->method() === 'POST';
        });

    expect($discussionRequests)->toHaveCount(0);

    // Commit status should be success (no critical findings)
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'statuses/cleansha123')
            && $request->method() === 'POST'
            && ($request->data()['state'] ?? '') === 'success';
    });

    // Labels should be ai::reviewed + ai::risk-low (no ai::security)
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'merge_requests/5') || $request->method() !== 'PUT') {
            return false;
        }

        $addLabels = $request->data()['add_labels'] ?? '';

        return str_contains($addLabels, 'ai::reviewed')
            && str_contains($addLabels, 'ai::risk-low')
            && ! str_contains($addLabels, 'ai::security');
    });

    // Task completed
    $task = Task::find($taskId);
    expect($task->status)->toBe(TaskStatus::Completed);
});

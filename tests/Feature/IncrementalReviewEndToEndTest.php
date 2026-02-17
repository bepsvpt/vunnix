<?php

/**
 * T40: End-to-end integration test for the incremental review flow.
 *
 * Exercises the complete chain for TWO sequential reviews on the same MR:
 *
 * Phase 1 (initial review):
 *   Webhook MR open → EventRouter (auto_review) → TaskDispatchService
 *   → ProcessTask → PostPlaceholderComment (new) + triggerPipeline
 *   → Runner result → PostSummaryComment + PostInlineThreads + PostLabelsAndStatus
 *
 * Phase 2 (incremental review after push):
 *   Webhook Push Hook → EventRouter (incremental_review) → TaskDispatchService
 *   → resolveMrIidFromPush (GitLab API) → ProcessTask
 *   → PostPlaceholderComment (reuses previous comment_id)
 *   → Runner result → PostSummaryComment (with timestamp) + PostInlineThreads (dedup)
 *   + PostLabelsAndStatus (stale label removal)
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

// ─── Helpers ─────────────────────────────────────────────────────

function incrementalReviewResult(string $riskLevel = 'high'): array
{
    $findings = [
        [
            'id' => 1,
            'severity' => 'critical',
            'category' => 'security',
            'file' => 'app/Services/PaymentService.php',
            'line' => 42,
            'end_line' => 45,
            'title' => 'SQL injection via raw query',
            'description' => 'User input is interpolated directly into a raw SQL query.',
            'suggestion' => 'Use parameterized queries.',
            'labels' => ['security'],
        ],
        [
            'id' => 2,
            'severity' => 'major',
            'category' => 'bug',
            'file' => 'app/Http/Controllers/CheckoutController.php',
            'line' => 78,
            'end_line' => 82,
            'title' => 'Missing null check on cart items',
            'description' => 'Cart items array accessed without null check.',
            'suggestion' => 'Add null coalescing operator.',
            'labels' => ['bug'],
        ],
    ];

    if ($riskLevel === 'high') {
        return [
            'version' => '1.0',
            'summary' => [
                'risk_level' => 'high',
                'total_findings' => 2,
                'findings_by_severity' => ['critical' => 1, 'major' => 1, 'minor' => 0],
                'walkthrough' => [
                    ['file' => 'app/Services/PaymentService.php', 'change_summary' => 'Added payment logic'],
                    ['file' => 'app/Http/Controllers/CheckoutController.php', 'change_summary' => 'New checkout flow'],
                ],
            ],
            'findings' => $findings,
            'labels' => ['ai::reviewed', 'ai::risk-high', 'ai::security'],
            'commit_status' => 'failed',
        ];
    }

    // Low risk: only the null check finding (major, not critical) + a NEW finding
    return [
        'version' => '1.0',
        'summary' => [
            'risk_level' => 'medium',
            'total_findings' => 2,
            'findings_by_severity' => ['critical' => 0, 'major' => 2, 'minor' => 0],
            'walkthrough' => [
                ['file' => 'app/Services/PaymentService.php', 'change_summary' => 'Fixed SQL injection'],
                ['file' => 'app/Http/Controllers/CheckoutController.php', 'change_summary' => 'Updated checkout flow'],
            ],
        ],
        'findings' => [
            // Same finding as phase 1 — should be DEDUPLICATED
            [
                'id' => 1,
                'severity' => 'major',
                'category' => 'bug',
                'file' => 'app/Http/Controllers/CheckoutController.php',
                'line' => 78,
                'end_line' => 82,
                'title' => 'Missing null check on cart items',
                'description' => 'Cart items array accessed without null check.',
                'suggestion' => 'Add null coalescing operator.',
                'labels' => ['bug'],
            ],
            // NEW finding — should get a NEW thread
            [
                'id' => 2,
                'severity' => 'major',
                'category' => 'performance',
                'file' => 'app/Services/PaymentService.php',
                'line' => 55,
                'end_line' => 60,
                'title' => 'N+1 query in payment loop',
                'description' => 'Each payment calls DB individually inside loop.',
                'suggestion' => 'Use eager loading or batch query.',
                'labels' => ['performance'],
            ],
        ],
        'labels' => ['ai::reviewed', 'ai::risk-medium'],
        'commit_status' => 'success',
    ];
}

function incrementalRunnerPayload(array $result): array
{
    return [
        'status' => 'completed',
        'result' => $result,
        'tokens' => [
            'input' => 12000,
            'output' => 2800,
            'thinking' => 600,
        ],
        'duration_seconds' => 38,
        'prompt_version' => [
            'skill' => 'backend-review-1.0',
            'claude_md' => 'executor-1.0',
            'schema' => 'code-review-1.0',
        ],
    ];
}

// ──────────────────────────────────────────────────────────────
//  Main E2E incremental review test
// ──────────────────────────────────────────────────────────────

it('updates summary in-place with timestamp and deduplicates threads on incremental review', function (): void {
    // ── 1. Set up project, config, and user ──────────────────────

    $project = Project::factory()->enabled()->create([
        'gitlab_project_id' => 44444,
    ]);

    $webhookSecret = 'incr-review-secret';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $webhookSecret,
        'ci_trigger_token' => 'ci-trigger-incr',
    ]);

    $user = User::factory()->create(['gitlab_id' => 7]);

    // ══════════════════════════════════════════════════════════════
    //  PHASE 1: Initial code review (MR open)
    // ══════════════════════════════════════════════════════════════

    $placeholderNoteId = 55001;
    $pipelineId1 = 66001;

    Http::fake([
        // MR changes → PHP files → backend-review strategy
        '*/api/v4/projects/44444/merge_requests/15/changes' => Http::response([
            'changes' => [
                ['new_path' => 'app/Services/PaymentService.php', 'old_path' => 'app/Services/PaymentService.php'],
                ['new_path' => 'app/Http/Controllers/CheckoutController.php', 'old_path' => 'app/Http/Controllers/CheckoutController.php'],
            ],
        ], 200),

        // Create placeholder note
        '*/api/v4/projects/44444/merge_requests/15/notes' => Http::response([
            'id' => $placeholderNoteId,
            'body' => '🤖 AI Review in progress…',
        ], 201),

        // Trigger pipeline
        '*/api/v4/projects/44444/trigger/pipeline' => Http::response([
            'id' => $pipelineId1, 'status' => 'created',
        ], 201),

        // Update note in-place (summary comment)
        '*/api/v4/projects/44444/merge_requests/15/notes/'.$placeholderNoteId => Http::response([
            'id' => $placeholderNoteId, 'body' => '(summary)',
        ], 200),

        // MR details (for inline threads + labels)
        '*/api/v4/projects/44444/merge_requests/15' => Http::response([
            'iid' => 15,
            'sha' => 'phase1sha',
            'diff_refs' => ['base_sha' => 'b1', 'start_sha' => 's1', 'head_sha' => 'h1'],
        ], 200),

        // Discussion threads: GET (dedup check, empty) then POST (create threads)
        '*/api/v4/projects/44444/merge_requests/15/discussions*' => Http::sequence()
            ->push([], 200)                                        // GET existing discussions (empty — first review)
            ->push(['id' => 'disc-phase1-1'], 201)                 // POST thread #1 (SQL injection)
            ->push(['id' => 'disc-phase1-2'], 201),                // POST thread #2 (null check)

        // Commit status
        '*/api/v4/projects/44444/statuses/phase1sha' => Http::response([
            'id' => 1, 'status' => 'failed',
        ], 201),
    ]);

    // ── 2. Send MR open webhook ──────────────────────────────────

    $response1 = $this->postJson('/webhook', [
        'object_kind' => 'merge_request',
        'object_attributes' => [
            'iid' => 15,
            'action' => 'open',
            'source_branch' => 'feature/payments',
            'target_branch' => 'main',
            'author_id' => 7,
            'last_commit' => ['id' => 'phase1sha'],
        ],
    ], [
        'X-Gitlab-Token' => $webhookSecret,
        'X-Gitlab-Event' => 'Merge Request Hook',
        'X-Gitlab-Event-UUID' => '00000000-0000-0000-0000-b00000000001',
    ]);

    $response1->assertOk()->assertJson(['intent' => 'auto_review']);
    $taskId1 = $response1->json('task_id');

    // ── 3. Submit runner result for phase 1 ──────────────────────

    $tokenService = app(TaskTokenService::class);
    $taskToken1 = $tokenService->generate($taskId1);

    $this->postJson(
        "/api/v1/tasks/{$taskId1}/result",
        incrementalRunnerPayload(incrementalReviewResult('high')),
        ['Authorization' => "Bearer {$taskToken1}"],
    )->assertOk();

    // Phase 1 task completed
    $task1 = Task::find($taskId1);
    $task1->refresh();
    expect($task1->status)->toBe(TaskStatus::Completed);
    expect($task1->comment_id)->toBe($placeholderNoteId);

    // Phase 1 created 2 discussion threads (both new — no dedup)
    $phase1DiscussionPosts = collect(Http::recorded())
        ->filter(function ($pair) {
            [$request] = $pair;

            return str_contains($request->url(), '/discussions')
                && $request->method() === 'POST';
        });

    expect($phase1DiscussionPosts)->toHaveCount(2);

    // ══════════════════════════════════════════════════════════════
    //  PHASE 2: Incremental review (push to same branch)
    // ══════════════════════════════════════════════════════════════

    // Reset the Http client entirely to clear phase 1's exhausted sequences.
    // Http::fake() merges into stubCallbacks rather than replacing, so stale
    // pattern stubs from phase 1 would match first and throw OutOfBounds.
    Http::swap(new \Illuminate\Http\Client\Factory);

    $phase2DiscussionSeq = Http::sequence()
        // GET existing discussions — includes the "Missing null check" thread from phase 1
        ->push([
            [
                'id' => 'disc-phase1-2',
                'notes' => [[
                    'body' => "🟠 **Major** | Bug\n\n**Missing null check on cart items**\n\nCart items array accessed without null check.",
                    'position' => [
                        'new_path' => 'app/Http/Controllers/CheckoutController.php',
                        'new_line' => 78,
                    ],
                ]],
            ],
        ], 200)
        // POST — only for the NEW finding (N+1 query)
        ->push(['id' => 'disc-phase2-new'], 201);

    Http::fake(function ($request) use ($placeholderNoteId, $phase2DiscussionSeq) {
        $url = $request->url();
        $method = $request->method();

        // findOpenMergeRequestForBranch — GET /merge_requests?source_branch=...
        if (str_contains($url, '/merge_requests') && str_contains($url, 'source_branch=')) {
            return Http::response([['iid' => 15]], 200);
        }

        // MR changes
        if (str_contains($url, '/merge_requests/15/changes')) {
            return Http::response([
                'changes' => [
                    ['new_path' => 'app/Services/PaymentService.php', 'old_path' => 'app/Services/PaymentService.php'],
                ],
            ], 200);
        }

        // Update placeholder note (PUT) or summary update (PUT)
        if (str_contains($url, "/notes/{$placeholderNoteId}") && $method === 'PUT') {
            return Http::response([
                'id' => $placeholderNoteId,
                'body' => '(updated)',
            ], 200);
        }

        // Trigger pipeline
        if (str_contains($url, 'trigger/pipeline')) {
            return Http::response(['id' => 66002, 'status' => 'created'], 201);
        }

        // Discussion threads (GET for dedup, POST for new)
        if (str_contains($url, '/discussions')) {
            return $phase2DiscussionSeq($request);
        }

        // MR details (GET /merge_requests/15)
        if (str_contains($url, '/merge_requests/15')) {
            return Http::response([
                'iid' => 15,
                'sha' => 'phase2sha',
                'diff_refs' => ['base_sha' => 'b2', 'start_sha' => 's2', 'head_sha' => 'h2'],
            ], 200);
        }

        // Commit status
        if (str_contains($url, '/statuses/phase2sha')) {
            return Http::response(['id' => 2, 'status' => 'success'], 201);
        }

        // Default fallback
        return Http::response([], 200);
    });

    // ── 4. Send Push Hook webhook ────────────────────────────────

    $response2 = $this->postJson('/webhook', [
        'object_kind' => 'push',
        'ref' => 'refs/heads/feature/payments',
        'before' => 'phase1sha',
        'after' => 'phase2sha',
        'user_id' => 7,
        'commits' => [
            ['id' => 'phase2sha', 'message' => 'Fix SQL injection'],
        ],
        'total_commits_count' => 1,
    ], [
        'X-Gitlab-Token' => $webhookSecret,
        'X-Gitlab-Event' => 'Push Hook',
        'X-Gitlab-Event-UUID' => '00000000-0000-0000-0000-b00000000002',
    ]);

    $response2->assertOk()->assertJson(['intent' => 'incremental_review']);
    $taskId2 = $response2->json('task_id');
    expect($taskId2)->not->toBeNull();

    // ── 5. Verify task 2 resolved MR IID from push ──────────────

    $task2 = Task::find($taskId2);
    expect($task2->mr_iid)->toBe(15);
    expect($task2->commit_sha)->toBe('phase2sha');
    expect($task2->type)->toBe(TaskType::CodeReview);

    // ── 6. Verify placeholder reused previous comment_id ─────────

    $task2->refresh();
    expect($task2->comment_id)->toBe($placeholderNoteId);

    // Verify the placeholder was UPDATED (PUT), not created (POST)
    Http::assertSent(function ($request) use ($placeholderNoteId) {
        return str_contains($request->url(), "notes/{$placeholderNoteId}")
            && $request->method() === 'PUT';
    });

    // ── 7. Submit runner result for phase 2 ──────────────────────

    $taskToken2 = $tokenService->generate($taskId2);

    $this->postJson(
        "/api/v1/tasks/{$taskId2}/result",
        incrementalRunnerPayload(incrementalReviewResult('medium')),
        ['Authorization' => "Bearer {$taskToken2}"],
    )->assertOk();

    // ── 8. Verify summary updated with timestamp ─────────────────
    //
    // PostSummaryComment detects this is an incremental review (same
    // comment_id as completed task1) and includes "📝 Updated:" timestamp.

    Http::assertSent(function ($request) use ($placeholderNoteId) {
        if (! str_contains($request->url(), "notes/{$placeholderNoteId}")) {
            return false;
        }
        if ($request->method() !== 'PUT') {
            return false;
        }

        $body = $request->data()['body'] ?? '';

        // The second PUT is the summary update (first PUT was the placeholder re-use)
        return str_contains($body, '📝 Updated:')
            && str_contains($body, 're-reviewed after new commits');
    });

    // ── 9. Verify thread deduplication (D33) ─────────────────────
    //
    // Phase 2 has 2 findings: "Missing null check" (exists) + "N+1 query" (new).
    // Only 1 NEW discussion POST should be made (for N+1 query).

    $phase2DiscussionPosts = collect(Http::recorded())
        ->filter(function ($pair) {
            [$request] = $pair;

            return str_contains($request->url(), '/discussions')
                && $request->method() === 'POST';
        });

    expect($phase2DiscussionPosts)->toHaveCount(1);

    // Verify the POST was for the NEW finding (N+1 query), not the duplicate
    Http::assertSent(function ($request) {
        if ($request->method() !== 'POST' || ! str_contains($request->url(), '/discussions')) {
            return false;
        }

        $body = $request['body'] ?? '';

        return str_contains($body, 'N+1 query in payment loop');
    });

    // Verify NO POST was made for the deduplicated finding
    Http::assertNotSent(function ($request) {
        if ($request->method() !== 'POST' || ! str_contains($request->url(), '/discussions')) {
            return false;
        }

        $body = $request['body'] ?? '';

        return str_contains($body, 'Missing null check on cart items');
    });

    // ── 10. Verify label replacement (D56) ───────────────────────
    //
    // Phase 1 was risk-high. Phase 2 is risk-medium.
    // PostLabelsAndStatus should remove stale risk labels before adding new ones.

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'merge_requests/15') || $request->method() !== 'PUT') {
            return false;
        }

        $removeLabels = $request->data()['remove_labels'] ?? '';

        // New risk is 'medium', so 'high' and 'low' should be removed
        return str_contains($removeLabels, 'ai::risk-high')
            && str_contains($removeLabels, 'ai::risk-low')
            && ! str_contains($removeLabels, 'ai::risk-medium');
    });

    // New labels should include ai::risk-medium
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'merge_requests/15') || $request->method() !== 'PUT') {
            return false;
        }

        $addLabels = $request->data()['add_labels'] ?? '';

        return str_contains($addLabels, 'ai::reviewed')
            && str_contains($addLabels, 'ai::risk-medium');
    });

    // ── 11. Verify task 2 completed ──────────────────────────────

    $task2->refresh();
    expect($task2->status)->toBe(TaskStatus::Completed);
    expect($task2->completed_at)->not->toBeNull();
});

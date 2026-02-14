<?php

/**
 * T43: End-to-end integration test for @ai on Issue (issue discussion).
 *
 * Flow:
 *   Webhook (Note Hook, noteable_type=Issue) → EventRouter (issue_discussion, normal priority)
 *   → Permission check (review.trigger) → TaskDispatchService (IssueDiscussion)
 *   → ProcessTask → TaskDispatcher (no placeholder, pipeline trigger with VUNNIX_ISSUE_IID)
 *   → [simulated runner result via API]
 *   → ProcessTaskResult → ResultProcessor (no schema) → PostIssueComment
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
function grantReviewTriggerT43(User $user, Project $project): void
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
//  @ai on Issue — full issue discussion flow
// ──────────────────────────────────────────────────────────────

it('completes full @ai issue discussion flow with response posted as Issue comment', function () {
    // ── 1. Set up project, config, and user with permission ──────

    $project = Project::factory()->enabled()->create([
        'gitlab_project_id' => 43001,
    ]);

    $webhookSecret = 'issue-discussion-e2e-secret';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $webhookSecret,
        'ci_trigger_token' => 'ci-trigger-issue-disc',
    ]);

    $user = User::factory()->create(['gitlab_id' => 60]);
    grantReviewTriggerT43($user, $project);

    // ── 2. Http::fake() all GitLab API endpoints ─────────────────

    $pipelineId = 43101;
    $issueNoteId = 43102;

    Http::fake([
        // Trigger pipeline
        '*/api/v4/projects/43001/trigger/pipeline' => Http::response([
            'id' => $pipelineId,
            'status' => 'created',
        ], 201),

        // Create issue comment (response posted as Issue note)
        '*/api/v4/projects/43001/issues/5/notes' => Http::response([
            'id' => $issueNoteId,
            'body' => '### 🤖 AI Response...',
        ], 201),
    ]);

    // ── 3. POST webhook with @ai note on Issue ───────────────────

    $webhookResponse = $this->postJson('/webhook', [
        'object_kind' => 'note',
        'object_attributes' => [
            'note' => '@ai explain the auth flow in this project',
            'noteable_type' => 'Issue',
            'author_id' => 60,
        ],
        'issue' => [
            'iid' => 5,
        ],
    ], [
        'X-Gitlab-Token' => $webhookSecret,
        'X-Gitlab-Event' => 'Note Hook',
        'X-Gitlab-Event-UUID' => 'issue-disc-uuid-001',
    ]);

    // ── 4. Assert webhook accepted + task created ────────────────

    $webhookResponse->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'event_type' => 'note',
            'intent' => 'issue_discussion',
        ])
        ->assertJsonMissing(['permission_denied' => true])
        ->assertJsonStructure(['task_id']);

    $taskId = $webhookResponse->json('task_id');
    expect($taskId)->not->toBeNull();

    // Task was created with correct fields — IssueDiscussion, Normal priority
    $task = Task::find($taskId);
    expect($task)->not->toBeNull();
    expect($task->type)->toBe(TaskType::IssueDiscussion);
    expect($task->priority)->toBe(TaskPriority::Normal);
    expect($task->project_id)->toBe($project->id);
    expect($task->issue_iid)->toBe(5);
    expect($task->mr_iid)->toBeNull();
    expect($task->user_id)->toBe($user->id);
    expect($task->result['intent'])->toBe('issue_discussion');

    // ── 5. Assert: NO placeholder comment (IssueDiscussion) ──────

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
            && $body['variables[VUNNIX_TASK_TYPE]'] === 'issue_discussion'
            && $body['variables[VUNNIX_INTENT]'] === 'issue_discussion'
            && $body['variables[VUNNIX_ISSUE_IID]'] === '5'
            && ! empty($body['variables[VUNNIX_TOKEN]'])
            && ! empty($body['variables[VUNNIX_STRATEGY]']);
    });

    // ── 7. Simulate runner posting issue discussion result ────────

    $tokenService = app(TaskTokenService::class);
    $taskToken = $tokenService->generate($taskId);

    $resultResponse = $this->postJson(
        "/api/v1/tasks/{$taskId}/result",
        [
            'status' => 'completed',
            'result' => [
                'version' => '1.0',
                'response' => "The authentication flow uses Laravel's built-in OAuth integration via Socialite.\n\n1. **Login:** Users click \"Sign in with GitLab\" which redirects to the GitLab OAuth endpoint\n2. **Callback:** After authorization, GitLab redirects back with an auth code\n3. **Token Exchange:** `AuthController::callback()` at `app/Http/Controllers/AuthController.php:45` exchanges the code for an access token\n4. **User Sync:** `UserSyncService::syncFromGitLab()` at `app/Services/UserSyncService.php:23` creates or updates the local user record\n\nThe access token is stored in the session, not in the database (per D153).",
                'references' => [
                    [
                        'file' => 'app/Http/Controllers/AuthController.php',
                        'line' => 45,
                        'description' => 'OAuth callback handler',
                    ],
                    [
                        'file' => 'app/Services/UserSyncService.php',
                        'line' => 23,
                        'description' => 'User sync from GitLab',
                    ],
                ],
                'confidence' => 'high',
            ],
            'tokens' => [
                'input' => 80000,
                'output' => 15000,
                'thinking' => 5000,
            ],
            'duration_seconds' => 90,
            'prompt_version' => [
                'skill' => 'backend-review-1.0',
                'claude_md' => 'executor-1.0',
                'schema' => 'issue-discussion-1.0',
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

    // ── 8. Assert: response posted as Issue comment ──────────────

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'issues/5/notes') || $request->method() !== 'POST') {
            return false;
        }

        $body = $request->data()['body'] ?? '';

        // Must contain the AI response header and auth flow content
        return str_contains($body, '🤖 AI Response')
            && str_contains($body, 'OAuth')
            && str_contains($body, 'AuthController');
    });

    // ── 9. Assert: NO 3-layer output (no labels, no inline threads, no MR notes) ──

    // No MR note creation
    $mrNoteRequests = collect(Http::recorded())
        ->filter(function ($pair) {
            [$request] = $pair;

            return str_contains($request->url(), 'merge_requests')
                && str_contains($request->url(), '/notes')
                && $request->method() === 'POST';
        });

    expect($mrNoteRequests)->toHaveCount(0);

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
    expect($task->tokens_used)->toBe(80000 + 15000 + 5000);
    // Issue comment ID stored on task
    expect($task->comment_id)->toBe($issueNoteId);
});

// ──────────────────────────────────────────────────────────────
//  Permission denied — user without review.trigger
// ──────────────────────────────────────────────────────────────

it('rejects @ai on Issue from user without review.trigger permission', function () {
    $project = Project::factory()->enabled()->create([
        'gitlab_project_id' => 43002,
    ]);

    $webhookSecret = 'issue-disc-perm-denied';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $webhookSecret,
    ]);

    // User exists but has NO review.trigger permission
    User::factory()->create(['gitlab_id' => 61]);

    $response = $this->postJson('/webhook', [
        'object_kind' => 'note',
        'object_attributes' => [
            'note' => '@ai explain this code',
            'noteable_type' => 'Issue',
            'author_id' => 61,
        ],
        'issue' => [
            'iid' => 10,
        ],
    ], [
        'X-Gitlab-Token' => $webhookSecret,
        'X-Gitlab-Event' => 'Note Hook',
        'X-Gitlab-Event-UUID' => 'issue-disc-perm-denied-001',
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'intent' => 'issue_discussion',
            'permission_denied' => true,
        ]);

    expect(Task::count())->toBe(0);
});

// ──────────────────────────────────────────────────────────────
//  Bot event filtering — bot's own Issue comments are ignored
// ──────────────────────────────────────────────────────────────

it('ignores bot-authored @ai notes on Issues (D154)', function () {
    $project = Project::factory()->enabled()->create([
        'gitlab_project_id' => 43003,
    ]);

    $webhookSecret = 'issue-disc-bot-filter';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $webhookSecret,
    ]);

    // Set bot account ID in config
    config(['services.gitlab.bot_account_id' => 999]);

    $response = $this->postJson('/webhook', [
        'object_kind' => 'note',
        'object_attributes' => [
            'note' => '### 🤖 AI Response\n\n@ai this is a bot response that mentions @ai',
            'noteable_type' => 'Issue',
            'author_id' => 999, // Bot account
        ],
        'issue' => [
            'iid' => 15,
        ],
    ], [
        'X-Gitlab-Token' => $webhookSecret,
        'X-Gitlab-Event' => 'Note Hook',
        'X-Gitlab-Event-UUID' => 'issue-disc-bot-001',
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'intent' => null, // Filtered before classification
        ]);

    expect(Task::count())->toBe(0);
});

// ──────────────────────────────────────────────────────────────
//  Issue note without @ai mention — ignored
// ──────────────────────────────────────────────────────────────

it('ignores Issue notes without @ai mention', function () {
    $project = Project::factory()->enabled()->create([
        'gitlab_project_id' => 43004,
    ]);

    $webhookSecret = 'issue-disc-no-ai';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $webhookSecret,
    ]);

    $response = $this->postJson('/webhook', [
        'object_kind' => 'note',
        'object_attributes' => [
            'note' => 'This is just a regular comment on an Issue',
            'noteable_type' => 'Issue',
            'author_id' => 60,
        ],
        'issue' => [
            'iid' => 20,
        ],
    ], [
        'X-Gitlab-Token' => $webhookSecret,
        'X-Gitlab-Event' => 'Note Hook',
        'X-Gitlab-Event-UUID' => 'issue-disc-no-ai-001',
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'intent' => null, // No @ai mention = no intent
        ]);

    expect(Task::count())->toBe(0);
});

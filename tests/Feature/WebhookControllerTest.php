<?php

use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Models\Role;
use App\Models\User;
use App\Models\WebhookEventLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// T39: Fake the queue so ProcessTask jobs dispatched by TaskDispatchService
// don't run inline. WebhookControllerTest tests webhook acceptance, not
// downstream job execution — that's covered by CodeReviewEndToEndTest.
beforeEach(function () {
    Queue::fake();
});

/**
 * Helper: create an enabled project with a webhook secret and return [project, token].
 *
 * T39: Also creates Users with matching gitlab_ids so that
 * TaskDispatchService::resolveUserId() can resolve webhook authors.
 */
function webhookProject(string $secret = 'test-secret'): array
{
    $project = Project::factory()->enabled()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $secret,
    ]);

    // T41: Create review.trigger permission and a developer role so that
    // @ai commands pass the permission check in WebhookController.
    $permission = Permission::firstOrCreate(
        ['name' => 'review.trigger'],
        ['description' => 'Can trigger on-demand review', 'group' => 'review'],
    );
    $role = Role::firstOrCreate(
        ['project_id' => $project->id, 'name' => 'developer'],
        ['description' => 'Test developer role', 'is_default' => true],
    );
    $role->permissions()->syncWithoutDetaching([$permission->id]);

    // Create users for all author_ids used in test payloads
    foreach ([3, 5, 7, 12] as $gitlabId) {
        if (! User::where('gitlab_id', $gitlabId)->exists()) {
            $user = User::factory()->create(['gitlab_id' => $gitlabId]);
            $user->assignRole($role, $project);
        }
    }

    return [$project, $secret];
}

/**
 * Helper: POST a webhook request with given payload and headers.
 */
function postWebhook(
    \Illuminate\Foundation\Testing\TestCase $test,
    string $token,
    string $gitlabEvent,
    array $payload = [],
    ?string $eventUuid = null,
): \Illuminate\Testing\TestResponse {
    $headers = [
        'X-Gitlab-Token' => $token,
        'X-Gitlab-Event' => $gitlabEvent,
    ];

    if ($eventUuid !== null) {
        $headers['X-Gitlab-Event-UUID'] = $eventUuid;
    }

    return $test->postJson('/webhook', $payload, $headers);
}

// ------------------------------------------------------------------
//  Event type detection
// ------------------------------------------------------------------

it('returns 400 when X-Gitlab-Event header is missing', function () {
    [$project, $token] = webhookProject();

    $this->postJson('/webhook', [], ['X-Gitlab-Token' => $token])
        ->assertStatus(400)
        ->assertJson([
            'status' => 'ignored',
            'reason' => 'Missing X-Gitlab-Event header.',
        ]);
});

it('returns 200 with ignored status for unsupported event types', function () {
    [$project, $token] = webhookProject();

    postWebhook($this, $token, 'Pipeline Hook', ['object_kind' => 'pipeline'])
        ->assertOk()
        ->assertJson([
            'status' => 'ignored',
            'reason' => 'Unsupported event type: Pipeline Hook',
        ]);
});

// ------------------------------------------------------------------
//  Merge Request events
// ------------------------------------------------------------------

it('accepts merge request hook events', function () {
    [$project, $token] = webhookProject();

    postWebhook($this, $token, 'Merge Request Hook', [
        'object_kind' => 'merge_request',
        'object_attributes' => [
            'iid' => 42,
            'action' => 'open',
            'source_branch' => 'feature/login',
            'target_branch' => 'main',
            'author_id' => 7,
            'last_commit' => ['id' => 'abc123def456'],
        ],
    ])->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'event_type' => 'merge_request',
            'project_id' => $project->id,
        ]);
});

it('accepts merge request update events', function () {
    [$project, $token] = webhookProject();

    postWebhook($this, $token, 'Merge Request Hook', [
        'object_kind' => 'merge_request',
        'object_attributes' => [
            'iid' => 42,
            'action' => 'update',
            'source_branch' => 'feature/login',
            'target_branch' => 'main',
            'author_id' => 7,
            'last_commit' => ['id' => 'def789ghi012'],
        ],
    ])->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'event_type' => 'merge_request',
        ]);
});

// ------------------------------------------------------------------
//  Note events (MR and Issue comments)
// ------------------------------------------------------------------

it('accepts note hook events on merge requests', function () {
    [$project, $token] = webhookProject();

    postWebhook($this, $token, 'Note Hook', [
        'object_kind' => 'note',
        'object_attributes' => [
            'note' => '@ai review',
            'noteable_type' => 'MergeRequest',
            'author_id' => 5,
        ],
        'merge_request' => [
            'iid' => 42,
        ],
    ])->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'event_type' => 'note',
        ]);
});

it('accepts note hook events on issues', function () {
    [$project, $token] = webhookProject();

    postWebhook($this, $token, 'Note Hook', [
        'object_kind' => 'note',
        'object_attributes' => [
            'note' => '@ai help with this issue',
            'noteable_type' => 'Issue',
            'author_id' => 3,
        ],
        'issue' => [
            'iid' => 17,
        ],
    ])->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'event_type' => 'note',
        ]);
});

// ------------------------------------------------------------------
//  Issue events
// ------------------------------------------------------------------

it('accepts issue hook events', function () {
    [$project, $token] = webhookProject();

    postWebhook($this, $token, 'Issue Hook', [
        'object_kind' => 'issue',
        'object_attributes' => [
            'iid' => 99,
            'action' => 'open',
            'author_id' => 12,
        ],
        'labels' => [
            ['title' => 'ai::develop'],
            ['title' => 'backend'],
        ],
    ])->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'event_type' => 'issue',
        ]);
});

// ------------------------------------------------------------------
//  Push events
// ------------------------------------------------------------------

it('accepts push hook events', function () {
    [$project, $token] = webhookProject();

    postWebhook($this, $token, 'Push Hook', [
        'object_kind' => 'push',
        'ref' => 'refs/heads/feature/login',
        'before' => '0000000000000000000000000000000000000000',
        'after' => 'abc123def456789',
        'user_id' => 7,
        'commits' => [
            ['id' => 'abc123', 'message' => 'Add login form'],
        ],
        'total_commits_count' => 1,
    ])->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'event_type' => 'push',
        ]);
});

// ------------------------------------------------------------------
//  Response structure
// ------------------------------------------------------------------

it('includes the project_id in accepted responses', function () {
    [$project, $token] = webhookProject();

    postWebhook($this, $token, 'Merge Request Hook', [
        'object_kind' => 'merge_request',
        'object_attributes' => [
            'iid' => 1,
            'action' => 'open',
            'author_id' => 7,
            'last_commit' => ['id' => 'resp-struct-commit'],
        ],
    ])->assertOk()
        ->assertJsonStructure([
            'status',
            'event_type',
            'project_id',
        ])
        ->assertJson([
            'project_id' => $project->id,
        ]);
});

// ------------------------------------------------------------------
//  T14: Deduplication via webhook endpoint
// ------------------------------------------------------------------

it('logs event UUID and returns superseded_count in response', function () {
    [$project, $token] = webhookProject();

    postWebhook($this, $token, 'Merge Request Hook', [
        'object_kind' => 'merge_request',
        'object_attributes' => [
            'iid' => 42,
            'action' => 'open',
            'source_branch' => 'feature/login',
            'target_branch' => 'main',
            'author_id' => 7,
            'last_commit' => ['id' => 'abc123def456'],
        ],
    ], eventUuid: 'e2d4f6a8-1234-5678-9abc-def012345678')
        ->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'event_type' => 'merge_request',
            'superseded_count' => 0,
        ]);

    // Verify event was logged
    expect(WebhookEventLog::where('gitlab_event_uuid', 'e2d4f6a8-1234-5678-9abc-def012345678')->exists())->toBeTrue();
});

it('rejects duplicate webhook by X-Gitlab-Event-UUID', function () {
    [$project, $token] = webhookProject();

    $payload = [
        'object_kind' => 'merge_request',
        'object_attributes' => [
            'iid' => 42,
            'action' => 'open',
            'source_branch' => 'feature/login',
            'target_branch' => 'main',
            'author_id' => 7,
            'last_commit' => ['id' => 'abc123def456'],
        ],
    ];

    $uuid = '00000000-0000-0000-0000-bbb000000001';

    // First request — accepted
    postWebhook($this, $token, 'Merge Request Hook', $payload, eventUuid: $uuid)
        ->assertOk()
        ->assertJson(['status' => 'accepted']);

    // Second request — duplicate
    postWebhook($this, $token, 'Merge Request Hook', $payload, eventUuid: $uuid)
        ->assertOk()
        ->assertJson([
            'status' => 'duplicate',
            'reason' => 'duplicate_uuid',
        ]);
});

// ------------------------------------------------------------------
//  T39: Task dispatch wiring
// ------------------------------------------------------------------

it('dispatches ProcessTask job and returns task_id for MR open events', function () {
    [$project, $token] = webhookProject();

    $response = postWebhook($this, $token, 'Merge Request Hook', [
        'object_kind' => 'merge_request',
        'object_attributes' => [
            'iid' => 42,
            'action' => 'open',
            'source_branch' => 'feature/login',
            'target_branch' => 'main',
            'author_id' => 7,
            'last_commit' => ['id' => 'abc123def456'],
        ],
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'intent' => 'auto_review',
        ])
        ->assertJsonStructure(['task_id']);

    // Task was created in DB
    $taskId = $response->json('task_id');
    expect($taskId)->not->toBeNull();
    $this->assertDatabaseHas('tasks', [
        'id' => $taskId,
        'project_id' => $project->id,
        'mr_iid' => 42,
        'commit_sha' => 'abc123def456',
    ]);

    // ProcessTask was dispatched
    Queue::assertPushed(\App\Jobs\ProcessTask::class, fn ($job) => $job->taskId === $taskId);
});

it('returns null task_id for non-dispatchable events', function () {
    [$project, $token] = webhookProject();

    // MR close action is not routable — EventRouter returns null,
    // so no task is dispatched. (MR merge now dispatches acceptance tracking via T86.)
    postWebhook($this, $token, 'Merge Request Hook', [
        'object_kind' => 'merge_request',
        'object_attributes' => [
            'iid' => 42,
            'action' => 'close',
            'source_branch' => 'feature/login',
            'target_branch' => 'main',
            'author_id' => 7,
            'last_commit' => ['id' => 'abc123def456'],
        ],
    ])->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'event_type' => 'merge_request',
        ]);

    Queue::assertNothingPushed();
});

it('accepts webhooks without X-Gitlab-Event-UUID header', function () {
    [$project, $token] = webhookProject();

    postWebhook($this, $token, 'Merge Request Hook', [
        'object_kind' => 'merge_request',
        'object_attributes' => [
            'iid' => 42,
            'action' => 'open',
            'source_branch' => 'feature/x',
            'target_branch' => 'main',
            'author_id' => 7,
            'last_commit' => ['id' => 'no-uuid-commit'],
        ],
    ])
        ->assertOk()
        ->assertJson(['status' => 'accepted']);
});

// ------------------------------------------------------------------
//  T41: Permission check for @ai commands
// ------------------------------------------------------------------

it('drops @ai review from user without review.trigger permission', function () {
    // Create project without granting review.trigger to the user
    $project = Project::factory()->enabled()->create();
    $token = 'perm-test-secret';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $token,
    ]);

    // Create user with gitlab_id 50 but NO review.trigger permission
    User::factory()->create(['gitlab_id' => 50]);

    postWebhook($this, $token, 'Note Hook', [
        'object_kind' => 'note',
        'object_attributes' => [
            'note' => '@ai review',
            'noteable_type' => 'MergeRequest',
            'author_id' => 50,
        ],
        'merge_request' => ['iid' => 42],
    ])->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'intent' => 'on_demand_review',
            'permission_denied' => true,
        ]);

    Queue::assertNothingPushed();
});

it('drops @ai review from unknown GitLab user (no Vunnix account)', function () {
    $project = Project::factory()->enabled()->create();
    $token = 'unknown-user-secret';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $token,
    ]);

    // gitlab_id 999 does not exist in users table
    postWebhook($this, $token, 'Note Hook', [
        'object_kind' => 'note',
        'object_attributes' => [
            'note' => '@ai review',
            'noteable_type' => 'MergeRequest',
            'author_id' => 999,
        ],
        'merge_request' => ['iid' => 42],
    ])->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'intent' => 'on_demand_review',
            'permission_denied' => true,
        ]);

    Queue::assertNothingPushed();
});

it('allows auto_review without review.trigger permission', function () {
    // Create project without granting review.trigger
    $project = Project::factory()->enabled()->create();
    $token = 'auto-review-perm-secret';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $token,
    ]);

    User::factory()->create(['gitlab_id' => 50]);

    // auto_review (MR open) does NOT require review.trigger
    postWebhook($this, $token, 'Merge Request Hook', [
        'object_kind' => 'merge_request',
        'object_attributes' => [
            'iid' => 42,
            'action' => 'open',
            'source_branch' => 'feature/test',
            'target_branch' => 'main',
            'author_id' => 50,
            'last_commit' => ['id' => 'auto-review-sha'],
        ],
    ])->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'intent' => 'auto_review',
        ])
        ->assertJsonMissing(['permission_denied' => true]);

    Queue::assertPushed(\App\Jobs\ProcessTask::class);
});

it('dispatches task for @ai review when user has review.trigger permission', function () {
    [$project, $token] = webhookProject();

    postWebhook($this, $token, 'Note Hook', [
        'object_kind' => 'note',
        'object_attributes' => [
            'note' => '@ai review',
            'noteable_type' => 'MergeRequest',
            'author_id' => 5,
        ],
        'merge_request' => ['iid' => 42],
    ])->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'intent' => 'on_demand_review',
        ])
        ->assertJsonMissing(['permission_denied' => true])
        ->assertJsonStructure(['task_id']);

    Queue::assertPushed(\App\Jobs\ProcessTask::class);
});

it('drops ai::develop label trigger from user without review.trigger', function () {
    $project = Project::factory()->enabled()->create();
    $token = 'label-perm-secret';
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => $token,
    ]);

    User::factory()->create(['gitlab_id' => 60]);

    postWebhook($this, $token, 'Issue Hook', [
        'object_kind' => 'issue',
        'object_attributes' => [
            'iid' => 99,
            'action' => 'update',
            'author_id' => 60,
        ],
        'labels' => [
            ['title' => 'ai::develop'],
        ],
    ])->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'intent' => 'feature_dev',
            'permission_denied' => true,
        ]);

    Queue::assertNothingPushed();
});

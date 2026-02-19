<?php

use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Models\Role;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withMiddleware();
    config(['security.force_csrf_validation_in_tests' => true]);
});

function attachProjectMembership(User $user, Project $project): void
{
    $project->users()->syncWithoutDetaching([
        $user->id => [
            'gitlab_access_level' => 30,
            'synced_at' => now(),
        ],
    ]);
}

function grantReviewTriggerPermission(User $user, Project $project): void
{
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'review-trigger']);
    $permission = Permission::firstOrCreate(
        ['name' => 'review.trigger'],
        ['description' => 'Can trigger code reviews', 'group' => 'review']
    );
    $role->permissions()->attach($permission);
    $user->assignRole($role, $project);
}

it('rejects session-authenticated state-changing api requests without csrf token', function (): void {
    Queue::fake();

    $user = User::factory()->create(['oauth_token' => null]);
    $project = Project::factory()->enabled()->create();
    attachProjectMembership($user, $project);
    grantReviewTriggerPermission($user, $project);

    $this->actingAs($user)
        ->postJson('/api/v1/conversations', [
            'project_id' => $project->id,
            'title' => 'CSRF Test',
        ])
        ->assertStatus(419);

    $this->actingAs($user)
        ->putJson('/api/v1/admin/settings', [
            'settings' => [['key' => 'ai_model', 'value' => 'sonnet', 'type' => 'string']],
        ])
        ->assertStatus(419);

    $this->actingAs($user)
        ->postJson('/api/v1/admin/roles', [
            'project_id' => $project->id,
            'name' => 'qa-role',
            'permissions' => [],
        ])
        ->assertStatus(419);

    $this->actingAs($user)
        ->postJson('/api/v1/api-keys', [
            'name' => 'integration-key',
        ])
        ->assertStatus(419);

    $this->actingAs($user)
        ->postJson('/api/v1/ext/tasks/review', [
            'project_id' => $project->id,
            'mr_iid' => 42,
        ])
        ->assertStatus(419);
});

it('accepts session-authenticated state-changing api requests with valid csrf token', function (): void {
    Queue::fake();

    $user = User::factory()->create(['oauth_token' => null]);
    $project = Project::factory()->enabled()->create();
    attachProjectMembership($user, $project);
    grantReviewTriggerPermission($user, $project);

    $csrfToken = 'csrf-token-123';

    $conversationResponse = $this->actingAs($user)
        ->withSession(['_token' => $csrfToken])
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/v1/conversations', [
            'project_id' => $project->id,
            'title' => 'CSRF Test',
        ]);
    expect($conversationResponse->status())->not->toBe(419);

    $settingsResponse = $this->actingAs($user)
        ->withSession(['_token' => $csrfToken])
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->putJson('/api/v1/admin/settings', [
            'settings' => [['key' => 'ai_model', 'value' => 'sonnet', 'type' => 'string']],
        ]);
    expect($settingsResponse->status())->not->toBe(419);

    $roleResponse = $this->actingAs($user)
        ->withSession(['_token' => $csrfToken])
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/v1/admin/roles', [
            'project_id' => $project->id,
            'name' => 'qa-role',
            'permissions' => [],
        ]);
    expect($roleResponse->status())->not->toBe(419);

    $apiKeyResponse = $this->actingAs($user)
        ->withSession(['_token' => $csrfToken])
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/v1/api-keys', [
            'name' => 'integration-key',
        ]);
    expect($apiKeyResponse->status())->toBe(201);

    $externalResponse = $this->actingAs($user)
        ->withSession(['_token' => $csrfToken])
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/v1/ext/tasks/review', [
            'project_id' => $project->id,
            'mr_iid' => 42,
        ]);
    expect($externalResponse->status())->toBe(201);
});

it('allows api-key authenticated requests without csrf token', function (): void {
    Queue::fake();

    $user = User::factory()->create(['oauth_token' => null]);
    $project = Project::factory()->enabled()->create();
    attachProjectMembership($user, $project);
    grantReviewTriggerPermission($user, $project);

    $apiKey = app(ApiKeyService::class)->generate($user, 'ext-key');
    $plaintext = $apiKey['plaintext'];

    $this->postJson(
        '/api/v1/ext/tasks/review',
        [
            'project_id' => $project->id,
            'mr_iid' => 101,
        ],
        ['Authorization' => "Bearer {$plaintext}"]
    )->assertCreated();
});

it('allows webhook requests without csrf token', function (): void {
    Queue::fake();

    $project = Project::factory()->enabled()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => 'csrf-bypass-token',
    ]);

    $this->post('/webhook', [
        'object_kind' => 'merge_request',
        'object_attributes' => ['iid' => 1, 'action' => 'open'],
    ], [
        'X-Gitlab-Token' => 'csrf-bypass-token',
        'X-Gitlab-Event' => 'Merge Request Hook',
        'Accept' => 'application/json',
    ])->assertOk();
});

it('keeps get and head requests unaffected by csrf checks', function (): void {
    $user = User::factory()->create(['oauth_token' => null]);

    $this->actingAs($user)
        ->getJson('/api/v1/user')
        ->assertOk();

    $this->actingAs($user)
        ->head('/api/v1/user')
        ->assertOk();
});

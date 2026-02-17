<?php

use App\Models\Project;
use App\Models\ProjectConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// T39: Fake the queue so ProcessTask jobs dispatched by TaskDispatchService
// don't run inline. This test file tests middleware behavior, not task dispatch.
beforeEach(function (): void {
    Queue::fake();
});

it('returns 401 when X-Gitlab-Token header is missing', function (): void {
    $this->postJson('/webhook')
        ->assertUnauthorized()
        ->assertJson(['error' => 'Missing webhook token.']);
});

it('returns 401 when X-Gitlab-Token header is empty', function (): void {
    $this->postJson('/webhook', [], ['X-Gitlab-Token' => ''])
        ->assertUnauthorized()
        ->assertJson(['error' => 'Missing webhook token.']);
});

it('returns 401 when X-Gitlab-Token does not match any project', function (): void {
    $project = Project::factory()->enabled()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => 'correct-secret',
    ]);

    $this->postJson('/webhook', [], ['X-Gitlab-Token' => 'wrong-secret'])
        ->assertUnauthorized()
        ->assertJson(['error' => 'Invalid webhook token.']);
});

it('passes when X-Gitlab-Token matches a project webhook secret', function (): void {
    $project = Project::factory()->enabled()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => 'valid-token-123',
    ]);

    // Send a valid MR webhook payload
    $this->postJson('/webhook', [
        'object_kind' => 'merge_request',
        'object_attributes' => ['iid' => 1, 'action' => 'open'],
    ], [
        'X-Gitlab-Token' => 'valid-token-123',
        'X-Gitlab-Event' => 'Merge Request Hook',
    ])->assertOk()
        ->assertJson(['status' => 'accepted']);
});

it('returns 403 when project is disabled', function (): void {
    $project = Project::factory()->create(['enabled' => false]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => 'valid-token',
    ]);

    $this->postJson('/webhook', [], ['X-Gitlab-Token' => 'valid-token'])
        ->assertForbidden()
        ->assertJson(['error' => 'Project not enabled.']);
});

it('skips configs where webhook_token_validation is disabled', function (): void {
    $project = Project::factory()->enabled()->create();
    ProjectConfig::factory()->withoutWebhookValidation()->create([
        'project_id' => $project->id,
        'webhook_secret' => 'valid-token',
    ]);

    // Token matches, but validation is disabled for this project — should not match
    $this->postJson('/webhook', [], ['X-Gitlab-Token' => 'valid-token'])
        ->assertUnauthorized()
        ->assertJson(['error' => 'Invalid webhook token.']);
});

it('matches the correct project when multiple projects have webhooks', function (): void {
    $projectA = Project::factory()->enabled()->create(['name' => 'Project A']);
    $projectB = Project::factory()->enabled()->create(['name' => 'Project B']);

    ProjectConfig::factory()->create([
        'project_id' => $projectA->id,
        'webhook_secret' => 'token-for-a',
    ]);
    ProjectConfig::factory()->create([
        'project_id' => $projectB->id,
        'webhook_secret' => 'token-for-b',
    ]);

    // Token for project B should resolve to project B
    $this->postJson('/webhook', [
        'object_kind' => 'merge_request',
        'object_attributes' => ['iid' => 5, 'action' => 'open'],
    ], [
        'X-Gitlab-Token' => 'token-for-b',
        'X-Gitlab-Event' => 'Merge Request Hook',
    ])->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'project_id' => $projectB->id,
        ]);
});

it('is not blocked by CSRF protection', function (): void {
    // Webhook requests come from GitLab, not a browser session — CSRF must be excluded.
    // This test ensures the CSRF exclusion in bootstrap/app.php is working.
    $project = Project::factory()->enabled()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'webhook_secret' => 'csrf-test-token',
    ]);

    // POST without any CSRF token — should still succeed
    $this->post('/webhook', [
        'object_kind' => 'merge_request',
        'object_attributes' => ['iid' => 1, 'action' => 'open'],
    ], [
        'X-Gitlab-Token' => 'csrf-test-token',
        'X-Gitlab-Event' => 'Merge Request Hook',
        'Accept' => 'application/json',
    ])->assertOk();
});

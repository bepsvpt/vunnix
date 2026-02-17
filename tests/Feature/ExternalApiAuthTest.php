<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['enabled' => true]);
    $this->project->users()->attach($this->user->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    $this->service = app(ApiKeyService::class);
    $this->apiKeyResult = $this->service->generate($this->user, 'CI Key');
});

// ─── API Key auth ─────────────────────────────────────────────

it('accesses GET /api/v1/ext/tasks via API key', function (): void {
    Task::factory()->create(['project_id' => $this->project->id]);

    $this->getJson('/api/v1/ext/tasks', [
        'Authorization' => 'Bearer '.$this->apiKeyResult['plaintext'],
    ])->assertOk();
});

it('accesses GET /api/v1/ext/projects via API key', function (): void {
    $this->getJson('/api/v1/ext/projects', [
        'Authorization' => 'Bearer '.$this->apiKeyResult['plaintext'],
    ])->assertOk();
});

it('accesses GET /api/v1/ext/metrics/summary via API key', function (): void {
    $this->getJson('/api/v1/ext/metrics/summary', [
        'Authorization' => 'Bearer '.$this->apiKeyResult['plaintext'],
    ])->assertOk();
});

it('accesses GET /api/v1/ext/activity via API key', function (): void {
    $this->getJson('/api/v1/ext/activity', [
        'Authorization' => 'Bearer '.$this->apiKeyResult['plaintext'],
    ])->assertOk();
});

// ─── Session auth ─────────────────────────────────────────────

it('accesses GET /api/v1/ext/tasks via session auth', function (): void {
    Task::factory()->create(['project_id' => $this->project->id]);

    $this->actingAs($this->user)
        ->getJson('/api/v1/ext/tasks')
        ->assertOk();
});

it('accesses GET /api/v1/ext/projects via session auth', function (): void {
    $this->actingAs($this->user)
        ->getJson('/api/v1/ext/projects')
        ->assertOk();
});

// ─── No auth ──────────────────────────────────────────────────

it('returns 401 without any auth on ext/tasks', function (): void {
    $this->getJson('/api/v1/ext/tasks')
        ->assertStatus(401);
});

it('returns 401 without any auth on ext/projects', function (): void {
    $this->getJson('/api/v1/ext/projects')
        ->assertStatus(401);
});

// ─── Invalid key ──────────────────────────────────────────────

it('returns 401 with invalid API key', function (): void {
    $this->getJson('/api/v1/ext/tasks', [
        'Authorization' => 'Bearer invalid-token-here',
    ])->assertStatus(401);
});

// ─── Rate limiting ────────────────────────────────────────────

it('rate limits API key requests on external routes', function (): void {
    $headers = ['Authorization' => 'Bearer '.$this->apiKeyResult['plaintext']];

    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/v1/ext/projects', $headers);
    }

    $this->getJson('/api/v1/ext/projects', $headers)->assertStatus(429);
});

<?php

use App\Models\Project;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();

    $this->service = app(ApiKeyService::class);
    $this->apiKeyResult = $this->service->generate($this->user, 'Test Key');
    $this->headers = ['Authorization' => 'Bearer '.$this->apiKeyResult['plaintext']];
});

// ─── Authentication ──────────────────────────────────────────

it('returns 401 for unauthenticated request to external projects', function (): void {
    $this->getJson('/api/v1/ext/projects')
        ->assertUnauthorized();
});

it('returns 401 from controller guard when middleware is bypassed', function (): void {
    $this->withoutMiddleware()
        ->getJson('/api/v1/ext/projects')
        ->assertUnauthorized();
});

// ─── Authenticated — with enabled projects ───────────────────

it('returns enabled projects for authenticated user', function (): void {
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($this->user->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/ext/projects');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $project->id);
});

// ─── Authenticated — no projects ─────────────────────────────

it('returns empty data array when user has no projects', function (): void {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/ext/projects');

    $response->assertOk()
        ->assertJsonPath('data', []);
});

// ─── Disabled projects excluded ──────────────────────────────

it('excludes disabled projects from external project list', function (): void {
    $enabledProject = Project::factory()->enabled()->create();
    $enabledProject->users()->attach($this->user->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    $disabledProject = Project::factory()->create(['enabled' => false]);
    $disabledProject->users()->attach($this->user->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/ext/projects');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $enabledProject->id);
});

// ─── API key auth ────────────────────────────────────────────

it('returns enabled projects via API key auth', function (): void {
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($this->user->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/ext/projects', $this->headers);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $project->id);
});

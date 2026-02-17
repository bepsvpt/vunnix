<?php

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectAccessChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->checker = new ProjectAccessChecker;
    $this->user = User::factory()->create();
    $this->project = Project::factory()->enabled()->create();
});

// ─── Access granted ────────────────────────────────────────────

it('returns null when user has access to the project', function (): void {
    $this->user->projects()->attach($this->project->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    $result = $this->checker->check($this->project->gitlab_project_id, $this->user);

    expect($result)->toBeNull();
});

// ─── Access denied — no membership ─────────────────────────────

it('returns rejection when user is not a member of the project', function (): void {
    // User exists but is not attached to the project
    $result = $this->checker->check($this->project->gitlab_project_id, $this->user);

    expect($result)
        ->toBeString()
        ->toContain('Access denied')
        ->toContain('do not have access');
});

// ─── Access denied — null user ─────────────────────────────────

it('returns rejection when user is null', function (): void {
    $result = $this->checker->check($this->project->gitlab_project_id, null);

    expect($result)
        ->toBeString()
        ->toContain('Access denied')
        ->toContain('no authenticated user');
});

// ─── Access denied — unregistered project ──────────────────────

it('returns rejection when gitlab project ID is not registered in Vunnix', function (): void {
    $result = $this->checker->check(999999, $this->user);

    expect($result)
        ->toBeString()
        ->toContain('Access denied')
        ->toContain('not registered');
});

// ─── Access denied — disabled project ──────────────────────────

it('returns rejection when project is disabled', function (): void {
    $disabledProject = Project::factory()->create(['enabled' => false]);
    $this->user->projects()->attach($disabledProject->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    $result = $this->checker->check($disabledProject->gitlab_project_id, $this->user);

    expect($result)
        ->toBeString()
        ->toContain('Access denied')
        ->toContain('not enabled');
});

// ─── Cross-project isolation ───────────────────────────────────

it('allows access to project A but denies access to project B', function (): void {
    $projectA = $this->project;
    $projectB = Project::factory()->enabled()->create();

    // User is member of A only
    $this->user->projects()->attach($projectA->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    $resultA = $this->checker->check($projectA->gitlab_project_id, $this->user);
    $resultB = $this->checker->check($projectB->gitlab_project_id, $this->user);

    expect($resultA)->toBeNull();
    expect($resultB)->toBeString()->toContain('Access denied');
});

// ─── No data leakage ──────────────────────────────────────────

it('does not reveal project names or internal IDs in rejection messages', function (): void {
    $project = Project::factory()->enabled()->create(['name' => 'SecretProject']);

    $result = $this->checker->check($project->gitlab_project_id, $this->user);

    expect($result)
        ->not->toContain('SecretProject')
        ->not->toContain((string) $project->id);
});

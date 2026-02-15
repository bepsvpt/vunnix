<?php

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createPromptVersionTestUser(): array
{
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    return [$user, $project];
}

it('returns distinct prompt version skills from completed tasks', function () {
    [$user, $project] = createPromptVersionTestUser();

    Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'frontend-review:1.0', 'claude_md' => 'executor:1.0', 'schema' => 'review:1.0'],
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'frontend-review:1.1', 'claude_md' => 'executor:1.0', 'schema' => 'review:1.0'],
    ]);
    // Duplicate — should not appear twice
    Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'frontend-review:1.0', 'claude_md' => 'executor:1.0', 'schema' => 'review:1.0'],
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/prompt-versions');

    $response->assertOk()
        ->assertJsonStructure(['data'])
        ->assertJsonCount(2, 'data');

    $skills = collect($response->json('data'))->pluck('skill')->all();
    expect($skills)->toContain('frontend-review:1.0')
        ->toContain('frontend-review:1.1');
});

it('scopes prompt versions to user-accessible projects', function () {
    [$user, $project] = createPromptVersionTestUser();

    // Task on accessible project
    Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'backend-review:1.0', 'claude_md' => 'executor:1.0', 'schema' => 'review:1.0'],
    ]);

    // Task on inaccessible project
    $otherProject = Project::factory()->enabled()->create();
    Task::factory()->create([
        'project_id' => $otherProject->id,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'security-audit:2.0', 'claude_md' => 'executor:1.0', 'schema' => 'review:1.0'],
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/prompt-versions');

    $response->assertOk()
        ->assertJsonCount(1, 'data');

    $skills = collect($response->json('data'))->pluck('skill')->all();
    expect($skills)->toContain('backend-review:1.0')
        ->not->toContain('security-audit:2.0');
});

it('excludes tasks with null prompt_version', function () {
    [$user, $project] = createPromptVersionTestUser();

    Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Completed,
        'prompt_version' => null,
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'mixed-review:1.0', 'claude_md' => 'executor:1.0', 'schema' => 'review:1.0'],
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/prompt-versions');

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('requires authentication', function () {
    $this->getJson('/api/v1/prompt-versions')
        ->assertStatus(401);
});

<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createQualityPromptVersionUser(): array
{
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'quality-viewer']);
    $permission = Permission::firstOrCreate(
        ['name' => 'review.view'],
        ['description' => 'Can view review results', 'group' => 'review']
    );
    $role->permissions()->attach($permission);
    $user->assignRole($role, $project);

    return [$user, $project];
}

it('filters quality metrics by prompt_version', function (): void {
    [$user, $project] = createQualityPromptVersionUser();

    // v1.0 review: 1 critical, 1 major
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'frontend-review:1.0', 'claude_md' => 'executor:1.0', 'schema' => 'review:1.0'],
        'result' => [
            'summary' => [
                'total_findings' => 2,
                'findings_by_severity' => ['critical' => 1, 'major' => 1, 'minor' => 0],
            ],
        ],
    ]);

    // v1.1 review: 0 critical, 0 major, 3 minor
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'frontend-review:1.1', 'claude_md' => 'executor:1.0', 'schema' => 'review:1.0'],
        'result' => [
            'summary' => [
                'total_findings' => 3,
                'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 3],
            ],
        ],
    ]);

    // Filter to v1.0 only
    $response = $this->actingAs($user)
        ->getJson('/api/v1/dashboard/quality?prompt_version=frontend-review:1.0');

    $response->assertOk()
        ->assertJsonPath('data.total_reviews', 1)
        ->assertJsonPath('data.total_findings', 2)
        ->assertJsonPath('data.severity_distribution.critical', 1)
        ->assertJsonPath('data.severity_distribution.major', 1)
        ->assertJsonPath('data.severity_distribution.minor', 0);
});

it('returns all reviews when no prompt_version filter is set', function (): void {
    [$user, $project] = createQualityPromptVersionUser();

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'frontend-review:1.0', 'claude_md' => 'executor:1.0', 'schema' => 'review:1.0'],
        'result' => [
            'summary' => [
                'total_findings' => 2,
                'findings_by_severity' => ['critical' => 1, 'major' => 1, 'minor' => 0],
            ],
        ],
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'frontend-review:1.1', 'claude_md' => 'executor:1.0', 'schema' => 'review:1.0'],
        'result' => [
            'summary' => [
                'total_findings' => 3,
                'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 3],
            ],
        ],
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/dashboard/quality');

    $response->assertOk()
        ->assertJsonPath('data.total_reviews', 2)
        ->assertJsonPath('data.total_findings', 5);
});

<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns quality stats scoped to user projects', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $otherProject = Project::factory()->enabled()->create();

    // Completed code review with result data in user's project
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'result' => [
            'summary' => [
                'total_findings' => 5,
                'findings_by_severity' => ['critical' => 1, 'major' => 2, 'minor' => 2],
            ],
        ],
    ]);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'result' => [
            'summary' => [
                'total_findings' => 3,
                'findings_by_severity' => ['critical' => 0, 'major' => 1, 'minor' => 2],
            ],
        ],
    ]);

    // Task in other project — should NOT appear
    Task::factory()->create([
        'project_id' => $otherProject->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'result' => [
            'summary' => [
                'total_findings' => 10,
                'findings_by_severity' => ['critical' => 5, 'major' => 3, 'minor' => 2],
            ],
        ],
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/quality');

    $response->assertOk();
    $response->assertJsonPath('data.total_reviews', 2);
    $response->assertJsonPath('data.total_findings', 8);
    $response->assertJsonPath('data.severity_distribution.critical', 1);
    $response->assertJsonPath('data.severity_distribution.major', 3);
    $response->assertJsonPath('data.severity_distribution.minor', 4);
    $response->assertJsonPath('data.avg_findings_per_review', 4);
});

it('returns correct response structure', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/quality');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'acceptance_rate',
            'severity_distribution' => ['critical', 'major', 'minor'],
            'total_findings',
            'total_reviews',
            'avg_findings_per_review',
        ],
    ]);
});

it('returns null acceptance rate (not yet tracked)', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/quality');

    $response->assertOk();
    $response->assertJsonPath('data.acceptance_rate', null);
});

it('returns null avg findings when no reviews exist', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/quality');

    $response->assertOk();
    $response->assertJsonPath('data.total_reviews', 0);
    $response->assertJsonPath('data.avg_findings_per_review', null);
});

it('returns 401 for unauthenticated users', function (): void {
    $response = $this->getJson('/api/v1/dashboard/quality');
    $response->assertUnauthorized();
});

it('excludes tasks from disabled projects', function (): void {
    $user = User::factory()->create();
    $disabledProject = Project::factory()->create(['enabled' => false]);
    $disabledProject->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->create([
        'project_id' => $disabledProject->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'result' => [
            'summary' => [
                'total_findings' => 5,
                'findings_by_severity' => ['critical' => 1, 'major' => 2, 'minor' => 2],
            ],
        ],
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/quality');

    $response->assertOk();
    $response->assertJsonPath('data.total_reviews', 0);
    $response->assertJsonPath('data.total_findings', 0);
});

it('excludes non-code-review tasks from quality metrics', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    // Feature dev task — should NOT be counted
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Completed,
        'result' => ['summary' => ['total_findings' => 0]],
    ]);

    // Incomplete code review — should NOT be counted
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Failed,
        'result' => null,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/quality');

    $response->assertOk();
    $response->assertJsonPath('data.total_reviews', 0);
    $response->assertJsonPath('data.total_findings', 0);
});

it('handles reviews with zero findings', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'result' => [
            'summary' => [
                'total_findings' => 0,
                'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 0],
            ],
        ],
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/quality');

    $response->assertOk();
    $response->assertJsonPath('data.total_reviews', 1);
    $response->assertJsonPath('data.total_findings', 0);
    $response->assertJsonPath('data.avg_findings_per_review', 0);
    $response->assertJsonPath('data.severity_distribution.critical', 0);
    $response->assertJsonPath('data.severity_distribution.major', 0);
    $response->assertJsonPath('data.severity_distribution.minor', 0);
});

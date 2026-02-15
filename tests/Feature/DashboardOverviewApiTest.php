<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns overview stats scoped to user projects', function () {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $otherProject = Project::factory()->enabled()->create();

    // Tasks in user's project
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Failed,
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Running,
    ]);

    // Task in other project — should NOT appear
    Task::factory()->create([
        'project_id' => $otherProject->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/overview');

    $response->assertOk();
    $response->assertJsonPath('data.tasks_by_type.code_review', 2);
    $response->assertJsonPath('data.tasks_by_type.feature_dev', 1);
    $response->assertJsonPath('data.tasks_by_type.ui_adjustment', 0);
    $response->assertJsonPath('data.tasks_by_type.prd_creation', 0);
    $response->assertJsonPath('data.active_tasks', 1);
    $response->assertJsonPath('data.total_completed', 1);
    $response->assertJsonPath('data.total_failed', 1);
    $response->assertJsonPath('data.success_rate', 50);
});

it('returns correct response structure', function () {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/overview');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'tasks_by_type' => ['code_review', 'feature_dev', 'ui_adjustment', 'prd_creation'],
            'active_tasks',
            'success_rate',
            'total_completed',
            'total_failed',
            'recent_activity',
        ],
    ]);
});

it('returns null success rate when no completed or failed tasks', function () {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/overview');

    $response->assertOk();
    $response->assertJsonPath('data.success_rate', null);
    $response->assertJsonPath('data.active_tasks', 0);
});

it('returns 401 for unauthenticated users', function () {
    $response = $this->getJson('/api/v1/dashboard/overview');
    $response->assertUnauthorized();
});

it('excludes tasks from disabled projects', function () {
    $user = User::factory()->create();
    $disabledProject = Project::factory()->create(['enabled' => false]);
    $disabledProject->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->create([
        'project_id' => $disabledProject->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/overview');

    $response->assertOk();
    $response->assertJsonPath('data.tasks_by_type.code_review', 0);
    $response->assertJsonPath('data.active_tasks', 0);
    $response->assertJsonPath('data.total_completed', 0);
});

it('includes queued and running tasks in active count', function () {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Queued,
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Running,
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::UiAdjustment,
        'status' => TaskStatus::Completed,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/overview');

    $response->assertOk();
    $response->assertJsonPath('data.active_tasks', 2);
});

it('returns recent activity timestamp', function () {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'created_at' => '2026-02-15 10:30:00',
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/overview');

    $response->assertOk();
    $response->assertJsonPath('data.recent_activity', fn ($val) => $val !== null);
});

<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns activity feed scoped to user projects', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $otherProject = Project::factory()->enabled()->create();

    // Task in user's project — should appear
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
    ]);

    // Task in other project — should NOT appear
    Task::factory()->create([
        'project_id' => $otherProject->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/activity');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.task_id', $task->id);
    $response->assertJsonPath('data.0.type', 'code_review');
    $response->assertJsonPath('data.0.project_name', $project->name);
});

it('filters activity by type', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Completed,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/activity?type=code_review');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.type', 'code_review');
});

it('returns cursor pagination metadata', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->count(3)->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/activity?per_page=2');

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
    $response->assertJsonStructure(['data', 'meta' => ['per_page', 'next_cursor'], 'links']);
});

it('returns 401 for unauthenticated users', function (): void {
    $response = $this->getJson('/api/v1/activity');
    $response->assertUnauthorized();
});

it('returns activity items with correct structure', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Completed,
        'mr_iid' => 42,
        'result' => ['title' => 'Add payment integration'],
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/activity');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            [
                'task_id',
                'type',
                'status',
                'project_id',
                'project_name',
                'summary',
                'user_name',
                'mr_iid',
                'conversation_id',
                'created_at',
            ],
        ],
    ]);
    $response->assertJsonPath('data.0.summary', 'Add payment integration');
    $response->assertJsonPath('data.0.mr_iid', 42);
});

it('excludes tasks from disabled projects', function (): void {
    $user = User::factory()->create();
    $disabledProject = Project::factory()->create(['enabled' => false]);
    $disabledProject->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->create([
        'project_id' => $disabledProject->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/activity');

    $response->assertOk();
    $response->assertJsonCount(0, 'data');
});

it('orders activity by most recent first', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $older = Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'created_at' => now()->subHour(),
    ]);
    $newer = Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Running,
        'created_at' => now(),
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/activity');

    $response->assertOk();
    $response->assertJsonPath('data.0.task_id', $newer->id);
    $response->assertJsonPath('data.1.task_id', $older->id);
});

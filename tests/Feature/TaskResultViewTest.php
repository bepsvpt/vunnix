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

function grantReviewViewPermission(User $user, Project $project): void
{
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'review-viewer']);
    $permission = Permission::firstOrCreate(
        ['name' => 'review.view'],
        ['description' => 'Can view review results', 'group' => 'review']
    );
    $role->permissions()->attach($permission);
    $user->assignRole($role, $project);
}

it('returns task result data for authorized user', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    grantReviewViewPermission($user, $project);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Completed,
        'mr_iid' => 123,
        'result' => [
            'branch' => 'ai/test',
            'mr_title' => 'Test MR',
            'mr_description' => 'Description',
            'files_changed' => [
                ['path' => 'foo.php', 'action' => 'created', 'summary' => 'New file'],
            ],
            'tests_added' => true,
            'notes' => 'Notes here',
        ],
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/tasks/{$task->id}/view");

    $response->assertOk();
    $response->assertJsonPath('data.task_id', $task->id);
    $response->assertJsonPath('data.status', 'completed');
    $response->assertJsonPath('data.type', 'feature_dev');
    $response->assertJsonPath('data.mr_iid', 123);
    $response->assertJsonPath('data.result.branch', 'ai/test');
});

it('returns 403 for user without project access', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Completed,
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/tasks/{$task->id}/view");

    $response->assertForbidden();
});

it('returns 401 for unauthenticated request', function (): void {
    $task = Task::factory()->create();

    $response = $this->getJson("/api/v1/tasks/{$task->id}/view");

    $response->assertUnauthorized();
});

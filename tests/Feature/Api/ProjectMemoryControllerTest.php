<?php

use App\Models\MemoryEntry;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['vunnix.memory.enabled' => true]);
});

function adminUserForProject(Project $project): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['project_id' => $project->id]);
    $perm = Permission::firstOrCreate(['name' => 'admin.global_config'], ['description' => 'Admin access']);
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);
    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 40,
        'synced_at' => now(),
    ]);

    return $user;
}

it('lists memory entries with filters', function (): void {
    $project = Project::factory()->create();
    $user = adminUserForProject($project);

    MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'review_pattern',
    ]);
    MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'conversation_fact',
    ]);
    MemoryEntry::factory()->create([
        'project_id' => Project::factory()->create()->id,
        'type' => 'review_pattern',
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/projects/{$project->id}/memory?type=review_pattern")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters memory entries by category', function (): void {
    $project = Project::factory()->create();
    $user = adminUserForProject($project);

    MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'review_pattern',
        'category' => 'false_positive',
    ]);
    MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'review_pattern',
        'category' => 'severity_calibration',
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/projects/{$project->id}/memory?category=false_positive")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.category', 'false_positive');
});

it('returns memory stats', function (): void {
    $project = Project::factory()->create();
    $user = adminUserForProject($project);

    MemoryEntry::factory()->create(['project_id' => $project->id, 'type' => 'review_pattern', 'confidence' => 80]);
    MemoryEntry::factory()->create(['project_id' => $project->id, 'type' => 'conversation_fact', 'confidence' => 60]);

    $this->actingAs($user)
        ->getJson("/api/v1/projects/{$project->id}/memory/stats")
        ->assertOk()
        ->assertJsonPath('data.total_entries', 2)
        ->assertJsonPath('data.by_type.review_pattern', 1);
});

it('archives an entry via destroy endpoint', function (): void {
    $project = Project::factory()->create();
    $user = adminUserForProject($project);
    $entry = MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'archived_at' => null,
    ]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/projects/{$project->id}/memory/{$entry->id}")
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($entry->fresh()?->archived_at)->not->toBeNull();
});

it('returns not found when deleting an entry from another project', function (): void {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = adminUserForProject($project);

    $entry = MemoryEntry::factory()->create([
        'project_id' => $otherProject->id,
        'archived_at' => null,
    ]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/projects/{$project->id}/memory/{$entry->id}")
        ->assertNotFound();
});

it('rejects non-admin access', function (): void {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $user->projects()->attach($project->id, ['gitlab_access_level' => 20, 'synced_at' => now()]);

    $this->actingAs($user)
        ->getJson("/api/v1/projects/{$project->id}/memory")
        ->assertForbidden();
});

it('rejects unauthenticated access', function (): void {
    $project = Project::factory()->create();

    $this->getJson("/api/v1/projects/{$project->id}/memory")
        ->assertUnauthorized();
});

<?php

use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function attachMembership(User $user, Project $project): void
{
    $project->users()->syncWithoutDetaching([
        $user->id => [
            'gitlab_access_level' => 40,
            'synced_at' => now(),
        ],
    ]);
}

function grantProjectPermission(User $user, Project $project, string $permission): void
{
    attachMembership($user, $project);

    $group = explode('.', $permission)[0] ?? 'misc';
    $perm = Permission::firstOrCreate(
        ['name' => $permission],
        ['description' => $permission, 'group' => $group],
    );

    $role = Role::factory()->create([
        'project_id' => $project->id,
        'name' => Str::slug($permission).'-'.Str::lower(Str::random(6)),
    ]);
    $role->permissions()->syncWithoutDetaching([$perm->id]);

    $user->assignRole($role, $project);
}

it('denies project admin from configuring another project', function (): void {
    $projectA = Project::factory()->enabled()->create();
    $projectB = Project::factory()->enabled()->create();
    $user = User::factory()->create();

    grantProjectPermission($user, $projectA, 'admin.global_config');

    $this->actingAs($user)
        ->getJson("/api/v1/admin/projects/{$projectB->id}/config")
        ->assertForbidden();
});

it('allows project admin to configure their own project', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = User::factory()->create();

    grantProjectPermission($user, $project, 'admin.global_config');

    $this->actingAs($user)
        ->getJson("/api/v1/admin/projects/{$project->id}/config")
        ->assertOk();
});

it('denies single-project admin from global admin endpoints', function (): void {
    $projectA = Project::factory()->enabled()->create();
    Project::factory()->enabled()->create();
    $user = User::factory()->create();

    grantProjectPermission($user, $projectA, 'admin.global_config');

    $this->actingAs($user)->getJson('/api/v1/admin/settings')->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/audit-logs')->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/admin/dead-letter')->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/dashboard/cost')->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/admin/projects')->assertForbidden();
});

it('allows global admin when user has admin on all enabled projects', function (): void {
    $projectA = Project::factory()->enabled()->create();
    $projectB = Project::factory()->enabled()->create();
    $user = User::factory()->create();

    grantProjectPermission($user, $projectA, 'admin.global_config');
    grantProjectPermission($user, $projectB, 'admin.global_config');

    $this->actingAs($user)->getJson('/api/v1/admin/settings')->assertOk();
    $this->actingAs($user)->getJson('/api/v1/audit-logs')->assertOk();
    $this->actingAs($user)->getJson('/api/v1/admin/dead-letter')->assertOk();
    $this->actingAs($user)->getJson('/api/v1/dashboard/cost')->assertOk();
    $this->actingAs($user)->getJson('/api/v1/admin/projects')->assertOk();
});

it('denies role admin on project A from managing project B roles', function (): void {
    $projectA = Project::factory()->enabled()->create();
    $projectB = Project::factory()->enabled()->create();
    $user = User::factory()->create();

    grantProjectPermission($user, $projectA, 'admin.roles');

    $roleB = Role::factory()->create([
        'project_id' => $projectB->id,
        'name' => 'project-b-role',
    ]);

    $targetUser = User::factory()->create();
    attachMembership($targetUser, $projectB);

    $this->actingAs($user)
        ->postJson('/api/v1/admin/roles', [
            'project_id' => $projectB->id,
            'name' => 'unauthorized-role',
            'permissions' => [],
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->postJson('/api/v1/admin/role-assignments', [
            'user_id' => $targetUser->id,
            'role_id' => $roleB->id,
            'project_id' => $projectB->id,
        ])
        ->assertForbidden();
});

<?php

use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function grantGlobalConfigAdmin(User $user, Project $project): void
{
    $project->users()->syncWithoutDetaching([$user->id => ['gitlab_access_level' => 30, 'synced_at' => now()]]);

    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'admin']);
    $permission = Permission::firstOrCreate(
        ['name' => 'admin.global_config'],
        ['description' => 'Can edit global settings', 'group' => 'admin']
    );
    $role->permissions()->attach($permission);

    $user->assignRole($role, $project);
}

it('returns false when no enabled projects exist', function (): void {
    $user = User::factory()->create();
    Project::factory()->create(['enabled' => false]);

    expect($user->isGlobalAdmin())->toBeFalse();
});

it('returns true when user has admin.global_config on all enabled projects', function (): void {
    $user = User::factory()->create();
    $projectA = Project::factory()->enabled()->create();
    $projectB = Project::factory()->enabled()->create();

    grantGlobalConfigAdmin($user, $projectA);
    grantGlobalConfigAdmin($user, $projectB);

    expect($user->isGlobalAdmin())->toBeTrue();
});

it('returns false when user lacks admin.global_config on one enabled project', function (): void {
    $user = User::factory()->create();
    $projectA = Project::factory()->enabled()->create();
    Project::factory()->enabled()->create();

    grantGlobalConfigAdmin($user, $projectA);

    expect($user->isGlobalAdmin())->toBeFalse();
});

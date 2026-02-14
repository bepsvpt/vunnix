<?php

use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Role model ──────────────────────────────────────────────

it('belongs to a project', function () {
    $project = Project::factory()->create();
    $role = Role::factory()->create(['project_id' => $project->id]);

    expect($role->project->id)->toBe($project->id);
});

it('can have permissions assigned', function () {
    $role = Role::factory()->create();
    $permission = Permission::factory()->create(['name' => 'chat.access']);

    $role->permissions()->attach($permission);

    expect($role->permissions)->toHaveCount(1)
        ->and($role->permissions->first()->name)->toBe('chat.access');
});

it('checks if it has a specific permission', function () {
    $role = Role::factory()->create();
    $perm1 = Permission::factory()->create(['name' => 'review.view']);
    $perm2 = Permission::factory()->create(['name' => 'admin.roles']);

    $role->permissions()->attach($perm1);

    expect($role->hasPermission('review.view'))->toBeTrue()
        ->and($role->hasPermission('admin.roles'))->toBeFalse();
});

it('can be marked as default', function () {
    $role = Role::factory()->default()->create();

    expect($role->is_default)->toBeTrue();
});

it('enforces unique name per project', function () {
    $project = Project::factory()->create();
    Role::factory()->create(['project_id' => $project->id, 'name' => 'admin']);

    Role::factory()->create(['project_id' => $project->id, 'name' => 'admin']);
})->throws(\Illuminate\Database\UniqueConstraintViolationException::class);

it('allows same role name on different projects', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();

    $roleA = Role::factory()->create(['project_id' => $projectA->id, 'name' => 'admin']);
    $roleB = Role::factory()->create(['project_id' => $projectB->id, 'name' => 'admin']);

    expect($roleA->id)->not->toBe($roleB->id);
});

// ── Permission model ────────────────────────────────────────

it('has a unique name', function () {
    Permission::factory()->create(['name' => 'chat.access']);

    Permission::factory()->create(['name' => 'chat.access']);
})->throws(\Illuminate\Database\UniqueConstraintViolationException::class);

it('can belong to multiple roles', function () {
    $permission = Permission::factory()->create(['name' => 'review.view']);
    $roleA = Role::factory()->create();
    $roleB = Role::factory()->create();

    $roleA->permissions()->attach($permission);
    $roleB->permissions()->attach($permission);

    expect($permission->roles)->toHaveCount(2);
});

// ── Project roles ───────────────────────────────────────────

it('project has many roles', function () {
    $project = Project::factory()->create();
    Role::factory()->count(3)->create(['project_id' => $project->id]);

    expect($project->roles)->toHaveCount(3);
});

it('project returns its default role', function () {
    $project = Project::factory()->create();
    Role::factory()->create(['project_id' => $project->id, 'name' => 'viewer']);
    $defaultRole = Role::factory()->default()->create(['project_id' => $project->id, 'name' => 'developer']);

    expect($project->defaultRole()->id)->toBe($defaultRole->id);
});

it('project returns null when no default role exists', function () {
    $project = Project::factory()->create();
    Role::factory()->create(['project_id' => $project->id, 'is_default' => false]);

    expect($project->defaultRole())->toBeNull();
});

// ── User RBAC methods ───────────────────────────────────────

it('can be assigned a role on a project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'developer']);

    $user->assignRole($role, $project);

    expect($user->roles)->toHaveCount(1)
        ->and($user->hasRole('developer', $project))->toBeTrue();
});

it('tracks who assigned a role', function () {
    $admin = User::factory()->create();
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $role = Role::factory()->create(['project_id' => $project->id]);

    $user->assignRole($role, $project, $admin);

    $pivot = $user->roles()->first()->pivot;
    expect($pivot->assigned_by)->toBe($admin->id);
});

it('can have different roles on different projects', function () {
    $user = User::factory()->create();
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $adminRole = Role::factory()->create(['project_id' => $projectA->id, 'name' => 'admin']);
    $viewerRole = Role::factory()->create(['project_id' => $projectB->id, 'name' => 'viewer']);

    $user->assignRole($adminRole, $projectA);
    $user->assignRole($viewerRole, $projectB);

    expect($user->hasRole('admin', $projectA))->toBeTrue()
        ->and($user->hasRole('viewer', $projectB))->toBeTrue()
        ->and($user->hasRole('admin', $projectB))->toBeFalse()
        ->and($user->hasRole('viewer', $projectA))->toBeFalse();
});

it('checks permission through roles on a project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $role = Role::factory()->create(['project_id' => $project->id]);
    $perm = Permission::factory()->create(['name' => 'chat.access']);

    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    expect($user->hasPermission('chat.access', $project))->toBeTrue()
        ->and($user->hasPermission('review.view', $project))->toBeFalse();
});

it('aggregates permissions across multiple roles on the same project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $role1 = Role::factory()->create(['project_id' => $project->id, 'name' => 'developer']);
    $role2 = Role::factory()->create(['project_id' => $project->id, 'name' => 'config-editor']);

    $chatPerm = Permission::factory()->create(['name' => 'chat.access']);
    $configPerm = Permission::factory()->create(['name' => 'config.manage']);

    $role1->permissions()->attach($chatPerm);
    $role2->permissions()->attach($configPerm);

    $user->assignRole($role1, $project);
    $user->assignRole($role2, $project);

    expect($user->hasPermission('chat.access', $project))->toBeTrue()
        ->and($user->hasPermission('config.manage', $project))->toBeTrue();
});

it('does not leak permissions across projects', function () {
    $user = User::factory()->create();
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();

    $role = Role::factory()->create(['project_id' => $projectA->id]);
    $perm = Permission::factory()->create(['name' => 'admin.global_config']);
    $role->permissions()->attach($perm);

    $user->assignRole($role, $projectA);

    expect($user->hasPermission('admin.global_config', $projectA))->toBeTrue()
        ->and($user->hasPermission('admin.global_config', $projectB))->toBeFalse();
});

it('returns all permissions for a project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $role = Role::factory()->create(['project_id' => $project->id]);

    $perm1 = Permission::factory()->create(['name' => 'chat.access']);
    $perm2 = Permission::factory()->create(['name' => 'review.view']);
    $role->permissions()->attach([$perm1->id, $perm2->id]);

    $user->assignRole($role, $project);

    $permissions = $user->permissionsForProject($project);
    expect($permissions)->toHaveCount(2)
        ->and($permissions->pluck('name')->toArray())->toContain('chat.access', 'review.view');
});

it('can remove a role from a user on a project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'developer']);
    $perm = Permission::factory()->create(['name' => 'chat.access']);
    $role->permissions()->attach($perm);

    $user->assignRole($role, $project);
    expect($user->hasPermission('chat.access', $project))->toBeTrue();

    $user->removeRole($role, $project);
    expect($user->hasPermission('chat.access', $project))->toBeFalse()
        ->and($user->hasRole('developer', $project))->toBeFalse();
});

it('returns roles for a specific project', function () {
    $user = User::factory()->create();
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();

    $role1 = Role::factory()->create(['project_id' => $projectA->id, 'name' => 'admin']);
    $role2 = Role::factory()->create(['project_id' => $projectB->id, 'name' => 'viewer']);

    $user->assignRole($role1, $projectA);
    $user->assignRole($role2, $projectB);

    $rolesA = $user->rolesForProject($projectA);
    expect($rolesA)->toHaveCount(1)
        ->and($rolesA->first()->name)->toBe('admin');
});

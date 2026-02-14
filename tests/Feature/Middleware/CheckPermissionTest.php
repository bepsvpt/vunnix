<?php

use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Register a test route protected by the permission middleware
    Route::middleware(['auth', 'permission:review.view'])->get(
        '/test-permission/{project}',
        fn () => response()->json(['ok' => true])
    );

    // Register a test route that uses project_id parameter instead
    Route::middleware(['auth', 'permission:chat.access'])->get(
        '/test-permission-param',
        fn () => response()->json(['ok' => true])
    );
});

it('returns 200 when user has the required permission', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $role = Role::factory()->create(['project_id' => $project->id]);
    $perm = Permission::factory()->create(['name' => 'review.view']);
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    $this->actingAs($user)
        ->getJson("/test-permission/{$project->id}")
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('returns 403 when user lacks the required permission', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    // User has no roles on this project
    $this->actingAs($user)
        ->getJson("/test-permission/{$project->id}")
        ->assertForbidden();
});

it('returns 403 when user has the permission on a different project', function () {
    $user = User::factory()->create();
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();

    $role = Role::factory()->create(['project_id' => $projectA->id]);
    $perm = Permission::factory()->create(['name' => 'review.view']);
    $role->permissions()->attach($perm);
    $user->assignRole($role, $projectA);

    // Try to access projectB — should fail even though user has permission on projectA
    $this->actingAs($user)
        ->getJson("/test-permission/{$projectB->id}")
        ->assertForbidden();
});

it('returns 401 when user is not authenticated', function () {
    $project = Project::factory()->create();

    $this->getJson("/test-permission/{$project->id}")
        ->assertUnauthorized();
});

it('returns 200 when admin permission grants access to admin endpoints', function () {
    Route::middleware(['auth', 'permission:admin.global_config'])->get(
        '/test-admin/{project}',
        fn () => response()->json(['admin' => true])
    );

    $user = User::factory()->create();
    $project = Project::factory()->create();
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'admin']);
    $perm = Permission::factory()->create(['name' => 'admin.global_config']);
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    $this->actingAs($user)
        ->getJson("/test-admin/{$project->id}")
        ->assertOk()
        ->assertJson(['admin' => true]);
});

it('resolves project from project_id query parameter', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $role = Role::factory()->create(['project_id' => $project->id]);
    $perm = Permission::factory()->create(['name' => 'chat.access']);
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    $this->actingAs($user)
        ->getJson("/test-permission-param?project_id={$project->id}")
        ->assertOk();
});

it('returns 403 when no project context is provided', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/test-permission-param')
        ->assertForbidden();
});

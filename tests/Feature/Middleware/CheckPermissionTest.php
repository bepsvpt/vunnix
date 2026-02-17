<?php

use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Register test routes under /api/ prefix to avoid SPA catch-all route (/{any})
    // which intercepts all non-excluded GET paths and returns the Vue app shell
    Route::middleware(['auth', 'permission:review.view'])->get(
        '/api/test-permission/{project}',
        fn () => response()->json(['ok' => true])
    );

    Route::middleware(['auth', 'permission:chat.access'])->get(
        '/api/test-permission-param',
        fn () => response()->json(['ok' => true])
    );
});

it('returns 200 when user has the required permission', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $role = Role::factory()->create(['project_id' => $project->id]);
    $perm = Permission::factory()->create(['name' => 'review.view']);
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    $this->actingAs($user)
        ->getJson("/api/test-permission/{$project->id}")
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('returns 403 when user lacks the required permission', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    // User has no roles on this project
    $this->actingAs($user)
        ->getJson("/api/test-permission/{$project->id}")
        ->assertForbidden();
});

it('returns 403 when user has the permission on a different project', function (): void {
    $user = User::factory()->create();
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();

    $role = Role::factory()->create(['project_id' => $projectA->id]);
    $perm = Permission::factory()->create(['name' => 'review.view']);
    $role->permissions()->attach($perm);
    $user->assignRole($role, $projectA);

    // Try to access projectB — should fail even though user has permission on projectA
    $this->actingAs($user)
        ->getJson("/api/test-permission/{$projectB->id}")
        ->assertForbidden();
});

it('returns 401 when user is not authenticated', function (): void {
    $project = Project::factory()->create();

    $this->getJson("/api/test-permission/{$project->id}")
        ->assertUnauthorized();
});

it('returns 200 when admin permission grants access to admin endpoints', function (): void {
    Route::middleware(['auth', 'permission:admin.global_config'])->get(
        '/api/test-admin/{project}',
        fn () => response()->json(['admin' => true])
    );

    $user = User::factory()->create();
    $project = Project::factory()->create();
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'admin']);
    $perm = Permission::factory()->create(['name' => 'admin.global_config']);
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    $this->actingAs($user)
        ->getJson("/api/test-admin/{$project->id}")
        ->assertOk()
        ->assertJson(['admin' => true]);
});

it('resolves project from project_id query parameter', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $role = Role::factory()->create(['project_id' => $project->id]);
    $perm = Permission::factory()->create(['name' => 'chat.access']);
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    $this->actingAs($user)
        ->getJson("/api/test-permission-param?project_id={$project->id}")
        ->assertOk();
});

it('returns 403 when no project context is provided', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/test-permission-param')
        ->assertForbidden();
});

it('returns 401 when request reaches CheckPermission without auth middleware', function (): void {
    // Register a route with permission middleware but WITHOUT auth middleware.
    // This tests line 27 of CheckPermission where $request->user() returns null.
    Route::middleware(['permission:some.perm'])->get(
        '/api/test-permission-no-auth',
        fn () => response()->json(['ok' => true])
    );

    $this->getJson('/api/test-permission-no-auth')
        ->assertUnauthorized();
});

it('resolves project via route model binding and returns it', function (): void {
    // This explicitly tests line 46-47 of resolveProject where
    // $request->route('project') instanceof Project is true
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $role = Role::factory()->create(['project_id' => $project->id]);
    $perm = Permission::factory()->create(['name' => 'review.view']);
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    // The /api/test-permission/{project} route uses model binding
    // so $request->route('project') returns a Project instance
    $this->actingAs($user)
        ->getJson("/api/test-permission/{$project->id}")
        ->assertOk();
});

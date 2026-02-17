<?php

use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('registers permission gates from the database', function (): void {
    // Create permissions in the database
    $perm1 = Permission::factory()->create(['name' => 'test.gate.alpha']);
    $perm2 = Permission::factory()->create(['name' => 'test.gate.beta']);

    // Re-register gates by calling the provider's boot method again
    // The AppServiceProvider runs at boot time, but permissions created
    // during test setup weren't there yet. Re-trigger registration.
    app()->make(\App\Providers\AppServiceProvider::class, ['app' => app()])->boot();

    expect(Gate::has('test.gate.alpha'))->toBeTrue();
    expect(Gate::has('test.gate.beta'))->toBeTrue();
});

it('gate check allows user with the permission on a project', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $role = Role::factory()->create(['project_id' => $project->id]);
    $permission = Permission::factory()->create(['name' => 'test.gate.check']);

    $role->permissions()->attach($permission);
    $user->assignRole($role, $project);

    // Re-register gates so this permission is known
    app()->make(\App\Providers\AppServiceProvider::class, ['app' => app()])->boot();

    expect(Gate::forUser($user)->allows('test.gate.check', [$project]))->toBeTrue();
});

it('gate check denies user without the permission on a project', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    Permission::factory()->create(['name' => 'test.gate.denied']);

    // Re-register gates
    app()->make(\App\Providers\AppServiceProvider::class, ['app' => app()])->boot();

    expect(Gate::forUser($user)->allows('test.gate.denied', [$project]))->toBeFalse();
});

it('gate check denies user with permission on a different project', function (): void {
    $user = User::factory()->create();
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $role = Role::factory()->create(['project_id' => $projectA->id]);
    $permission = Permission::factory()->create(['name' => 'test.gate.cross_project']);

    $role->permissions()->attach($permission);
    $user->assignRole($role, $projectA);

    // Re-register gates
    app()->make(\App\Providers\AppServiceProvider::class, ['app' => app()])->boot();

    // User has the permission on projectA but NOT projectB
    expect(Gate::forUser($user)->allows('test.gate.cross_project', [$projectA]))->toBeTrue();
    expect(Gate::forUser($user)->allows('test.gate.cross_project', [$projectB]))->toBeFalse();
});

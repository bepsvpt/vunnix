<?php

use App\Models\Permission;
use App\Models\Project;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds all default permissions', function () {
    $this->seed(RbacSeeder::class);

    expect(Permission::count())->toBe(7);

    $expected = [
        'chat.access',
        'chat.dispatch_task',
        'review.view',
        'review.trigger',
        'config.manage',
        'admin.roles',
        'admin.global_config',
    ];

    foreach ($expected as $name) {
        expect(Permission::where('name', $name)->exists())->toBeTrue("Permission {$name} should exist");
    }
});

it('assigns correct groups to permissions', function () {
    $this->seed(RbacSeeder::class);

    expect(Permission::where('name', 'chat.access')->value('group'))->toBe('chat')
        ->and(Permission::where('name', 'review.view')->value('group'))->toBe('review')
        ->and(Permission::where('name', 'config.manage')->value('group'))->toBe('config')
        ->and(Permission::where('name', 'admin.roles')->value('group'))->toBe('admin');
});

it('is idempotent — running twice does not duplicate permissions', function () {
    $this->seed(RbacSeeder::class);
    $this->seed(RbacSeeder::class);

    expect(Permission::count())->toBe(7);
});

it('creates default roles for a project', function () {
    $this->seed(RbacSeeder::class);
    $project = Project::factory()->create();

    RbacSeeder::createDefaultRolesForProject($project);

    expect($project->roles)->toHaveCount(3);

    $admin = $project->roles()->where('name', 'admin')->first();
    $developer = $project->roles()->where('name', 'developer')->first();
    $viewer = $project->roles()->where('name', 'viewer')->first();

    expect($admin)->not->toBeNull()
        ->and($developer)->not->toBeNull()
        ->and($viewer)->not->toBeNull();

    // Admin should have all 7 permissions
    expect($admin->permissions)->toHaveCount(7);

    // Developer should have 4 permissions
    expect($developer->permissions)->toHaveCount(4);
    expect($developer->is_default)->toBeTrue();

    // Viewer should have 1 permission
    expect($viewer->permissions)->toHaveCount(1);
    expect($viewer->permissions->first()->name)->toBe('review.view');
});

it('createDefaultRolesForProject is idempotent', function () {
    $this->seed(RbacSeeder::class);
    $project = Project::factory()->create();

    RbacSeeder::createDefaultRolesForProject($project);
    RbacSeeder::createDefaultRolesForProject($project);

    expect($project->roles)->toHaveCount(3);
});

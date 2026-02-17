<?php

use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns 401 for unauthenticated requests', function (): void {
    $response = $this->getJson('/api/v1/user');

    $response->assertStatus(401);
});

it('returns authenticated user profile', function (): void {
    $user = User::factory()->create([
        'name' => 'Jane Dev',
        'email' => 'jane@example.com',
        'username' => 'janedev',
        'avatar_url' => 'https://gitlab.com/avatar.png',
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/user');

    $response->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.name', 'Jane Dev')
        ->assertJsonPath('data.email', 'jane@example.com')
        ->assertJsonPath('data.username', 'janedev')
        ->assertJsonPath('data.avatar_url', 'https://gitlab.com/avatar.png')
        ->assertJsonPath('data.projects', []);
});

it('does not expose sensitive fields', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/user');

    $response->assertOk()
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token')
        ->assertJsonMissingPath('data.oauth_token')
        ->assertJsonMissingPath('data.oauth_refresh_token');
});

it('includes accessible projects with roles and permissions', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create(['name' => 'My Project']);

    // Attach user to project
    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    // Create role with permissions on the project
    $role = Role::factory()->create([
        'project_id' => $project->id,
        'name' => 'developer',
    ]);
    $permission = Permission::factory()->create([
        'name' => 'chat.access',
        'group' => 'chat',
    ]);
    $role->permissions()->attach($permission->id);
    $user->assignRole($role, $project);

    $response = $this->actingAs($user)->getJson('/api/v1/user');

    $response->assertOk()
        ->assertJsonCount(1, 'data.projects')
        ->assertJsonPath('data.projects.0.id', $project->id)
        ->assertJsonPath('data.projects.0.name', 'My Project')
        ->assertJsonPath('data.projects.0.slug', $project->slug)
        ->assertJsonPath('data.projects.0.gitlab_project_id', $project->gitlab_project_id)
        ->assertJsonPath('data.projects.0.roles', ['developer'])
        ->assertJsonPath('data.projects.0.permissions', ['chat.access']);
});

it('excludes disabled projects', function (): void {
    $user = User::factory()->create();
    $enabledProject = Project::factory()->enabled()->create(['name' => 'Enabled']);
    $disabledProject = Project::factory()->create(['name' => 'Disabled', 'enabled' => false]);

    $user->projects()->attach([
        $enabledProject->id => ['gitlab_access_level' => 30, 'synced_at' => now()],
        $disabledProject->id => ['gitlab_access_level' => 30, 'synced_at' => now()],
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/user');

    $response->assertOk()
        ->assertJsonCount(1, 'data.projects')
        ->assertJsonPath('data.projects.0.name', 'Enabled');
});

it('includes multiple projects with different roles', function (): void {
    $user = User::factory()->create();
    $projectA = Project::factory()->enabled()->create(['name' => 'Project A']);
    $projectB = Project::factory()->enabled()->create(['name' => 'Project B']);

    $user->projects()->attach([
        $projectA->id => ['gitlab_access_level' => 30, 'synced_at' => now()],
        $projectB->id => ['gitlab_access_level' => 40, 'synced_at' => now()],
    ]);

    $roleA = Role::factory()->create(['project_id' => $projectA->id, 'name' => 'developer']);
    $roleB = Role::factory()->create(['project_id' => $projectB->id, 'name' => 'admin']);
    $perm1 = Permission::factory()->create(['name' => 'chat.access']);
    $perm2 = Permission::factory()->create(['name' => 'admin.roles']);
    $roleA->permissions()->attach($perm1->id);
    $roleB->permissions()->attach([$perm1->id, $perm2->id]);
    $user->assignRole($roleA, $projectA);
    $user->assignRole($roleB, $projectB);

    $response = $this->actingAs($user)->getJson('/api/v1/user');

    $response->assertOk()
        ->assertJsonCount(2, 'data.projects');

    $projectAData = collect($response->json('data.projects'))->firstWhere('name', 'Project A');
    $projectBData = collect($response->json('data.projects'))->firstWhere('name', 'Project B');

    expect($projectAData['roles'])->toBe(['developer'])
        ->and($projectAData['permissions'])->toBe(['chat.access'])
        ->and($projectBData['roles'])->toBe(['admin'])
        ->and($projectBData['permissions'])->toContain('chat.access')
        ->and($projectBData['permissions'])->toContain('admin.roles');
});

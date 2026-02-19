<?php

use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    if (! Schema::hasTable('agent_conversations')) {
        Schema::create('agent_conversations', function ($table): void {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('title');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });
    } elseif (! Schema::hasColumn('agent_conversations', 'project_id')) {
        Schema::table('agent_conversations', function ($table): void {
            $table->unsignedBigInteger('project_id')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
    }

    if (! Schema::hasTable('agent_conversation_messages')) {
        Schema::create('agent_conversation_messages', function ($table): void {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36)->index();
            $table->foreignId('user_id');
            $table->string('agent');
            $table->string('role', 25);
            $table->text('content');
            $table->text('attachments');
            $table->text('tool_calls');
            $table->text('tool_results');
            $table->text('usage');
            $table->text('meta');
            $table->timestamps();
        });
    }
});

function createRoleAdmin(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'admin']);
    $perm = Permission::firstOrCreate(
        ['name' => 'admin.roles'],
        ['description' => 'Can create/edit roles and assign permissions', 'group' => 'admin']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

function createNonRoleAdmin(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'developer']);
    $perm = Permission::firstOrCreate(
        ['name' => 'chat.access'],
        ['description' => 'Chat access', 'group' => 'chat']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

// ─── List Roles ─────────────────────────────────────────────────

it('returns role list for role admin', function (): void {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);

    // Create an additional role with permissions
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'reviewer']);
    $perm = Permission::firstOrCreate(['name' => 'review.view'], ['description' => 'View reviews', 'group' => 'review']);
    $role->permissions()->attach($perm);

    $this->actingAs($admin)
        ->getJson('/api/v1/admin/roles')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'project_id', 'project_name', 'name', 'description', 'is_default', 'permissions', 'user_count']],
        ]);
});

it('filters roles by project_id', function (): void {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $admin = createRoleAdmin($projectA);

    Role::factory()->create(['project_id' => $projectA->id, 'name' => 'viewer']);
    Role::factory()->create(['project_id' => $projectB->id, 'name' => 'viewer']);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/admin/roles?project_id={$projectA->id}")
        ->assertOk();

    // Should only contain roles from projectA (admin + viewer = 2)
    $data = $response->json('data');
    foreach ($data as $role) {
        expect($role['project_id'])->toBe($projectA->id);
    }
});

it('rejects role list for non-role-admin', function (): void {
    $project = Project::factory()->create();
    $user = createNonRoleAdmin($project);

    $this->actingAs($user)
        ->getJson('/api/v1/admin/roles')
        ->assertForbidden();
});

it('rejects role list for unauthenticated users', function (): void {
    $this->getJson('/api/v1/admin/roles')
        ->assertUnauthorized();
});

// ─── List Permissions ───────────────────────────────────────────

it('returns all permissions for role admin', function (): void {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);

    $this->actingAs($admin)
        ->getJson('/api/v1/admin/permissions')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['name', 'description', 'group']],
        ]);
});

// ─── Create Role ────────────────────────────────────────────────

it('creates a role with permissions', function (): void {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);

    Permission::firstOrCreate(['name' => 'chat.access'], ['description' => 'Chat access', 'group' => 'chat']);
    Permission::firstOrCreate(['name' => 'review.view'], ['description' => 'View reviews', 'group' => 'review']);

    $this->actingAs($admin)
        ->postJson('/api/v1/admin/roles', [
            'project_id' => $project->id,
            'name' => 'custom-role',
            'description' => 'A custom role',
            'permissions' => ['chat.access', 'review.view'],
        ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'custom-role')
        ->assertJsonPath('data.project_id', $project->id);

    $role = Role::where('name', 'custom-role')->first();
    expect($role)->not->toBeNull()
        ->and($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['chat.access', 'review.view']);
});

it('creates a role with empty permissions', function (): void {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);

    $this->actingAs($admin)
        ->postJson('/api/v1/admin/roles', [
            'project_id' => $project->id,
            'name' => 'viewer',
            'permissions' => [],
        ])
        ->assertCreated()
        ->assertJsonPath('data.permissions', []);
});

it('rejects creating role with invalid project', function (): void {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);

    $this->actingAs($admin)
        ->postJson('/api/v1/admin/roles', [
            'project_id' => 99999,
            'name' => 'test',
            'permissions' => [],
        ])
        ->assertUnprocessable();
});

// ─── Update Role ────────────────────────────────────────────────

it('updates a role name and permissions', function (): void {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'old-name']);

    Permission::firstOrCreate(['name' => 'chat.access'], ['description' => 'Chat access', 'group' => 'chat']);

    $this->actingAs($admin)
        ->putJson("/api/v1/admin/roles/{$role->id}", [
            'name' => 'new-name',
            'permissions' => ['chat.access'],
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'new-name');

    $role->refresh();
    expect($role->name)->toBe('new-name')
        ->and($role->permissions->pluck('name')->all())->toBe(['chat.access']);
});

// ─── Delete Role ────────────────────────────────────────────────

it('deletes a role with no users', function (): void {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'to-delete']);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/admin/roles/{$role->id}")
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(Role::find($role->id))->toBeNull();
});

it('rejects deleting a role with assigned users', function (): void {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'in-use']);
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $user->assignRole($role, $project);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/admin/roles/{$role->id}")
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

// ─── Assignments ────────────────────────────────────────────────

it('lists role assignments across all projects', function (): void {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);

    $this->actingAs($admin)
        ->getJson('/api/v1/admin/role-assignments')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['user_id', 'user_name', 'role_id', 'role_name', 'project_id', 'project_name']],
        ]);
});

it('filters assignments by project_id', function (): void {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $admin = createRoleAdmin($projectA);

    // Admin also has a role on projectA — verify filtering works
    $response = $this->actingAs($admin)
        ->getJson("/api/v1/admin/role-assignments?project_id={$projectA->id}")
        ->assertOk();

    $data = $response->json('data');
    foreach ($data as $assignment) {
        expect($assignment['project_id'])->toBe($projectA->id);
    }
});

it('assigns a role to a user', function (): void {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'developer']);
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $this->actingAs($admin)
        ->postJson('/api/v1/admin/role-assignments', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'project_id' => $project->id,
        ])
        ->assertCreated()
        ->assertJsonPath('success', true);

    expect($user->hasRole('developer', $project))->toBeTrue();
});

it('rejects duplicate role assignment', function (): void {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'developer']);
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $user->assignRole($role, $project);

    $this->actingAs($admin)
        ->postJson('/api/v1/admin/role-assignments', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'project_id' => $project->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

it('rejects assigning role to an unauthorized project', function (): void {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $admin = createRoleAdmin($projectA);
    $role = Role::factory()->create(['project_id' => $projectA->id, 'name' => 'developer']);
    $user = User::factory()->create();
    $projectB->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $this->actingAs($admin)
        ->postJson('/api/v1/admin/role-assignments', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'project_id' => $projectB->id,
        ])
        ->assertStatus(403);
});

it('revokes a role assignment', function (): void {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'developer']);
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $user->assignRole($role, $project);

    $this->actingAs($admin)
        ->deleteJson('/api/v1/admin/role-assignments', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'project_id' => $project->id,
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($user->hasRole('developer', $project))->toBeFalse();
});

// ─── Users List ─────────────────────────────────────────────────

it('returns user list for assignment dropdowns', function (): void {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);

    $this->actingAs($admin)
        ->getJson('/api/v1/admin/users')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'name', 'email', 'username']],
        ]);
});

// ─── Integration: Assign + Access ───────────────────────────────

it('grants access after role assignment and denies after revocation', function (): void {
    $project = Project::factory()->create();
    $admin = createRoleAdmin($project);

    // Create a role with chat.access permission
    $chatPerm = Permission::firstOrCreate(['name' => 'chat.access'], ['description' => 'Chat access', 'group' => 'chat']);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'chatter']);
    $role->permissions()->attach($chatPerm);

    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    // Before assignment — no permission
    expect($user->hasPermission('chat.access', $project))->toBeFalse();

    // Assign role
    $user->assignRole($role, $project, $admin);
    expect($user->hasPermission('chat.access', $project))->toBeTrue();

    // Revoke role
    $user->removeRole($role, $project);
    expect($user->hasPermission('chat.access', $project))->toBeFalse();
});

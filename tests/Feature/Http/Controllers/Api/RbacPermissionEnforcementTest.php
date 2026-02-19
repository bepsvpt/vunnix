<?php

use App\Models\Conversation;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
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

function attachRbacMembership(User $user, Project $project): void
{
    $project->users()->syncWithoutDetaching([
        $user->id => ['gitlab_access_level' => 30, 'synced_at' => now()],
    ]);
}

function permissionGroup(string $permission): string
{
    return match (true) {
        str_starts_with($permission, 'chat.') => 'chat',
        str_starts_with($permission, 'review.') => 'review',
        str_starts_with($permission, 'admin.') => 'admin',
        default => 'misc',
    };
}

/**
 * @param  array<int, string>  $permissions
 */
function grantPermissions(User $user, Project $project, array $permissions, string $roleName): void
{
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => $roleName]);

    foreach ($permissions as $permissionName) {
        $permission = Permission::firstOrCreate(
            ['name' => $permissionName],
            ['description' => "{$permissionName} permission", 'group' => permissionGroup($permissionName)]
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }

    $user->assignRole($role, $project);
}

it('denies viewer from sending messages and streaming in conversations', function (): void {
    $project = Project::factory()->enabled()->create();
    $viewer = User::factory()->create(['oauth_token' => null]);
    attachRbacMembership($viewer, $project);
    grantPermissions($viewer, $project, ['review.view'], 'viewer');

    $conversation = Conversation::factory()->forProject($project)->create();

    $this->actingAs($viewer)
        ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Can I send this?',
        ])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->postJson("/api/v1/conversations/{$conversation->id}/stream", [
            'content' => 'Can I stream this?',
        ])
        ->assertForbidden();
});

it('allows developer with chat.access to view conversations', function (): void {
    $project = Project::factory()->enabled()->create();
    $developer = User::factory()->create(['oauth_token' => null]);
    attachRbacMembership($developer, $project);
    grantPermissions($developer, $project, ['chat.access'], 'developer');

    $conversation = Conversation::factory()->forUser($developer)->forProject($project)->create();

    $this->actingAs($developer)
        ->getJson("/api/v1/conversations/{$conversation->id}")
        ->assertOk();
});

it('denies task result viewing without review.view', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = User::factory()->create(['oauth_token' => null]);
    attachRbacMembership($user, $project);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/tasks/{$task->id}/view")
        ->assertForbidden();
});

it('denies external review trigger without review.trigger', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = User::factory()->create(['oauth_token' => null]);
    attachRbacMembership($user, $project);
    grantPermissions($user, $project, ['review.view'], 'viewer');

    $this->actingAs($user)
        ->postJson('/api/v1/ext/tasks/review', [
            'project_id' => $project->id,
            'mr_iid' => 101,
        ])
        ->assertForbidden();
});

it('returns 422 when assigning a role to a non-member', function (): void {
    $project = Project::factory()->enabled()->create();
    $admin = User::factory()->create(['oauth_token' => null]);
    attachRbacMembership($admin, $project);
    grantPermissions($admin, $project, ['admin.roles'], 'role-admin');

    $targetUser = User::factory()->create(['oauth_token' => null]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'developer']);

    $this->actingAs($admin)
        ->postJson('/api/v1/admin/role-assignments', [
            'user_id' => $targetUser->id,
            'role_id' => $role->id,
            'project_id' => $project->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

it('denies dashboard endpoints without review.view', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = User::factory()->create(['oauth_token' => null]);
    attachRbacMembership($user, $project);

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard/overview')
        ->assertForbidden();

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard/quality')
        ->assertForbidden();
});

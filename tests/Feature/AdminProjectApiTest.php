<?php

use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! Schema::hasTable('agent_conversations')) {
        Schema::create('agent_conversations', function ($table) {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('title');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });
    } elseif (! Schema::hasColumn('agent_conversations', 'project_id')) {
        Schema::table('agent_conversations', function ($table) {
            $table->unsignedBigInteger('project_id')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
    }

    if (! Schema::hasTable('agent_conversation_messages')) {
        Schema::create('agent_conversation_messages', function ($table) {
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

    config(['services.gitlab.host' => 'https://gitlab.example.com']);
    config(['services.gitlab.bot_token' => 'test-bot-token']);
    config(['services.gitlab.vunnix_project_id' => 100]);
});

function createAdmin(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'admin']);
    $perm = Permission::firstOrCreate(
        ['name' => 'admin.global_config'],
        ['description' => 'Admin settings', 'group' => 'admin']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

function createNonAdmin(Project $project): User
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

// ─── Index ──────────────────────────────────────────────────────

it('returns project list for admin users', function () {
    $project = Project::factory()->create(['enabled' => true]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $admin = createAdmin($project);

    $this->actingAs($admin)
        ->getJson('/api/v1/admin/projects')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'name', 'slug', 'gitlab_project_id', 'enabled', 'webhook_configured', 'recent_task_count', 'active_conversation_count']],
        ]);
});

it('rejects project list for non-admin users', function () {
    $project = Project::factory()->create(['enabled' => true]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $user = createNonAdmin($project);

    $this->actingAs($user)
        ->getJson('/api/v1/admin/projects')
        ->assertForbidden();
});

it('rejects project list for unauthenticated users', function () {
    $this->getJson('/api/v1/admin/projects')
        ->assertUnauthorized();
});

// ─── Enable ─────────────────────────────────────────────────────

it('enables a project successfully', function () {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => false,
    ]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $admin = createAdmin($project);

    Http::fake([
        'gitlab.example.com/api/v4/user' => Http::response(['id' => 99]),
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response([
            'id' => 99, 'access_level' => 40,
        ]),
        'gitlab.example.com/api/v4/projects/100' => Http::response([
            'id' => 100, 'visibility' => 'internal',
        ]),
        'gitlab.example.com/api/v4/projects/42/hooks' => Http::response([
            'id' => 555,
        ], 201),
        'gitlab.example.com/api/v4/projects/42/labels' => Http::response([
            'id' => 1,
        ], 201),
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/projects/{$project->id}/enable")
        ->assertOk()
        ->assertJsonPath('success', true);

    $project->refresh();
    expect($project->enabled)->toBeTrue();
    expect($project->webhook_id)->toBe(555);
});

it('returns error when bot is not a member on enable', function () {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => false,
    ]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $admin = createAdmin($project);

    Http::fake([
        'gitlab.example.com/api/v4/user' => Http::response(['id' => 99]),
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response(
            ['message' => '404 Not found'],
            404
        ),
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/projects/{$project->id}/enable")
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['success', 'error']);
});

// ─── Disable ────────────────────────────────────────────────────

it('disables an enabled project', function () {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => true,
        'webhook_configured' => true,
        'webhook_id' => 555,
    ]);
    $admin = createAdmin($project);

    Http::fake([
        'gitlab.example.com/api/v4/projects/42/hooks/555' => Http::response(null, 204),
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/projects/{$project->id}/disable")
        ->assertOk()
        ->assertJsonPath('success', true);

    $project->refresh();
    expect($project->enabled)->toBeFalse();
    expect($project->webhook_id)->toBeNull();
});

it('rejects enable from non-admin', function () {
    $project = Project::factory()->create(['enabled' => false]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $user = createNonAdmin($project);

    $this->actingAs($user)
        ->postJson("/api/v1/admin/projects/{$project->id}/enable")
        ->assertForbidden();
});

// ─── Show ───────────────────────────────────────────────────────

it('returns project details for admin', function () {
    $project = Project::factory()->create(['enabled' => true]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $admin = createAdmin($project);

    $this->actingAs($admin)
        ->getJson("/api/v1/admin/projects/{$project->id}")
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['id', 'name', 'slug', 'gitlab_project_id', 'enabled', 'webhook_configured', 'recent_task_count', 'active_conversation_count'],
        ]);
});

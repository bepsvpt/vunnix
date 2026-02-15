<?php

use App\Models\GlobalSetting;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
});

function createSettingsAdmin(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'admin']);
    $perm = Permission::firstOrCreate(
        ['name' => 'admin.global_config'],
        ['description' => 'Can edit global Vunnix settings', 'group' => 'admin']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

function createNonSettingsAdmin(Project $project): User
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

// ─── GET /admin/settings ────────────────────────────────────────

it('returns settings list for admin', function () {
    $project = Project::factory()->create();
    $user = createSettingsAdmin($project);

    GlobalSetting::set('ai_model', 'opus', 'string', 'Default AI model');
    GlobalSetting::set('ai_language', 'en', 'string', 'AI response language');

    $response = $this->actingAs($user)->getJson('/api/v1/admin/settings');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['key', 'value', 'type', 'description'],
            ],
            'api_key_configured',
            'defaults',
        ]);
});

it('includes api_key_configured status', function () {
    $project = Project::factory()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->getJson('/api/v1/admin/settings');

    $response->assertOk()
        ->assertJsonPath('api_key_configured', fn ($v) => is_bool($v));
});

it('includes defaults for reference', function () {
    $project = Project::factory()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->getJson('/api/v1/admin/settings');

    $response->assertOk()
        ->assertJsonPath('defaults.ai_model', 'opus')
        ->assertJsonPath('defaults.ai_language', 'en')
        ->assertJsonPath('defaults.timeout_minutes', 10)
        ->assertJsonPath('defaults.max_tokens', 8192);
});

it('returns 403 for non-admin user', function () {
    $project = Project::factory()->create();
    $user = createNonSettingsAdmin($project);

    $response = $this->actingAs($user)->getJson('/api/v1/admin/settings');

    $response->assertForbidden();
});

it('returns 401 for unauthenticated request', function () {
    $response = $this->getJson('/api/v1/admin/settings');

    $response->assertUnauthorized();
});

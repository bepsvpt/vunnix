<?php

use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
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

function createProjectConfigAdmin(Project $project): User
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

function createProjectConfigNonAdmin(Project $project): User
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

// ─── GET /admin/projects/{project}/config ────────────────────

it('returns effective config for a project', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet', 'timeout_minutes' => 20],
    ]);
    $admin = createProjectConfigAdmin($project);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/admin/projects/{$project->id}/config");

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'settings',
                'effective',
                'setting_keys',
            ],
        ]);

    // Project overrides should appear with source: 'project'
    $effective = $response->json('data.effective');
    expect($effective['ai_model']['source'])->toBe('project');
    expect($effective['ai_model']['value'])->toBe('sonnet');
});

it('returns global defaults when no project overrides', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);
    $admin = createProjectConfigAdmin($project);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/admin/projects/{$project->id}/config");

    $response->assertOk();

    $effective = $response->json('data.effective');
    expect($effective['ai_model']['source'])->toBe('default');
    expect($effective['ai_model']['value'])->toBe('opus');
});

it('rejects config read for non-admin', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $user = createProjectConfigNonAdmin($project);

    $this->actingAs($user)
        ->getJson("/api/v1/admin/projects/{$project->id}/config")
        ->assertForbidden();
});

it('rejects config read for unauthenticated user', function (): void {
    $project = Project::factory()->create();

    $this->getJson("/api/v1/admin/projects/{$project->id}/config")
        ->assertUnauthorized();
});

// ─── PUT /admin/projects/{project}/config ────────────────────

it('updates project config overrides', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);
    $admin = createProjectConfigAdmin($project);

    $response = $this->actingAs($admin)
        ->putJson("/api/v1/admin/projects/{$project->id}/config", [
            'settings' => [
                'ai_model' => 'sonnet',
                'timeout_minutes' => 20,
            ],
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    $config = $project->projectConfig->fresh();
    expect($config->settings['ai_model'])->toBe('sonnet');
    expect($config->settings['timeout_minutes'])->toBe(20);
});

it('removes overrides when value is null', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet'],
    ]);
    $admin = createProjectConfigAdmin($project);

    $response = $this->actingAs($admin)
        ->putJson("/api/v1/admin/projects/{$project->id}/config", [
            'settings' => ['ai_model' => null],
        ]);

    $response->assertOk();

    $config = $project->projectConfig->fresh();
    expect($config->settings)->not->toHaveKey('ai_model');
});

it('returns updated effective config after update', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);
    $admin = createProjectConfigAdmin($project);

    $response = $this->actingAs($admin)
        ->putJson("/api/v1/admin/projects/{$project->id}/config", [
            'settings' => ['ai_model' => 'sonnet'],
        ]);

    $response->assertOk()
        ->assertJsonPath('data.effective.ai_model.value', 'sonnet')
        ->assertJsonPath('data.effective.ai_model.source', 'project');
});

it('creates ProjectConfig if it does not exist', function (): void {
    $project = Project::factory()->create();
    $admin = createProjectConfigAdmin($project);

    $response = $this->actingAs($admin)
        ->putJson("/api/v1/admin/projects/{$project->id}/config", [
            'settings' => ['ai_model' => 'haiku'],
        ]);

    $response->assertOk();

    $config = $project->projectConfig()->first();
    expect($config)->not->toBeNull();
    expect($config->settings['ai_model'])->toBe('haiku');
});

it('rejects config update for non-admin', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $user = createProjectConfigNonAdmin($project);

    $this->actingAs($user)
        ->putJson("/api/v1/admin/projects/{$project->id}/config", [
            'settings' => ['ai_model' => 'sonnet'],
        ])
        ->assertForbidden();
});

it('validates settings is required', function (): void {
    $project = Project::factory()->create();
    $admin = createProjectConfigAdmin($project);

    $this->actingAs($admin)
        ->putJson("/api/v1/admin/projects/{$project->id}/config", [])
        ->assertUnprocessable();
});

it('validates settings must be an array', function (): void {
    $project = Project::factory()->create();
    $admin = createProjectConfigAdmin($project);

    $this->actingAs($admin)
        ->putJson("/api/v1/admin/projects/{$project->id}/config", [
            'settings' => 'not-an-array',
        ])
        ->assertUnprocessable();
});

it('continues update when audit logging fails', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);
    $admin = createProjectConfigAdmin($project);

    $this->mock(AuditLogService::class)
        ->shouldReceive('logConfigurationChange')
        ->andThrow(new RuntimeException('audit failed'));

    $this->actingAs($admin)
        ->putJson("/api/v1/admin/projects/{$project->id}/config", [
            'settings' => ['ai_model' => 'sonnet'],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);
});

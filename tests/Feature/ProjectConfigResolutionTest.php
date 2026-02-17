<?php

use App\Models\GlobalSetting;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Models\Role;
use App\Models\User;
use App\Services\ProjectConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

function createConfigAdmin(Project $project): User
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

it('API update → service resolution reflects new override', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);
    $admin = createConfigAdmin($project);

    // Verify starts with default
    $service = app(ProjectConfigService::class);
    expect($service->get($project, 'ai_model'))->toBe('opus');

    // Update via API
    $this->actingAs($admin)->putJson("/api/v1/admin/projects/{$project->id}/config", [
        'settings' => ['ai_model' => 'sonnet'],
    ])->assertOk();

    // Service should now resolve to project override
    expect($service->get($project, 'ai_model'))->toBe('sonnet');
});

it('global setting → project inherits when no override', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);

    GlobalSetting::set('ai_language', 'ja', 'string');

    $service = app(ProjectConfigService::class);
    expect($service->get($project, 'ai_language'))->toBe('ja');
});

it('project override → takes precedence over global', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_language' => 'de'],
    ]);

    GlobalSetting::set('ai_language', 'ja', 'string');

    $service = app(ProjectConfigService::class);
    expect($service->get($project, 'ai_language'))->toBe('de');
});

it('remove override → falls back to global', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet'],
    ]);
    $admin = createConfigAdmin($project);

    // Remove override
    $this->actingAs($admin)->putJson("/api/v1/admin/projects/{$project->id}/config", [
        'settings' => ['ai_model' => null],
    ])->assertOk();

    $service = app(ProjectConfigService::class);
    expect($service->get($project, 'ai_model'))->toBe('opus');
});

it('cache is invalidated on config update via API', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet'],
    ]);
    $admin = createConfigAdmin($project);

    $service = app(ProjectConfigService::class);
    $service->get($project, 'ai_model'); // populates cache

    // Update via API
    $this->actingAs($admin)->putJson("/api/v1/admin/projects/{$project->id}/config", [
        'settings' => ['ai_model' => 'haiku'],
    ])->assertOk();

    // Cache should be invalidated — should get new value
    expect($service->get($project, 'ai_model'))->toBe('haiku');
});

// ─── T92: 4-level config hierarchy with file layer ──────────────

it('resolves config with 4-level hierarchy: default → global → file → project DB', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'haiku'], // DB override for ai_model only
    ]);

    // Global override for ai_language
    GlobalSetting::set('ai_language', 'ja', 'string');

    // File config provides: ai_model (will be overridden by DB),
    // code_review.auto_review (no DB override — file wins),
    // ai_language (has global but no DB — file wins)
    $fileConfig = [
        'ai_model' => 'sonnet',                 // overridden by project DB
        'code_review.auto_review' => false,      // file wins (no DB override)
        'ai_language' => 'de',                   // file wins over global
    ];

    $service = app(ProjectConfigService::class);

    // Project DB overrides file → 'haiku'
    expect($service->getWithFileConfig($project, 'ai_model', $fileConfig))->toBe('haiku');
    // File config wins (no project DB override) → false
    expect($service->getWithFileConfig($project, 'code_review.auto_review', $fileConfig))->toBe(false);
    // File config wins over global → 'de'
    expect($service->getWithFileConfig($project, 'ai_language', $fileConfig))->toBe('de');
    // No file config, no DB → falls back to global default → 8192
    expect($service->getWithFileConfig($project, 'max_tokens', $fileConfig))->toBe(8192);
});

<?php

use App\Models\GlobalSetting;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Helpers ──────────────────────────────────────────────────

function createConfigManager(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'config-manager']);
    $perm = Permission::firstOrCreate(
        ['name' => 'config.manage'],
        ['description' => 'Can edit project-level config', 'group' => 'config']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

function createTemplateAdmin(Project $project): User
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

function createUnprivilegedUser(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'viewer']);
    $perm = Permission::firstOrCreate(
        ['name' => 'chat.access'],
        ['description' => 'Chat access', 'group' => 'chat']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

// ─── GET /admin/projects/{project}/prd-template ──────────────

it('returns default PRD template when no override exists', function (): void {
    $project = Project::factory()->create();
    $user = createConfigManager($project);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/admin/projects/{$project->id}/prd-template");

    $response->assertOk()
        ->assertJsonStructure(['data' => ['template', 'source']])
        ->assertJsonPath('data.source', 'default');

    expect($response->json('data.template'))->toContain('## Problem');
});

it('returns project-level PRD template override', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['prd_template' => '# Custom Template'],
    ]);
    $user = createConfigManager($project);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/admin/projects/{$project->id}/prd-template");

    $response->assertOk()
        ->assertJsonPath('data.template', '# Custom Template')
        ->assertJsonPath('data.source', 'project');
});

it('returns global PRD template when set and no project override', function (): void {
    $project = Project::factory()->create();
    GlobalSetting::set('prd_template', '# Global Template', 'string', 'PRD template');
    $user = createConfigManager($project);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/admin/projects/{$project->id}/prd-template");

    $response->assertOk()
        ->assertJsonPath('data.template', '# Global Template')
        ->assertJsonPath('data.source', 'global');
});

it('rejects PRD template read for user without config.manage', function (): void {
    $project = Project::factory()->create();
    $user = createUnprivilegedUser($project);

    $this->actingAs($user)
        ->getJson("/api/v1/admin/projects/{$project->id}/prd-template")
        ->assertForbidden();
});

it('rejects PRD template read for unauthenticated user', function (): void {
    $project = Project::factory()->create();

    $this->getJson("/api/v1/admin/projects/{$project->id}/prd-template")
        ->assertUnauthorized();
});

// ─── PUT /admin/projects/{project}/prd-template ──────────────

it('saves project-level PRD template override', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create(['project_id' => $project->id, 'settings' => []]);
    $user = createConfigManager($project);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/admin/projects/{$project->id}/prd-template", [
            'template' => '# My Custom PRD\n\n## Requirements',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.source', 'project');

    $config = $project->projectConfig->fresh();
    expect($config->settings['prd_template'])->toBe('# My Custom PRD\n\n## Requirements');
});

it('removes project PRD template override when template is null', function (): void {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['prd_template' => '# Old Template'],
    ]);
    $user = createConfigManager($project);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/admin/projects/{$project->id}/prd-template", [
            'template' => null,
        ]);

    $response->assertOk();

    $config = $project->projectConfig->fresh();
    expect($config->settings)->not->toHaveKey('prd_template');
});

it('rejects PRD template update for user without config.manage', function (): void {
    $project = Project::factory()->create();
    $user = createUnprivilegedUser($project);

    $this->actingAs($user)
        ->putJson("/api/v1/admin/projects/{$project->id}/prd-template", [
            'template' => '# Hacked',
        ])
        ->assertForbidden();
});

it('validates template must be a string or null', function (): void {
    $project = Project::factory()->create();
    $user = createConfigManager($project);

    $this->actingAs($user)
        ->putJson("/api/v1/admin/projects/{$project->id}/prd-template", [
            'template' => ['not', 'a', 'string'],
        ])
        ->assertUnprocessable();
});

// ─── GET /admin/prd-template (global) ────────────────────────

it('returns global default PRD template', function (): void {
    $project = Project::factory()->create();
    $admin = createTemplateAdmin($project);

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/admin/prd-template');

    $response->assertOk()
        ->assertJsonStructure(['data' => ['template', 'source']])
        ->assertJsonPath('data.source', 'default');

    expect($response->json('data.template'))->toContain('## Problem');
});

it('returns global PRD template override when set', function (): void {
    $project = Project::factory()->create();
    GlobalSetting::set('prd_template', '# Global Custom', 'string', 'PRD template');
    $admin = createTemplateAdmin($project);

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/admin/prd-template');

    $response->assertOk()
        ->assertJsonPath('data.template', '# Global Custom')
        ->assertJsonPath('data.source', 'global');
});

it('rejects global PRD template read without admin.global_config', function (): void {
    $project = Project::factory()->create();
    $user = createConfigManager($project);

    $this->actingAs($user)
        ->getJson('/api/v1/admin/prd-template')
        ->assertForbidden();
});

// ─── PUT /admin/prd-template (global) ────────────────────────

it('saves global PRD template override', function (): void {
    $project = Project::factory()->create();
    $admin = createTemplateAdmin($project);

    $response = $this->actingAs($admin)
        ->putJson('/api/v1/admin/prd-template', [
            'template' => '# Org-Wide PRD',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    expect(GlobalSetting::get('prd_template'))->toBe('# Org-Wide PRD');
});

it('resets global PRD template to default when null', function (): void {
    $project = Project::factory()->create();
    GlobalSetting::set('prd_template', '# Old Global', 'string', 'PRD');
    $admin = createTemplateAdmin($project);

    $response = $this->actingAs($admin)
        ->putJson('/api/v1/admin/prd-template', [
            'template' => null,
        ]);

    $response->assertOk();
    // After deletion, should fall back to hardcoded default
    expect(GlobalSetting::get('prd_template'))->toBeNull();
});

it('rejects global PRD template update without admin.global_config', function (): void {
    $project = Project::factory()->create();
    $user = createConfigManager($project);

    $this->actingAs($user)
        ->putJson('/api/v1/admin/prd-template', [
            'template' => '# Hacked',
        ])
        ->assertForbidden();
});

<?php

use App\Models\OverrelianceAlert;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// ─── Setup ─────────────────────────────────────────────────────

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

/**
 * Helper: create an admin user with admin.global_config permission on a project.
 */
function createOverrelianceAdmin(Project $project): User
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

/**
 * Helper: create a non-admin user on a project.
 */
function createOverrelianceRegularUser(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'developer']);
    $perm = Permission::firstOrCreate(
        ['name' => 'review.view'],
        ['description' => 'Can view AI review results', 'group' => 'review']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

// ─── GET /api/v1/dashboard/overreliance-alerts ─────────────────

it('returns 401 for unauthenticated users on overreliance alerts index', function () {
    $this->getJson('/api/v1/dashboard/overreliance-alerts')
        ->assertUnauthorized();
});

it('returns 403 for non-admin users on overreliance alerts index', function () {
    $project = Project::factory()->enabled()->create();
    $user = createOverrelianceRegularUser($project);

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard/overreliance-alerts')
        ->assertForbidden();
});

it('returns active overreliance alerts for admin users', function () {
    $project = Project::factory()->enabled()->create();
    $user = createOverrelianceAdmin($project);

    OverrelianceAlert::create([
        'rule' => 'high_acceptance_rate',
        'severity' => 'warning',
        'message' => 'Acceptance rate above 95% for 2 consecutive weeks.',
        'context' => ['weekly_rates' => [], 'consecutive_weeks' => 2],
    ]);

    OverrelianceAlert::create([
        'rule' => 'zero_reactions',
        'severity' => 'info',
        'message' => 'Zero negative reactions across 30 findings.',
        'context' => ['total_findings' => 30],
    ]);

    // Acknowledged alert — should NOT appear
    OverrelianceAlert::create([
        'rule' => 'bulk_resolution',
        'severity' => 'warning',
        'message' => 'Bulk resolution pattern detected.',
        'context' => ['ratio' => 75.0],
        'acknowledged' => true,
        'acknowledged_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard/overreliance-alerts')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.rule', 'zero_reactions')
        ->assertJsonPath('data.1.rule', 'high_acceptance_rate');
});

it('returns empty data array when no active overreliance alerts exist', function () {
    $project = Project::factory()->enabled()->create();
    $user = createOverrelianceAdmin($project);

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard/overreliance-alerts')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// ─── PATCH /api/v1/dashboard/overreliance-alerts/{id}/acknowledge

it('returns 401 for unauthenticated users on overreliance acknowledge', function () {
    $alert = OverrelianceAlert::create([
        'rule' => 'high_acceptance_rate',
        'severity' => 'warning',
        'message' => 'Test alert',
        'context' => [],
    ]);

    $this->patchJson("/api/v1/dashboard/overreliance-alerts/{$alert->id}/acknowledge")
        ->assertUnauthorized();
});

it('returns 403 for non-admin users on overreliance acknowledge', function () {
    $project = Project::factory()->enabled()->create();
    $user = createOverrelianceRegularUser($project);

    $alert = OverrelianceAlert::create([
        'rule' => 'high_acceptance_rate',
        'severity' => 'warning',
        'message' => 'Test alert',
        'context' => [],
    ]);

    $this->actingAs($user)
        ->patchJson("/api/v1/dashboard/overreliance-alerts/{$alert->id}/acknowledge")
        ->assertForbidden();
});

it('acknowledges an overreliance alert for admin users', function () {
    $project = Project::factory()->enabled()->create();
    $user = createOverrelianceAdmin($project);

    $alert = OverrelianceAlert::create([
        'rule' => 'high_acceptance_rate',
        'severity' => 'warning',
        'message' => 'Acceptance rate above 95%.',
        'context' => ['threshold' => 95],
    ]);

    $this->actingAs($user)
        ->patchJson("/api/v1/dashboard/overreliance-alerts/{$alert->id}/acknowledge")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.acknowledged', true);

    $alert->refresh();
    expect($alert->acknowledged)->toBeTrue();
    expect($alert->acknowledged_at)->not->toBeNull();
});

it('returns 404 for non-existent overreliance alert on acknowledge', function () {
    $project = Project::factory()->enabled()->create();
    $user = createOverrelianceAdmin($project);

    $this->actingAs($user)
        ->patchJson('/api/v1/dashboard/overreliance-alerts/99999/acknowledge')
        ->assertNotFound();
});

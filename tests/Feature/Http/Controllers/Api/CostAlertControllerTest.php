<?php

use App\Models\CostAlert;
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
function createCostAlertAdmin(Project $project): User
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
function createCostAlertRegularUser(Project $project): User
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

// ─── GET /api/v1/dashboard/cost-alerts ─────────────────────────

it('returns 401 for unauthenticated users on cost alerts index', function () {
    $this->getJson('/api/v1/dashboard/cost-alerts')
        ->assertUnauthorized();
});

it('returns 403 for non-admin users on cost alerts index', function () {
    $project = Project::factory()->enabled()->create();
    $user = createCostAlertRegularUser($project);

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard/cost-alerts')
        ->assertForbidden();
});

it('returns active cost alerts for admin users', function () {
    $project = Project::factory()->enabled()->create();
    $user = createCostAlertAdmin($project);

    CostAlert::create([
        'rule' => 'daily_spike',
        'severity' => 'critical',
        'message' => 'Daily spend ($50.00) exceeds 5× the daily average ($5.00).',
        'context' => ['today_spend' => 50.0, 'avg_daily' => 5.0],
    ]);

    CostAlert::create([
        'rule' => 'monthly_anomaly',
        'severity' => 'critical',
        'message' => 'Monthly spend ($200.00) exceeds 2× average ($80.00).',
        'context' => ['current_spend' => 200.0, 'avg_monthly' => 80.0],
    ]);

    // Acknowledged alert — should NOT appear
    CostAlert::create([
        'rule' => 'single_task_outlier',
        'severity' => 'warning',
        'message' => 'Task #5 cost outlier.',
        'context' => ['task_id' => 5],
        'acknowledged' => true,
        'acknowledged_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard/cost-alerts')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.rule', 'monthly_anomaly')
        ->assertJsonPath('data.1.rule', 'daily_spike');
});

it('returns empty data array when no active alerts exist', function () {
    $project = Project::factory()->enabled()->create();
    $user = createCostAlertAdmin($project);

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard/cost-alerts')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// ─── PATCH /api/v1/dashboard/cost-alerts/{id}/acknowledge ──────

it('returns 401 for unauthenticated users on acknowledge', function () {
    $alert = CostAlert::create([
        'rule' => 'daily_spike',
        'severity' => 'critical',
        'message' => 'Test alert',
        'context' => [],
    ]);

    $this->patchJson("/api/v1/dashboard/cost-alerts/{$alert->id}/acknowledge")
        ->assertUnauthorized();
});

it('returns 403 for non-admin users on acknowledge', function () {
    $project = Project::factory()->enabled()->create();
    $user = createCostAlertRegularUser($project);

    $alert = CostAlert::create([
        'rule' => 'daily_spike',
        'severity' => 'critical',
        'message' => 'Test alert',
        'context' => [],
    ]);

    $this->actingAs($user)
        ->patchJson("/api/v1/dashboard/cost-alerts/{$alert->id}/acknowledge")
        ->assertForbidden();
});

it('acknowledges a cost alert for admin users', function () {
    $project = Project::factory()->enabled()->create();
    $user = createCostAlertAdmin($project);

    $alert = CostAlert::create([
        'rule' => 'daily_spike',
        'severity' => 'critical',
        'message' => 'Daily spend exceeds threshold.',
        'context' => ['today_spend' => 50.0],
    ]);

    $this->actingAs($user)
        ->patchJson("/api/v1/dashboard/cost-alerts/{$alert->id}/acknowledge")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.acknowledged', true);

    $alert->refresh();
    expect($alert->acknowledged)->toBeTrue();
    expect($alert->acknowledged_at)->not->toBeNull();
});

it('returns 404 for non-existent alert on acknowledge', function () {
    $project = Project::factory()->enabled()->create();
    $user = createCostAlertAdmin($project);

    $this->actingAs($user)
        ->patchJson('/api/v1/dashboard/cost-alerts/99999/acknowledge')
        ->assertNotFound();
});

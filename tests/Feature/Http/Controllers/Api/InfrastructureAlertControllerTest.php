<?php

use App\Models\AlertEvent;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// ─── Setup ─────────────────────────────────────────────────────

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

/**
 * Helper: create an admin user with admin.global_config permission on a project.
 */
function createInfraAlertAdmin(Project $project): User
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
function createInfraAlertRegularUser(Project $project): User
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

// ─── GET /api/v1/dashboard/infrastructure-alerts ────────────────

it('returns 401 for unauthenticated users on infrastructure alerts index', function (): void {
    $this->getJson('/api/v1/dashboard/infrastructure-alerts')
        ->assertUnauthorized();
});

it('returns 403 for non-admin users on infrastructure alerts index', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createInfraAlertRegularUser($project);

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard/infrastructure-alerts')
        ->assertForbidden();
});

it('returns active infrastructure alerts for admin users', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createInfraAlertAdmin($project);

    AlertEvent::factory()->create([
        'alert_type' => 'container_health',
        'status' => 'active',
        'severity' => 'high',
        'message' => 'Container unhealthy for >3 minutes.',
        'context' => ['duration_minutes' => 3],
        'created_at' => now()->subMinutes(10),
    ]);

    AlertEvent::factory()->create([
        'alert_type' => 'cpu_usage',
        'status' => 'active',
        'severity' => 'high',
        'message' => 'CPU usage at 95.2% for >6 minutes.',
        'context' => ['cpu_percent' => 95.2, 'duration_minutes' => 6],
        'created_at' => now()->subMinutes(5),
    ]);

    // Resolved alert — should NOT appear
    AlertEvent::factory()->create([
        'alert_type' => 'memory_usage',
        'status' => 'resolved',
        'severity' => 'high',
        'message' => 'Memory usage at 92.5% for >6 minutes.',
        'resolved_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard/infrastructure-alerts')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.alert_type', 'cpu_usage')
        ->assertJsonPath('data.1.alert_type', 'container_health');
});

it('returns empty data array when no active infrastructure alerts exist', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createInfraAlertAdmin($project);

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard/infrastructure-alerts')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// ─── PATCH /api/v1/dashboard/infrastructure-alerts/{id}/acknowledge ──

it('returns 401 for unauthenticated users on infrastructure alert acknowledge', function (): void {
    $alert = AlertEvent::factory()->create([
        'alert_type' => 'cpu_usage',
        'status' => 'active',
    ]);

    $this->patchJson("/api/v1/dashboard/infrastructure-alerts/{$alert->id}/acknowledge")
        ->assertUnauthorized();
});

it('returns 403 for non-admin users on infrastructure alert acknowledge', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createInfraAlertRegularUser($project);

    $alert = AlertEvent::factory()->create([
        'alert_type' => 'cpu_usage',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->patchJson("/api/v1/dashboard/infrastructure-alerts/{$alert->id}/acknowledge")
        ->assertForbidden();
});

it('acknowledges an infrastructure alert for admin users', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createInfraAlertAdmin($project);

    $alert = AlertEvent::factory()->create([
        'alert_type' => 'container_health',
        'status' => 'active',
        'severity' => 'high',
        'message' => 'Container unhealthy for >3 minutes.',
    ]);

    $this->actingAs($user)
        ->patchJson("/api/v1/dashboard/infrastructure-alerts/{$alert->id}/acknowledge")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'resolved');

    $alert->refresh();
    expect($alert->status)->toBe('resolved');
    expect($alert->resolved_at)->not->toBeNull();
});

it('returns 404 for non-existent alert on infrastructure alert acknowledge', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createInfraAlertAdmin($project);

    $this->actingAs($user)
        ->patchJson('/api/v1/dashboard/infrastructure-alerts/99999/acknowledge')
        ->assertNotFound();
});

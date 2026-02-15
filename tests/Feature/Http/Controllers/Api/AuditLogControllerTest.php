<?php

use App\Models\AuditLog;
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

/**
 * Helper: create an admin user with admin.global_config permission.
 */
function createAuditAdmin(Project $project): User
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
function createAuditRegularUser(Project $project): User
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

// ─── Authentication & Authorization ──────────────────────────────

it('returns 401 for unauthenticated users on audit log index', function () {
    $this->getJson('/api/v1/audit-logs')
        ->assertUnauthorized();
});

it('returns 403 for non-admin users on audit log index', function () {
    $project = Project::factory()->enabled()->create();
    $user = createAuditRegularUser($project);

    $this->actingAs($user)
        ->getJson('/api/v1/audit-logs')
        ->assertForbidden();
});

// ─── GET /api/v1/audit-logs ──────────────────────────────────────

it('returns audit logs for admin users with cursor pagination', function () {
    $project = Project::factory()->enabled()->create();
    $user = createAuditAdmin($project);

    AuditLog::factory()->count(3)->create([
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/audit-logs')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [['id', 'event_type', 'summary', 'created_at']],
            'meta' => ['next_cursor', 'per_page'],
        ]);
});

it('returns empty data when no audit logs exist', function () {
    $project = Project::factory()->enabled()->create();
    $user = createAuditAdmin($project);

    $this->actingAs($user)
        ->getJson('/api/v1/audit-logs')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('filters audit logs by event_type', function () {
    $project = Project::factory()->enabled()->create();
    $user = createAuditAdmin($project);

    AuditLog::factory()->conversationTurn()->create(['user_id' => $user->id]);
    AuditLog::factory()->taskExecution()->create(['user_id' => $user->id]);
    AuditLog::factory()->authEvent()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/audit-logs?event_type=conversation_turn')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.event_type', 'conversation_turn');
});

it('filters audit logs by user_id', function () {
    $project = Project::factory()->enabled()->create();
    $admin = createAuditAdmin($project);
    $other = User::factory()->create();

    AuditLog::factory()->create(['user_id' => $admin->id]);
    AuditLog::factory()->create(['user_id' => $other->id]);

    $this->actingAs($admin)
        ->getJson("/api/v1/audit-logs?user_id={$other->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user_id', $other->id);
});

it('filters audit logs by project_id', function () {
    $project = Project::factory()->enabled()->create();
    $otherProject = Project::factory()->create();
    $user = createAuditAdmin($project);

    AuditLog::factory()->create(['project_id' => $project->id]);
    AuditLog::factory()->create(['project_id' => $otherProject->id]);

    $this->actingAs($user)
        ->getJson("/api/v1/audit-logs?project_id={$project->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.project_id', $project->id);
});

it('filters audit logs by date range', function () {
    $project = Project::factory()->enabled()->create();
    $user = createAuditAdmin($project);

    AuditLog::factory()->create([
        'user_id' => $user->id,
        'created_at' => '2026-02-01 12:00:00',
    ]);
    AuditLog::factory()->create([
        'user_id' => $user->id,
        'created_at' => '2026-02-10 12:00:00',
    ]);
    AuditLog::factory()->create([
        'user_id' => $user->id,
        'created_at' => '2026-02-20 12:00:00',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/audit-logs?date_from=2026-02-05&date_to=2026-02-15')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('paginates audit logs with cursor', function () {
    $project = Project::factory()->enabled()->create();
    $user = createAuditAdmin($project);

    AuditLog::factory()->count(5)->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/audit-logs?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $cursor = $response->json('meta.next_cursor');
    expect($cursor)->not->toBeNull();

    $this->actingAs($user)
        ->getJson("/api/v1/audit-logs?per_page=2&cursor={$cursor}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

// ─── GET /api/v1/audit-logs/{id} ─────────────────────────────────

it('returns a single audit log entry for admin', function () {
    $project = Project::factory()->enabled()->create();
    $user = createAuditAdmin($project);

    $log = AuditLog::factory()->conversationTurn()->create([
        'user_id' => $user->id,
        'conversation_id' => 'conv-test-123',
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/audit-logs/{$log->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $log->id)
        ->assertJsonPath('data.event_type', 'conversation_turn')
        ->assertJsonPath('data.conversation_id', 'conv-test-123')
        ->assertJsonStructure(['data' => ['id', 'event_type', 'summary', 'properties', 'created_at']]);
});

it('returns 403 for non-admin on audit log show', function () {
    $project = Project::factory()->enabled()->create();
    $user = createAuditRegularUser($project);

    $log = AuditLog::factory()->create();

    $this->actingAs($user)
        ->getJson("/api/v1/audit-logs/{$log->id}")
        ->assertForbidden();
});

it('returns 404 for non-existent audit log', function () {
    $project = Project::factory()->enabled()->create();
    $user = createAuditAdmin($project);

    $this->actingAs($user)
        ->getJson('/api/v1/audit-logs/99999')
        ->assertNotFound();
});

<?php

use App\Models\DeadLetterEntry;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
function createDlqAdmin(Project $project): User
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
function createDlqRegularUser(Project $project): User
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

/**
 * Helper: create a DLQ entry with a real task for retry testing.
 */
function createDlqEntryWithTask(Project $project, array $overrides = []): DeadLetterEntry
{
    $task = Task::factory()->failed()->create(['project_id' => $project->id]);

    return DeadLetterEntry::factory()->create(array_merge([
        'task_id' => $task->id,
        'task_record' => $task->toArray(),
    ], $overrides));
}

// ─── GET /api/v1/admin/dead-letter ─────────────────────────────

it('returns 401 for unauthenticated users on DLQ index', function (): void {
    $this->getJson('/api/v1/admin/dead-letter')
        ->assertUnauthorized();
});

it('returns 403 for non-admin users on DLQ index', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createDlqRegularUser($project);

    $this->actingAs($user)
        ->getJson('/api/v1/admin/dead-letter')
        ->assertForbidden();
});

it('returns active DLQ entries for admin users', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createDlqAdmin($project);

    $entry1 = createDlqEntryWithTask($project, [
        'failure_reason' => 'max_retries_exceeded',
        'dead_lettered_at' => now()->subMinutes(10),
    ]);

    $entry2 = createDlqEntryWithTask($project, [
        'failure_reason' => 'expired',
        'dead_lettered_at' => now(),
    ]);

    // Dismissed entry — should NOT appear
    createDlqEntryWithTask($project, [
        'dismissed' => true,
        'dismissed_at' => now(),
        'dismissed_by' => $user->id,
    ]);

    // Retried entry — should NOT appear
    createDlqEntryWithTask($project, [
        'retried' => true,
        'retried_at' => now(),
        'retried_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/admin/dead-letter')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $entry2->id)  // most recent first
        ->assertJsonPath('data.1.id', $entry1->id);
});

it('returns empty data array when no DLQ entries exist', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createDlqAdmin($project);

    $this->actingAs($user)
        ->getJson('/api/v1/admin/dead-letter')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('filters DLQ entries by failure_reason', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createDlqAdmin($project);

    createDlqEntryWithTask($project, ['failure_reason' => 'expired']);
    createDlqEntryWithTask($project, ['failure_reason' => 'max_retries_exceeded']);

    $this->actingAs($user)
        ->getJson('/api/v1/admin/dead-letter?reason=expired')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.failure_reason', 'expired');
});

// ─── GET /api/v1/admin/dead-letter/{id} ────────────────────────

it('returns single DLQ entry with relationships for admin', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createDlqAdmin($project);

    $entry = createDlqEntryWithTask($project);

    $this->actingAs($user)
        ->getJson("/api/v1/admin/dead-letter/{$entry->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $entry->id)
        ->assertJsonPath('data.failure_reason', $entry->failure_reason);
});

it('returns 404 for non-existent DLQ entry', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createDlqAdmin($project);

    $this->actingAs($user)
        ->getJson('/api/v1/admin/dead-letter/99999')
        ->assertNotFound();
});

// ─── POST /api/v1/admin/dead-letter/{id}/retry ─────────────────

it('returns 401 for unauthenticated users on DLQ retry', function (): void {
    $project = Project::factory()->enabled()->create();
    $entry = createDlqEntryWithTask($project);

    $this->postJson("/api/v1/admin/dead-letter/{$entry->id}/retry")
        ->assertUnauthorized();
});

it('returns 403 for non-admin users on DLQ retry', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createDlqRegularUser($project);
    $entry = createDlqEntryWithTask($project);

    $this->actingAs($user)
        ->postJson("/api/v1/admin/dead-letter/{$entry->id}/retry")
        ->assertForbidden();
});

it('retries a DLQ entry and returns new task', function (): void {
    Queue::fake();

    $project = Project::factory()->enabled()->create();
    $user = createDlqAdmin($project);
    $entry = createDlqEntryWithTask($project);

    $this->actingAs($user)
        ->postJson("/api/v1/admin/dead-letter/{$entry->id}/retry")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['success', 'data' => ['id', 'type', 'status']]);

    $entry->refresh();
    expect($entry->retried)->toBeTrue();
    expect($entry->retried_at)->not->toBeNull();
    expect($entry->retried_by)->toBe($user->id);
    expect($entry->retried_task_id)->not->toBeNull();
});

it('returns 422 when retrying an already-retried DLQ entry', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createDlqAdmin($project);
    $entry = createDlqEntryWithTask($project, [
        'retried' => true,
        'retried_at' => now(),
        'retried_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/admin/dead-letter/{$entry->id}/retry")
        ->assertUnprocessable()
        ->assertJsonPath('error', 'This DLQ entry has already been retried.');
});

// ─── POST /api/v1/admin/dead-letter/{id}/dismiss ───────────────

it('returns 401 for unauthenticated users on DLQ dismiss', function (): void {
    $project = Project::factory()->enabled()->create();
    $entry = createDlqEntryWithTask($project);

    $this->postJson("/api/v1/admin/dead-letter/{$entry->id}/dismiss")
        ->assertUnauthorized();
});

it('returns 403 for non-admin users on DLQ dismiss', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createDlqRegularUser($project);
    $entry = createDlqEntryWithTask($project);

    $this->actingAs($user)
        ->postJson("/api/v1/admin/dead-letter/{$entry->id}/dismiss")
        ->assertForbidden();
});

it('dismisses a DLQ entry for admin users', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createDlqAdmin($project);
    $entry = createDlqEntryWithTask($project);

    $this->actingAs($user)
        ->postJson("/api/v1/admin/dead-letter/{$entry->id}/dismiss")
        ->assertOk()
        ->assertJsonPath('success', true);

    $entry->refresh();
    expect($entry->dismissed)->toBeTrue();
    expect($entry->dismissed_at)->not->toBeNull();
    expect($entry->dismissed_by)->toBe($user->id);
});

it('returns 422 when dismissing an already-dismissed DLQ entry', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createDlqAdmin($project);
    $entry = createDlqEntryWithTask($project, [
        'dismissed' => true,
        'dismissed_at' => now(),
        'dismissed_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/admin/dead-letter/{$entry->id}/dismiss")
        ->assertUnprocessable()
        ->assertJsonPath('error', 'This DLQ entry has already been dismissed.');
});

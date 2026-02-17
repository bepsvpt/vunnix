<?php

use App\Enums\TaskOrigin;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\Task;
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

// ─── Tests ─────────────────────────────────────────────────────

it('returns designer activity stats scoped to user projects', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $otherProject = Project::factory()->enabled()->create();

    // Completed UI adjustment in user's project — first attempt (retry_count = 0)
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::UiAdjustment,
        'origin' => TaskOrigin::Conversation,
        'status' => TaskStatus::Completed,
        'mr_iid' => 10,
        'retry_count' => 0,
    ]);

    // Completed UI adjustment — retried once (retry_count = 1)
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::UiAdjustment,
        'origin' => TaskOrigin::Webhook,
        'status' => TaskStatus::Completed,
        'mr_iid' => 11,
        'retry_count' => 1,
    ]);

    // Task in other project — should NOT appear
    Task::factory()->create([
        'project_id' => $otherProject->id,
        'type' => TaskType::UiAdjustment,
        'origin' => TaskOrigin::Conversation,
        'status' => TaskStatus::Completed,
        'mr_iid' => 99,
        'retry_count' => 0,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/designer-activity');

    $response->assertOk();
    $response->assertJsonPath('data.ui_adjustments_dispatched', 2);
    // avg iterations: (0+1 + 1+1) / 2 = (1 + 2) / 2 = 1.5
    $response->assertJsonPath('data.avg_iterations', 1.5);
    // Only the conversation-originated task has mr_iid and origin=Conversation
    $response->assertJsonPath('data.mrs_created_from_chat', 1);
    // 1 out of 2 had retry_count = 0 → 50%
    $response->assertJsonPath('data.first_attempt_success_rate', 50);
});

it('returns correct response structure', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/designer-activity');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'ui_adjustments_dispatched',
            'avg_iterations',
            'mrs_created_from_chat',
            'first_attempt_success_rate',
        ],
    ]);
});

it('returns null metrics when no UI adjustments exist', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/designer-activity');

    $response->assertOk();
    $response->assertJsonPath('data.ui_adjustments_dispatched', 0);
    $response->assertJsonPath('data.avg_iterations', null);
    $response->assertJsonPath('data.mrs_created_from_chat', 0);
    $response->assertJsonPath('data.first_attempt_success_rate', null);
});

it('returns 401 for unauthenticated users', function (): void {
    $response = $this->getJson('/api/v1/dashboard/designer-activity');
    $response->assertUnauthorized();
});

it('excludes tasks from disabled projects', function (): void {
    $user = User::factory()->create();
    $disabledProject = Project::factory()->create(['enabled' => false]);
    $disabledProject->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->create([
        'project_id' => $disabledProject->id,
        'type' => TaskType::UiAdjustment,
        'origin' => TaskOrigin::Conversation,
        'status' => TaskStatus::Completed,
        'mr_iid' => 1,
        'retry_count' => 0,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/designer-activity');

    $response->assertOk();
    $response->assertJsonPath('data.ui_adjustments_dispatched', 0);
    $response->assertJsonPath('data.mrs_created_from_chat', 0);
});

it('excludes non-UiAdjustment tasks', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    // Code review task — should not count as designer activity
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'origin' => TaskOrigin::Webhook,
        'status' => TaskStatus::Completed,
        'retry_count' => 0,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/designer-activity');

    $response->assertOk();
    $response->assertJsonPath('data.ui_adjustments_dispatched', 0);
});

it('excludes failed UI adjustment tasks', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::UiAdjustment,
        'origin' => TaskOrigin::Conversation,
        'status' => TaskStatus::Failed,
        'mr_iid' => 1,
        'retry_count' => 2,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/designer-activity');

    $response->assertOk();
    $response->assertJsonPath('data.ui_adjustments_dispatched', 0);
    $response->assertJsonPath('data.avg_iterations', null);
    $response->assertJsonPath('data.first_attempt_success_rate', null);
});

it('computes 100% first-attempt success when all tasks have zero retries', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->count(3)->create([
        'project_id' => $project->id,
        'type' => TaskType::UiAdjustment,
        'origin' => TaskOrigin::Conversation,
        'status' => TaskStatus::Completed,
        'mr_iid' => null,
        'retry_count' => 0,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/designer-activity');

    $response->assertOk();
    $response->assertJsonPath('data.ui_adjustments_dispatched', 3);
    // avg iterations: all retry_count=0 → (1+1+1)/3 = 1.0 → JSON encodes as 1
    $response->assertJsonPath('data.avg_iterations', 1);
    // 3/3 = 100% → JSON encodes as 100
    $response->assertJsonPath('data.first_attempt_success_rate', 100);
});

it('counts only conversation-originated tasks with mr_iid for MRs from chat', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    // Webhook-originated with mr_iid — should NOT count as "from chat"
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::UiAdjustment,
        'origin' => TaskOrigin::Webhook,
        'status' => TaskStatus::Completed,
        'mr_iid' => 5,
        'retry_count' => 0,
    ]);

    // Conversation-originated without mr_iid — should NOT count
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::UiAdjustment,
        'origin' => TaskOrigin::Conversation,
        'status' => TaskStatus::Completed,
        'mr_iid' => null,
        'retry_count' => 0,
    ]);

    // Conversation-originated with mr_iid — SHOULD count
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::UiAdjustment,
        'origin' => TaskOrigin::Conversation,
        'status' => TaskStatus::Completed,
        'mr_iid' => 10,
        'retry_count' => 0,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/designer-activity');

    $response->assertOk();
    $response->assertJsonPath('data.ui_adjustments_dispatched', 3);
    $response->assertJsonPath('data.mrs_created_from_chat', 1);
});

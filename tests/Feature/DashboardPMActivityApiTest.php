<?php

use App\Enums\TaskOrigin;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// ─── Setup ─────────────────────────────────────────────────────

beforeEach(function () {
    // The SDK migration creates agent_conversations and agent_conversation_messages,
    // but our custom columns (project_id, archived_at) are added by PostgreSQL-only
    // migrations that skip on SQLite. Create the tables with all columns if they
    // don't exist yet (SDK migration may have a 2026 timestamp sorting after ours).
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

// ─── Tests ─────────────────────────────────────────────────────

it('returns PM activity stats scoped to user projects', function () {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $otherProject = Project::factory()->enabled()->create();

    // Conversations in user's project
    Conversation::factory()->count(3)->forProject($project)->forUser($user)->create();

    // Conversation in other project — should NOT appear
    Conversation::factory()->forProject($otherProject)->create();

    // Completed PRD from conversation in user's project
    $prdConversation = Conversation::factory()->forProject($project)->forUser($user)->create();
    Message::factory()->count(8)->forConversation($prdConversation)->create();

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::PrdCreation,
        'origin' => TaskOrigin::Conversation,
        'status' => TaskStatus::Completed,
        'conversation_id' => $prdConversation->id,
        'issue_iid' => 42,
    ]);

    // Another completed issue from chat (not PRD)
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::FeatureDev,
        'origin' => TaskOrigin::Conversation,
        'status' => TaskStatus::Completed,
        'issue_iid' => 43,
        'conversation_id' => Conversation::factory()->forProject($project)->create()->id,
    ]);

    // Task in other project — should NOT appear
    Task::factory()->create([
        'project_id' => $otherProject->id,
        'type' => TaskType::PrdCreation,
        'origin' => TaskOrigin::Conversation,
        'status' => TaskStatus::Completed,
        'issue_iid' => 99,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/pm-activity');

    $response->assertOk();
    $response->assertJsonPath('data.prds_created', 1);
    // 3 conversations + prdConversation + feature dev conversation = 5
    $response->assertJsonPath('data.conversations_held', 5);
    // PRD task + feature dev task both have issue_iid and are from conversation
    $response->assertJsonPath('data.issues_from_chat', 2);
    // 8 messages in the single PRD conversation — JSON encodes 8.0 as integer 8
    $response->assertJsonPath('data.avg_turns_per_prd', 8);
});

it('returns correct response structure', function () {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/pm-activity');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'prds_created',
            'conversations_held',
            'issues_from_chat',
            'avg_turns_per_prd',
        ],
    ]);
});

it('returns null avg turns when no PRDs exist', function () {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/pm-activity');

    $response->assertOk();
    $response->assertJsonPath('data.prds_created', 0);
    $response->assertJsonPath('data.avg_turns_per_prd', null);
});

it('returns 401 for unauthenticated users', function () {
    $response = $this->getJson('/api/v1/dashboard/pm-activity');
    $response->assertUnauthorized();
});

it('excludes tasks from disabled projects', function () {
    $user = User::factory()->create();
    $disabledProject = Project::factory()->create(['enabled' => false]);
    $disabledProject->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->create([
        'project_id' => $disabledProject->id,
        'type' => TaskType::PrdCreation,
        'origin' => TaskOrigin::Conversation,
        'status' => TaskStatus::Completed,
        'issue_iid' => 1,
    ]);

    Conversation::factory()->forProject($disabledProject)->create();

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/pm-activity');

    $response->assertOk();
    $response->assertJsonPath('data.prds_created', 0);
    $response->assertJsonPath('data.conversations_held', 0);
    $response->assertJsonPath('data.issues_from_chat', 0);
});

it('excludes webhook-origin tasks from PM metrics', function () {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    // Webhook-originated PRD task — should NOT be counted as PM activity
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::PrdCreation,
        'origin' => TaskOrigin::Webhook,
        'status' => TaskStatus::Completed,
        'issue_iid' => 1,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/pm-activity');

    $response->assertOk();
    $response->assertJsonPath('data.prds_created', 0);
});

it('excludes failed PRD tasks from count and avg turns', function () {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $conv = Conversation::factory()->forProject($project)->forUser($user)->create();
    Message::factory()->count(5)->forConversation($conv)->create();

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::PrdCreation,
        'origin' => TaskOrigin::Conversation,
        'status' => TaskStatus::Failed,
        'conversation_id' => $conv->id,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/pm-activity');

    $response->assertOk();
    $response->assertJsonPath('data.prds_created', 0);
    $response->assertJsonPath('data.avg_turns_per_prd', null);
});

it('computes avg turns across multiple PRD conversations', function () {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    // First PRD conversation — 6 messages
    $conv1 = Conversation::factory()->forProject($project)->forUser($user)->create();
    Message::factory()->count(6)->forConversation($conv1)->create();
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::PrdCreation,
        'origin' => TaskOrigin::Conversation,
        'status' => TaskStatus::Completed,
        'conversation_id' => $conv1->id,
        'issue_iid' => 10,
    ]);

    // Second PRD conversation — 10 messages
    $conv2 = Conversation::factory()->forProject($project)->forUser($user)->create();
    Message::factory()->count(10)->forConversation($conv2)->create();
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::PrdCreation,
        'origin' => TaskOrigin::Conversation,
        'status' => TaskStatus::Completed,
        'conversation_id' => $conv2->id,
        'issue_iid' => 11,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/pm-activity');

    $response->assertOk();
    $response->assertJsonPath('data.prds_created', 2);
    // (6 + 10) / 2 = 8.0 — JSON encodes as integer 8
    $response->assertJsonPath('data.avg_turns_per_prd', 8);
});

it('handles zero conversations gracefully', function () {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/pm-activity');

    $response->assertOk();
    $response->assertJsonPath('data.prds_created', 0);
    $response->assertJsonPath('data.conversations_held', 0);
    $response->assertJsonPath('data.issues_from_chat', 0);
    $response->assertJsonPath('data.avg_turns_per_prd', null);
});

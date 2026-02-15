<?php

use App\Enums\TaskOrigin;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\Task;
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
 * Helper: create a user with access to a project.
 */
function createUserWithProject(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    return $user;
}

// ─── Authentication ──────────────────────────────────────────

it('returns 401 for unauthenticated users', function () {
    $response = $this->getJson('/api/v1/dashboard/adoption');
    $response->assertUnauthorized();
});

it('allows authenticated users to access adoption data', function () {
    $project = Project::factory()->enabled()->create();
    $user = createUserWithProject($project);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/adoption');
    $response->assertOk();
});

// ─── Response Structure ───────────────────────────────────────

it('returns correct response structure', function () {
    $project = Project::factory()->enabled()->create();
    $user = createUserWithProject($project);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/adoption');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'ai_reviewed_mr_percent',
            'reviewed_mr_count',
            'total_mr_count',
            'chat_active_users',
            'tasks_by_type_over_time',
            'ai_mentions_per_week',
        ],
    ]);
});

it('returns empty data when no tasks exist', function () {
    $project = Project::factory()->enabled()->create();
    $user = createUserWithProject($project);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/adoption');

    $response->assertOk();
    $response->assertJsonPath('data.ai_reviewed_mr_percent', null);
    $response->assertJsonPath('data.reviewed_mr_count', 0);
    $response->assertJsonPath('data.total_mr_count', 0);
    $response->assertJsonPath('data.chat_active_users', 0);
    $response->assertJsonPath('data.tasks_by_type_over_time', []);
    $response->assertJsonPath('data.ai_mentions_per_week', []);
});

// ─── AI-Reviewed MR % ────────────────────────────────────────

it('calculates AI-reviewed MR percentage correctly', function () {
    $project = Project::factory()->enabled()->create();
    $user = createUserWithProject($project);

    // 2 MRs with completed code reviews
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'mr_iid' => 1,
    ]);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'mr_iid' => 2,
    ]);

    // 1 MR with only a feature dev task (not a code review)
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Completed,
        'mr_iid' => 3,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/adoption');

    $response->assertOk();
    // 2 reviewed MRs out of 3 total MRs = 66.7%
    $response->assertJsonPath('data.ai_reviewed_mr_percent', 66.7);
    $response->assertJsonPath('data.reviewed_mr_count', 2);
    $response->assertJsonPath('data.total_mr_count', 3);
});

it('counts distinct MRs not duplicate tasks on same MR', function () {
    $project = Project::factory()->enabled()->create();
    $user = createUserWithProject($project);

    // Two code review tasks on the same MR (e.g., incremental review)
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'mr_iid' => 1,
    ]);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'mr_iid' => 1,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/adoption');

    $response->assertOk();
    // Only 1 distinct MR, 100% reviewed
    // JSON encodes round(100.0) as integer 100 — use int per Learnings
    $response->assertJsonPath('data.ai_reviewed_mr_percent', 100);
    $response->assertJsonPath('data.reviewed_mr_count', 1);
    $response->assertJsonPath('data.total_mr_count', 1);
});

it('returns null MR percent when no MRs exist', function () {
    $project = Project::factory()->enabled()->create();
    $user = createUserWithProject($project);

    // Task without mr_iid (e.g., issue discussion)
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::IssueDiscussion,
        'status' => TaskStatus::Completed,
        'mr_iid' => null,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/adoption');

    $response->assertOk();
    $response->assertJsonPath('data.ai_reviewed_mr_percent', null);
    $response->assertJsonPath('data.total_mr_count', 0);
});

// ─── Chat Active Users ───────────────────────────────────────

it('counts distinct chat active users', function () {
    $project = Project::factory()->enabled()->create();
    $user = createUserWithProject($project);
    $otherUser = User::factory()->create();

    Conversation::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'user_id' => $user->id,
        'project_id' => $project->id,
        'title' => 'Conversation 1',
    ]);

    Conversation::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'user_id' => $user->id,
        'project_id' => $project->id,
        'title' => 'Conversation 2',
    ]);

    Conversation::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'user_id' => $otherUser->id,
        'project_id' => $project->id,
        'title' => 'Conversation 3',
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/adoption');

    $response->assertOk();
    // 2 distinct users: $user (2 conversations) + $otherUser (1 conversation)
    $response->assertJsonPath('data.chat_active_users', 2);
});

// ─── Tasks by Type Over Time ─────────────────────────────────

it('returns tasks grouped by month and type', function () {
    $project = Project::factory()->enabled()->create();
    $user = createUserWithProject($project);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'created_at' => now()->startOfMonth(),
    ]);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'created_at' => now()->startOfMonth(),
    ]);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Completed,
        'created_at' => now()->startOfMonth(),
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/adoption');

    $response->assertOk();
    $tasksOverTime = $response->json('data.tasks_by_type_over_time');
    $currentMonth = now()->format('Y-m');
    expect($tasksOverTime)->toHaveKey($currentMonth);
    expect($tasksOverTime[$currentMonth]['code_review'])->toBe(2);
    expect($tasksOverTime[$currentMonth]['feature_dev'])->toBe(1);
});

it('excludes non-terminal tasks from type over time', function () {
    $project = Project::factory()->enabled()->create();
    $user = createUserWithProject($project);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'created_at' => now()->startOfMonth(),
    ]);

    // Queued task — should be excluded
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Queued,
        'created_at' => now()->startOfMonth(),
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/adoption');

    $response->assertOk();
    $tasksOverTime = $response->json('data.tasks_by_type_over_time');
    $currentMonth = now()->format('Y-m');
    expect($tasksOverTime[$currentMonth]['code_review'])->toBe(1);
});

// ─── @ai Mentions per Week ───────────────────────────────────

it('returns webhook-originated tasks grouped by week', function () {
    $project = Project::factory()->enabled()->create();
    $user = createUserWithProject($project);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'origin' => TaskOrigin::Webhook,
        'status' => TaskStatus::Completed,
        'created_at' => now(),
    ]);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::IssueDiscussion,
        'origin' => TaskOrigin::Webhook,
        'status' => TaskStatus::Completed,
        'created_at' => now(),
    ]);

    // Conversation-originated task — should NOT be counted as @ai mention
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::PrdCreation,
        'origin' => TaskOrigin::Conversation,
        'status' => TaskStatus::Completed,
        'created_at' => now(),
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/adoption');

    $response->assertOk();
    $mentions = $response->json('data.ai_mentions_per_week');
    expect($mentions)->toHaveCount(1);
    expect($mentions[0]['count'])->toBe(2);
});

// ─── Project Scoping ──────────────────────────────────────────

it('excludes tasks from projects the user does not have access to', function () {
    $project = Project::factory()->enabled()->create();
    $otherProject = Project::factory()->enabled()->create();
    $user = createUserWithProject($project);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'mr_iid' => 1,
    ]);

    // Task in other project — user has no access
    Task::factory()->create([
        'project_id' => $otherProject->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'mr_iid' => 2,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/adoption');

    $response->assertOk();
    $response->assertJsonPath('data.reviewed_mr_count', 1);
    $response->assertJsonPath('data.total_mr_count', 1);
});

it('excludes tasks from disabled projects', function () {
    $project = Project::factory()->enabled()->create();
    $disabledProject = Project::factory()->create(['enabled' => false]);
    $user = createUserWithProject($project);
    $disabledProject->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'mr_iid' => 1,
    ]);

    Task::factory()->create([
        'project_id' => $disabledProject->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'mr_iid' => 2,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/adoption');

    $response->assertOk();
    $response->assertJsonPath('data.reviewed_mr_count', 1);
    $response->assertJsonPath('data.total_mr_count', 1);
});

it('excludes conversations from other projects in chat active users', function () {
    $project = Project::factory()->enabled()->create();
    $otherProject = Project::factory()->enabled()->create();
    $user = createUserWithProject($project);
    $otherUser = User::factory()->create();

    Conversation::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'user_id' => $user->id,
        'project_id' => $project->id,
        'title' => 'My conversation',
    ]);

    // Conversation in other project — should be excluded
    Conversation::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'user_id' => $otherUser->id,
        'project_id' => $otherProject->id,
        'title' => 'Other conversation',
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/adoption');

    $response->assertOk();
    $response->assertJsonPath('data.chat_active_users', 1);
});

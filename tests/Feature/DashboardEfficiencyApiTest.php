<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
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

// ─── Tests ─────────────────────────────────────────────────────

it('returns efficiency stats scoped to user projects', function () {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $otherProject = Project::factory()->enabled()->create();

    $baseTime = now()->subHour();

    // Completed code review — 2 min to start, 5 min total
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'created_at' => $baseTime,
        'started_at' => $baseTime->copy()->addMinutes(2),
        'completed_at' => $baseTime->copy()->addMinutes(5),
    ]);

    // Completed code review — 4 min to start, 10 min total
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'created_at' => $baseTime,
        'started_at' => $baseTime->copy()->addMinutes(4),
        'completed_at' => $baseTime->copy()->addMinutes(10),
    ]);

    // Code review in other project — should NOT appear
    Task::factory()->create([
        'project_id' => $otherProject->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'created_at' => $baseTime,
        'started_at' => $baseTime->copy()->addMinutes(100),
        'completed_at' => $baseTime->copy()->addMinutes(200),
    ]);

    // Completed feature dev — for completion rate
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Completed,
    ]);

    // Failed feature dev — for completion rate
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Failed,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/efficiency');

    $response->assertOk();
    // avg time to first review: (2 + 4) / 2 = 3.0 min
    $response->assertJsonPath('data.time_to_first_review', 3);
    // avg review turnaround: (5 + 10) / 2 = 7.5 min
    $response->assertJsonPath('data.review_turnaround', 7.5);
    // code_review: 2/2 = 100%, feature_dev: 1/2 = 50%
    $response->assertJsonPath('data.completion_rate_by_type.code_review', 100);
    $response->assertJsonPath('data.completion_rate_by_type.feature_dev', 50);
});

it('returns correct response structure', function () {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/efficiency');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'time_to_first_review',
            'review_turnaround',
            'completion_rate_by_type',
        ],
    ]);
});

it('returns null time metrics when no completed reviews exist', function () {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/efficiency');

    $response->assertOk();
    $response->assertJsonPath('data.time_to_first_review', null);
    $response->assertJsonPath('data.review_turnaround', null);
    $response->assertJsonPath('data.completion_rate_by_type', []);
});

it('returns 401 for unauthenticated users', function () {
    $response = $this->getJson('/api/v1/dashboard/efficiency');
    $response->assertUnauthorized();
});

it('excludes tasks from disabled projects', function () {
    $user = User::factory()->create();
    $disabledProject = Project::factory()->create(['enabled' => false]);
    $disabledProject->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->create([
        'project_id' => $disabledProject->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'created_at' => now()->subMinutes(10),
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/efficiency');

    $response->assertOk();
    $response->assertJsonPath('data.time_to_first_review', null);
    $response->assertJsonPath('data.review_turnaround', null);
    $response->assertJsonPath('data.completion_rate_by_type', []);
});

it('only includes completed code reviews for time metrics', function () {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $baseTime = now()->subHour();

    // Failed code review — should NOT be included in time metrics
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Failed,
        'created_at' => $baseTime,
        'started_at' => $baseTime->copy()->addMinutes(1),
        'completed_at' => null,
    ]);

    // Completed feature dev — should NOT be included in time metrics (wrong type)
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Completed,
        'created_at' => $baseTime,
        'started_at' => $baseTime->copy()->addMinutes(50),
        'completed_at' => $baseTime->copy()->addMinutes(100),
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/efficiency');

    $response->assertOk();
    $response->assertJsonPath('data.time_to_first_review', null);
    $response->assertJsonPath('data.review_turnaround', null);
    // feature_dev: 1/1 = 100%, code_review failed so 0/1 = 0%
    $response->assertJsonPath('data.completion_rate_by_type.feature_dev', 100);
    $response->assertJsonPath('data.completion_rate_by_type.code_review', 0);
});

it('computes completion rate for multiple types', function () {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    // 3 completed code reviews, 1 failed
    Task::factory()->count(3)->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Failed,
    ]);

    // 1 completed UI adjustment
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::UiAdjustment,
        'status' => TaskStatus::Completed,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/efficiency');

    $response->assertOk();
    // code_review: 3/4 = 75%
    $response->assertJsonPath('data.completion_rate_by_type.code_review', 75);
    // ui_adjustment: 1/1 = 100%
    $response->assertJsonPath('data.completion_rate_by_type.ui_adjustment', 100);
});

it('excludes superseded tasks from completion rate', function () {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    // Superseded tasks are not terminal in the completed/failed sense
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Superseded,
    ]);

    // Queued/running tasks should also not appear
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Running,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/efficiency');

    $response->assertOk();
    $response->assertJsonPath('data.completion_rate_by_type', []);
});

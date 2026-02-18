<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns quality stats scoped to user projects', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $otherProject = Project::factory()->enabled()->create();

    // Completed code review with result data in user's project
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'result' => [
            'summary' => [
                'total_findings' => 5,
                'findings_by_severity' => ['critical' => 1, 'major' => 2, 'minor' => 2],
            ],
        ],
    ]);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'result' => [
            'summary' => [
                'total_findings' => 3,
                'findings_by_severity' => ['critical' => 0, 'major' => 1, 'minor' => 2],
            ],
        ],
    ]);

    // Task in other project — should NOT appear
    Task::factory()->create([
        'project_id' => $otherProject->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'result' => [
            'summary' => [
                'total_findings' => 10,
                'findings_by_severity' => ['critical' => 5, 'major' => 3, 'minor' => 2],
            ],
        ],
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/quality');

    $response->assertOk();
    $response->assertJsonPath('data.total_reviews', 2);
    $response->assertJsonPath('data.total_findings', 8);
    $response->assertJsonPath('data.severity_distribution.critical', 1);
    $response->assertJsonPath('data.severity_distribution.major', 3);
    $response->assertJsonPath('data.severity_distribution.minor', 4);
    $response->assertJsonPath('data.avg_findings_per_review', 4);
});

it('returns correct response structure', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/quality');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'acceptance_rate',
            'severity_distribution' => ['critical', 'major', 'minor'],
            'total_findings',
            'total_reviews',
            'avg_findings_per_review',
        ],
    ]);
});

it('returns null acceptance rate (not yet tracked)', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/quality');

    $response->assertOk();
    $response->assertJsonPath('data.acceptance_rate', null);
});

it('returns null avg findings when no reviews exist', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/quality');

    $response->assertOk();
    $response->assertJsonPath('data.total_reviews', 0);
    $response->assertJsonPath('data.avg_findings_per_review', null);
});

it('returns 401 for unauthenticated users', function (): void {
    $response = $this->getJson('/api/v1/dashboard/quality');
    $response->assertUnauthorized();
});

it('excludes tasks from disabled projects', function (): void {
    $user = User::factory()->create();
    $disabledProject = Project::factory()->create(['enabled' => false]);
    $disabledProject->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->create([
        'project_id' => $disabledProject->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'result' => [
            'summary' => [
                'total_findings' => 5,
                'findings_by_severity' => ['critical' => 1, 'major' => 2, 'minor' => 2],
            ],
        ],
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/quality');

    $response->assertOk();
    $response->assertJsonPath('data.total_reviews', 0);
    $response->assertJsonPath('data.total_findings', 0);
});

it('excludes non-code-review tasks from quality metrics', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    // Feature dev task — should NOT be counted
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Completed,
        'result' => ['summary' => ['total_findings' => 0]],
    ]);

    // Incomplete code review — should NOT be counted
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Failed,
        'result' => null,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/quality');

    $response->assertOk();
    $response->assertJsonPath('data.total_reviews', 0);
    $response->assertJsonPath('data.total_findings', 0);
});

it('handles reviews with zero findings', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'result' => [
            'summary' => [
                'total_findings' => 0,
                'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 0],
            ],
        ],
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/quality');

    $response->assertOk();
    $response->assertJsonPath('data.total_reviews', 1);
    $response->assertJsonPath('data.total_findings', 0);
    $response->assertJsonPath('data.avg_findings_per_review', 0);
    $response->assertJsonPath('data.severity_distribution.critical', 0);
    $response->assertJsonPath('data.severity_distribution.major', 0);
    $response->assertJsonPath('data.severity_distribution.minor', 0);
});

// ─── Materialized View / task_metrics Path ────────────────────

it('uses pre-aggregated metrics from task_metrics when available', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $task = Task::factory()->create(['project_id' => $project->id]);

    // Seed task_metrics with code_review data — triggers the materialized view path
    // because MetricsQueryService::byType() returns non-empty with a code_review row
    DB::table('task_metrics')->insert([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'task_type' => 'code_review',
        'input_tokens' => 10000,
        'output_tokens' => 5000,
        'cost' => 1.50,
        'duration' => 45,
        'severity_critical' => 3,
        'severity_high' => 5,
        'severity_medium' => 8,
        'severity_low' => 2,
        'findings_count' => 18,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Refresh materialized views so PostgreSQL CI sees the seeded data
    $this->artisan('metrics:aggregate');

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/quality');

    $response->assertOk();
    $response->assertJsonPath('data.severity_distribution.critical', 3);
    $response->assertJsonPath('data.severity_distribution.major', 5);
    $response->assertJsonPath('data.severity_distribution.minor', 8);
    $response->assertJsonPath('data.total_findings', 18);
    $response->assertJsonPath('data.total_reviews', 1);
    expect((float) $response->json('data.avg_findings_per_review'))->toEqual(18.0);
});

it('uses pre-aggregated metrics across multiple code review task_metrics rows', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $task1 = Task::factory()->create(['project_id' => $project->id]);
    $task2 = Task::factory()->create(['project_id' => $project->id]);

    DB::table('task_metrics')->insert([
        [
            'task_id' => $task1->id,
            'project_id' => $project->id,
            'task_type' => 'code_review',
            'input_tokens' => 5000,
            'output_tokens' => 2000,
            'cost' => 1.00,
            'duration' => 30,
            'severity_critical' => 2,
            'severity_high' => 3,
            'severity_medium' => 4,
            'severity_low' => 1,
            'findings_count' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'task_id' => $task2->id,
            'project_id' => $project->id,
            'task_type' => 'code_review',
            'input_tokens' => 8000,
            'output_tokens' => 3000,
            'cost' => 2.00,
            'duration' => 60,
            'severity_critical' => 1,
            'severity_high' => 2,
            'severity_medium' => 5,
            'severity_low' => 3,
            'findings_count' => 11,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // Refresh materialized views so PostgreSQL CI sees the seeded data
    $this->artisan('metrics:aggregate');

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/quality');

    $response->assertOk();
    // Aggregated: critical=2+1=3, major(high)=3+2=5, minor(medium)=4+5=9
    $response->assertJsonPath('data.severity_distribution.critical', 3);
    $response->assertJsonPath('data.severity_distribution.major', 5);
    $response->assertJsonPath('data.severity_distribution.minor', 9);
    $response->assertJsonPath('data.total_findings', 21);
    $response->assertJsonPath('data.total_reviews', 2);
    $response->assertJsonPath('data.avg_findings_per_review', 10.5);
});

// ─── Prompt Version Filter ────────────────────────────────────

it('filters quality metrics by prompt_version skill', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    // Task with matching prompt version
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'review-v2', 'claude_md' => 'v1', 'schema' => 'v1'],
        'result' => [
            'summary' => [
                'total_findings' => 4,
                'findings_by_severity' => ['critical' => 1, 'major' => 1, 'minor' => 2],
            ],
        ],
    ]);

    // Task with different prompt version — should be excluded
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'review-v1', 'claude_md' => 'v1', 'schema' => 'v1'],
        'result' => [
            'summary' => [
                'total_findings' => 10,
                'findings_by_severity' => ['critical' => 5, 'major' => 3, 'minor' => 2],
            ],
        ],
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/quality?prompt_version=review-v2');

    $response->assertOk();
    $response->assertJsonPath('data.total_reviews', 1);
    $response->assertJsonPath('data.total_findings', 4);
    $response->assertJsonPath('data.severity_distribution.critical', 1);
    $response->assertJsonPath('data.severity_distribution.major', 1);
    $response->assertJsonPath('data.severity_distribution.minor', 2);
});

it('skips materialized view path when prompt_version filter is active', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'review-v2', 'claude_md' => 'v1', 'schema' => 'v1'],
        'result' => [
            'summary' => [
                'total_findings' => 3,
                'findings_by_severity' => ['critical' => 1, 'major' => 1, 'minor' => 1],
            ],
        ],
    ]);

    // Seed task_metrics — would normally trigger materialized view path,
    // but prompt_version filter forces the live query path
    DB::table('task_metrics')->insert([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'task_type' => 'code_review',
        'input_tokens' => 10000,
        'output_tokens' => 5000,
        'cost' => 1.50,
        'duration' => 45,
        'severity_critical' => 99,
        'severity_high' => 99,
        'severity_medium' => 99,
        'severity_low' => 99,
        'findings_count' => 999,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/quality?prompt_version=review-v2');

    $response->assertOk();
    // Should use live query path (ignoring the inflated task_metrics data)
    $response->assertJsonPath('data.total_reviews', 1);
    $response->assertJsonPath('data.total_findings', 3);
    $response->assertJsonPath('data.severity_distribution.critical', 1);
    $response->assertJsonPath('data.severity_distribution.major', 1);
    $response->assertJsonPath('data.severity_distribution.minor', 1);
});

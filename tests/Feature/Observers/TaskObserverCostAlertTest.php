<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Events\TaskStatusChanged;
use App\Models\CostAlert;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([TaskStatusChanged::class]);
});

// ─── Single-task outlier fires on completion ─────────────────────────

test('creates cost alert when completed task cost exceeds 3x type average', function () {
    $project = \App\Models\Project::factory()->enabled()->create();

    // Seed 5 historical code_review metrics with avg cost $0.50
    for ($i = 0; $i < 5; $i++) {
        $historicalTask = Task::factory()->create([
            'project_id' => $project->id,
            'type' => TaskType::CodeReview,
            'status' => TaskStatus::Completed,
        ]);
        DB::table('task_metrics')->insert([
            'task_id' => $historicalTask->id,
            'project_id' => $project->id,
            'task_type' => 'code_review',
            'input_tokens' => 1000,
            'output_tokens' => 500,
            'cost' => 0.50,
            'duration' => 60,
            'severity_critical' => 0,
            'severity_high' => 0,
            'severity_medium' => 0,
            'severity_low' => 0,
            'findings_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Create a task with cost $2.00 (> 3 × $0.50 = $1.50)
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Running,
        'type' => TaskType::CodeReview,
        'input_tokens' => 5000,
        'output_tokens' => 10000,
        'cost' => 2.000000,
        'duration_seconds' => 300,
        'started_at' => now()->subMinutes(5),
        'result' => [
            'summary' => [
                'total_findings' => 0,
                'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 0],
            ],
        ],
    ]);

    $task->transitionTo(TaskStatus::Completed);

    // Should have created both a TaskMetric and a CostAlert
    expect(CostAlert::where('rule', 'single_task_outlier')->count())->toBe(1);

    $alert = CostAlert::where('rule', 'single_task_outlier')->first();
    expect($alert->severity)->toBe('warning')
        ->and($alert->context['task_id'])->toBe($task->id)
        ->and($alert->context['task_type'])->toBe('code_review');
});

test('does not create cost alert when task cost is within 3x type average', function () {
    $project = \App\Models\Project::factory()->enabled()->create();

    // Seed 5 historical code_review metrics with avg cost $0.50
    for ($i = 0; $i < 5; $i++) {
        $historicalTask = Task::factory()->create([
            'project_id' => $project->id,
            'type' => TaskType::CodeReview,
            'status' => TaskStatus::Completed,
        ]);
        DB::table('task_metrics')->insert([
            'task_id' => $historicalTask->id,
            'project_id' => $project->id,
            'task_type' => 'code_review',
            'input_tokens' => 1000,
            'output_tokens' => 500,
            'cost' => 0.50,
            'duration' => 60,
            'severity_critical' => 0,
            'severity_high' => 0,
            'severity_medium' => 0,
            'severity_low' => 0,
            'findings_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Create a task with cost $1.00 (< 3 × $0.50 = $1.50)
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Running,
        'type' => TaskType::CodeReview,
        'input_tokens' => 2000,
        'output_tokens' => 4000,
        'cost' => 1.000000,
        'duration_seconds' => 120,
        'started_at' => now()->subMinutes(2),
        'result' => [
            'summary' => [
                'total_findings' => 0,
                'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 0],
            ],
        ],
    ]);

    $task->transitionTo(TaskStatus::Completed);

    expect(CostAlert::where('rule', 'single_task_outlier')->count())->toBe(0);
});

test('does not create cost alert when task has zero cost', function () {
    $task = Task::factory()->create([
        'status' => TaskStatus::Running,
        'type' => TaskType::CodeReview,
        'cost' => 0,
        'duration_seconds' => 30,
    ]);

    $task->transitionTo(TaskStatus::Completed);

    expect(CostAlert::where('rule', 'single_task_outlier')->count())->toBe(0);
});

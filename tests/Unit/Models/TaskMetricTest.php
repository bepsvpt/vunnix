<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskMetric;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('defines a task BelongsTo relationship', function (): void {
    $metric = new TaskMetric;
    $relation = $metric->task();

    expect($relation)->toBeInstanceOf(BelongsTo::class);
    expect($relation->getRelated())->toBeInstanceOf(Task::class);
});

it('defines a project BelongsTo relationship', function (): void {
    $metric = new TaskMetric;
    $relation = $metric->project();

    expect($relation)->toBeInstanceOf(BelongsTo::class);
    expect($relation->getRelated())->toBeInstanceOf(Project::class);
});

it('loads task relationship from database', function (): void {
    $task = Task::factory()->create();

    $metric = TaskMetric::create([
        'task_id' => $task->id,
        'project_id' => $task->project_id,
        'task_type' => 'code_review',
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'cost' => 0.015000,
        'duration' => 120,
        'severity_critical' => 0,
        'severity_high' => 1,
        'severity_medium' => 3,
        'severity_low' => 5,
        'findings_count' => 9,
    ]);

    $loaded = TaskMetric::with('task')->find($metric->id);

    expect($loaded->task)->toBeInstanceOf(Task::class)
        ->and($loaded->task->id)->toBe($task->id);
});

it('loads project relationship from database', function (): void {
    $task = Task::factory()->create();

    $metric = TaskMetric::create([
        'task_id' => $task->id,
        'project_id' => $task->project_id,
        'task_type' => 'feature_dev',
        'input_tokens' => 2000,
        'output_tokens' => 1000,
        'cost' => 0.030000,
        'duration' => 300,
        'severity_critical' => 0,
        'severity_high' => 0,
        'severity_medium' => 0,
        'severity_low' => 0,
        'findings_count' => 0,
    ]);

    $loaded = TaskMetric::with('project')->find($metric->id);

    expect($loaded->project)->toBeInstanceOf(Project::class)
        ->and($loaded->project->id)->toBe($task->project_id);
});

<?php

use App\Models\Project;
use App\Models\Task;
use App\Services\MetricsQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function insertTaskMetric(Task $task, array $overrides = []): void
{
    DB::table('task_metrics')->insert(array_merge([
        'task_id' => $task->id,
        'project_id' => $task->project_id,
        'task_type' => $task->type->value,
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cost' => 0,
        'duration' => 0,
        'severity_critical' => 0,
        'severity_high' => 0,
        'severity_medium' => 0,
        'severity_low' => 0,
        'findings_count' => 0,
        'created_at' => now(),
    ], $overrides));
}

function refreshMetricsViews(array $views): void
{
    foreach ($views as $view) {
        DB::statement("REFRESH MATERIALIZED VIEW {$view}");
    }
}

it('returns empty collection for byProject with empty project IDs', function (): void {
    $service = new MetricsQueryService;

    expect($service->byProject(collect()))->toBeEmpty();
});

it('returns empty collection for byType with empty project IDs', function (): void {
    $service = new MetricsQueryService;

    expect($service->byType(collect()))->toBeEmpty();
});

it('returns empty collection for byPeriod with empty project IDs', function (): void {
    $service = new MetricsQueryService;

    expect($service->byPeriod(collect()))->toBeEmpty();
});

it('hasMaterializedViews returns true on PostgreSQL', function (): void {
    $service = new MetricsQueryService;

    expect($service->hasMaterializedViews())->toBeTrue();
});

it('byProject returns aggregated metrics from task_metrics table when materialized views are disabled', function (): void {
    $project = Project::factory()->enabled()->create();
    $task = Task::factory()->completed()->create([
        'project_id' => $project->id,
        'type' => 'code_review',
    ]);

    insertTaskMetric($task, [
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'cost' => 0.05,
        'duration' => 30,
        'severity_high' => 1,
        'severity_medium' => 2,
        'severity_low' => 3,
        'findings_count' => 6,
    ]);

    $service = Mockery::mock(MetricsQueryService::class)->makePartial();
    $service->shouldReceive('hasMaterializedViews')->andReturn(false);

    $result = $service->byProject(collect([$project->id]));

    expect($result)->toHaveCount(1);
    expect((int) $result->first()->task_count)->toBe(1);
    expect((int) $result->first()->total_input_tokens)->toBe(1000);
    expect((int) $result->first()->total_output_tokens)->toBe(500);
    expect((int) $result->first()->total_tokens)->toBe(1500);
});

it('byType returns aggregated metrics grouped by task type when materialized views are disabled', function (): void {
    $project = Project::factory()->enabled()->create();

    $task1 = Task::factory()->completed()->create([
        'project_id' => $project->id,
        'type' => 'code_review',
    ]);
    $task2 = Task::factory()->completed()->create([
        'project_id' => $project->id,
        'type' => 'feature_dev',
    ]);

    insertTaskMetric($task1, [
        'task_type' => 'code_review',
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'cost' => 0.05,
        'duration' => 30,
    ]);
    insertTaskMetric($task2, [
        'task_type' => 'feature_dev',
        'input_tokens' => 2000,
        'output_tokens' => 1000,
        'cost' => 0.10,
        'duration' => 60,
    ]);

    $service = Mockery::mock(MetricsQueryService::class)->makePartial();
    $service->shouldReceive('hasMaterializedViews')->andReturn(false);

    $result = $service->byType(collect([$project->id]));

    expect($result)->toHaveCount(2);
    $types = $result->pluck('task_type')->sort()->values()->all();
    expect($types)->toBe(['code_review', 'feature_dev']);
});

it('byPeriod returns aggregated metrics with month grouping when materialized views are disabled', function (): void {
    $project = Project::factory()->enabled()->create();
    $task = Task::factory()->completed()->create([
        'project_id' => $project->id,
        'type' => 'code_review',
    ]);

    insertTaskMetric($task, [
        'created_at' => now(),
    ]);

    $service = Mockery::mock(MetricsQueryService::class)->makePartial();
    $service->shouldReceive('hasMaterializedViews')->andReturn(false);

    $result = $service->byPeriod(collect([$project->id]), months: 12);

    expect($result)->toHaveCount(1);
    expect($result->first()->period_month)->toBe(now()->format('Y-m'));
});

it('byProject queries mv_metrics_by_project when materialized views are available', function (): void {
    $project = Project::factory()->enabled()->create();
    $task = Task::factory()->completed()->create([
        'project_id' => $project->id,
        'type' => 'code_review',
    ]);

    insertTaskMetric($task, [
        'task_type' => 'code_review',
        'input_tokens' => 50000,
        'output_tokens' => 25000,
        'cost' => 2.50,
        'duration' => 45,
        'severity_critical' => 1,
        'severity_high' => 3,
        'severity_medium' => 5,
        'severity_low' => 8,
        'findings_count' => 17,
    ]);

    refreshMetricsViews(['mv_metrics_by_project']);

    $service = new MetricsQueryService;
    $result = $service->byProject(collect([$project->id]));

    expect($result)->toHaveCount(1);
    expect((int) $result->first()->task_count)->toBe(1);
    expect((int) $result->first()->total_tokens)->toBe(75000);
    expect((float) $result->first()->total_cost)->toBe(2.50);
});

it('byType queries mv_metrics_by_type when materialized views are available', function (): void {
    $project = Project::factory()->enabled()->create();

    $task1 = Task::factory()->completed()->create([
        'project_id' => $project->id,
        'type' => 'code_review',
    ]);
    $task2 = Task::factory()->completed()->create([
        'project_id' => $project->id,
        'type' => 'feature_dev',
    ]);

    insertTaskMetric($task1, [
        'task_type' => 'code_review',
        'input_tokens' => 20000,
        'output_tokens' => 10000,
        'cost' => 1.00,
        'duration' => 25,
        'severity_high' => 2,
        'severity_medium' => 3,
        'severity_low' => 4,
        'findings_count' => 9,
    ]);
    insertTaskMetric($task2, [
        'task_type' => 'feature_dev',
        'input_tokens' => 15000,
        'output_tokens' => 8000,
        'cost' => 0.75,
        'duration' => 50,
    ]);

    refreshMetricsViews(['mv_metrics_by_type']);

    $service = new MetricsQueryService;
    $result = $service->byType(collect([$project->id]));

    expect($result)->toHaveCount(2);
    $types = $result->pluck('task_type')->sort()->values()->all();
    expect($types)->toBe(['code_review', 'feature_dev']);
});

it('byPeriod queries mv_metrics_by_period when materialized views are available', function (): void {
    $project = Project::factory()->enabled()->create();
    $lastMonth = now()->subMonth()->format('Y-m');
    $currentMonth = now()->format('Y-m');

    $task1 = Task::factory()->completed()->create([
        'project_id' => $project->id,
        'type' => 'code_review',
    ]);
    $task2 = Task::factory()->completed()->create([
        'project_id' => $project->id,
        'type' => 'code_review',
    ]);

    insertTaskMetric($task1, [
        'task_type' => 'code_review',
        'input_tokens' => 40000,
        'output_tokens' => 20000,
        'cost' => 2.00,
        'duration' => 35,
        'severity_high' => 1,
        'severity_medium' => 2,
        'severity_low' => 3,
        'findings_count' => 6,
        'created_at' => now()->subMonth()->startOfMonth(),
    ]);
    insertTaskMetric($task2, [
        'task_type' => 'code_review',
        'input_tokens' => 20000,
        'output_tokens' => 10000,
        'cost' => 1.00,
        'duration' => 30,
        'severity_critical' => 1,
        'findings_count' => 1,
        'created_at' => now()->startOfMonth(),
    ]);

    refreshMetricsViews(['mv_metrics_by_period']);

    $service = new MetricsQueryService;
    $result = $service->byPeriod(collect([$project->id]), months: 12);

    expect($result)->toHaveCount(2);
    expect($result->first()->period_month)->toBe($lastMonth);
    expect($result->last()->period_month)->toBe($currentMonth);
});

it('byPeriod with materialized views filters by date range', function (): void {
    $project = Project::factory()->enabled()->create();

    $oldTask = Task::factory()->completed()->create([
        'project_id' => $project->id,
        'type' => 'code_review',
    ]);
    $currentTask = Task::factory()->completed()->create([
        'project_id' => $project->id,
        'type' => 'code_review',
    ]);

    insertTaskMetric($oldTask, [
        'task_type' => 'code_review',
        'created_at' => now()->subMonths(24)->startOfMonth(),
    ]);
    insertTaskMetric($currentTask, [
        'task_type' => 'code_review',
        'created_at' => now()->startOfMonth(),
    ]);

    refreshMetricsViews(['mv_metrics_by_period']);

    $service = new MetricsQueryService;
    $result = $service->byPeriod(collect([$project->id]), months: 12);

    expect($result)->toHaveCount(1);
    expect((int) $result->first()->task_count)->toBe(1);
});

it('byProject with materialized views filters by project IDs', function (): void {
    $project1 = Project::factory()->enabled()->create();
    $project2 = Project::factory()->enabled()->create();

    $task1 = Task::factory()->completed()->create([
        'project_id' => $project1->id,
        'type' => 'code_review',
    ]);
    $task2 = Task::factory()->completed()->create([
        'project_id' => $project2->id,
        'type' => 'code_review',
    ]);

    insertTaskMetric($task1, ['task_type' => 'code_review']);
    insertTaskMetric($task2, ['task_type' => 'code_review']);

    refreshMetricsViews(['mv_metrics_by_project']);

    $service = new MetricsQueryService;
    $result = $service->byProject(collect([$project1->id]));

    expect($result)->toHaveCount(1);
    expect((int) $result->first()->project_id)->toBe($project1->id);
});

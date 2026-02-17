<?php

use App\Models\Project;
use App\Models\Task;
use App\Services\MetricsQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ------------------------------------------------------------------
//  Fallback queries (SQLite path — already exercised in other tests,
//  but we add explicit coverage for completeness)
// ------------------------------------------------------------------

it('returns empty collection for byProject with empty project IDs', function (): void {
    $service = new MetricsQueryService;
    $result = $service->byProject(collect());

    expect($result)->toBeEmpty();
});

it('returns empty collection for byType with empty project IDs', function (): void {
    $service = new MetricsQueryService;
    $result = $service->byType(collect());

    expect($result)->toBeEmpty();
});

it('returns empty collection for byPeriod with empty project IDs', function (): void {
    $service = new MetricsQueryService;
    $result = $service->byPeriod(collect());

    expect($result)->toBeEmpty();
});

it('hasMaterializedViews returns false for SQLite', function (): void {
    $service = new MetricsQueryService;

    expect($service->hasMaterializedViews())->toBeFalse();
});

// ------------------------------------------------------------------
//  SQLite fallback path with real data
// ------------------------------------------------------------------

it('byProject returns aggregated metrics from task_metrics table', function (): void {
    $project = Project::factory()->enabled()->create();
    $task = Task::factory()->completed()->create(['project_id' => $project->id]);

    DB::table('task_metrics')->insert([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'task_type' => 'code_review',
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'cost' => 0.05,
        'duration' => 30,
        'severity_critical' => 0,
        'severity_high' => 1,
        'severity_medium' => 2,
        'severity_low' => 3,
        'findings_count' => 6,
        'created_at' => now(),
    ]);

    $service = new MetricsQueryService;
    $result = $service->byProject(collect([$project->id]));

    expect($result)->toHaveCount(1);
    expect((int) $result->first()->task_count)->toBe(1);
    expect((int) $result->first()->total_input_tokens)->toBe(1000);
    expect((int) $result->first()->total_output_tokens)->toBe(500);
});

it('byType returns aggregated metrics grouped by task type', function (): void {
    $project = Project::factory()->enabled()->create();
    $task1 = Task::factory()->completed()->create(['project_id' => $project->id, 'type' => 'code_review']);
    $task2 = Task::factory()->completed()->create(['project_id' => $project->id, 'type' => 'feature_dev']);

    DB::table('task_metrics')->insert([
        'task_id' => $task1->id,
        'project_id' => $project->id,
        'task_type' => 'code_review',
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'cost' => 0.05,
        'duration' => 30,
        'severity_critical' => 0,
        'severity_high' => 0,
        'severity_medium' => 0,
        'severity_low' => 0,
        'findings_count' => 0,
        'created_at' => now(),
    ]);
    DB::table('task_metrics')->insert([
        'task_id' => $task2->id,
        'project_id' => $project->id,
        'task_type' => 'feature_dev',
        'input_tokens' => 2000,
        'output_tokens' => 1000,
        'cost' => 0.10,
        'duration' => 60,
        'severity_critical' => 0,
        'severity_high' => 0,
        'severity_medium' => 0,
        'severity_low' => 0,
        'findings_count' => 0,
        'created_at' => now(),
    ]);

    $service = new MetricsQueryService;
    $result = $service->byType(collect([$project->id]));

    expect($result)->toHaveCount(2);
    $types = $result->pluck('task_type')->sort()->values()->all();
    expect($types)->toBe(['code_review', 'feature_dev']);
});

it('byPeriod returns aggregated metrics with month grouping', function (): void {
    $project = Project::factory()->enabled()->create();
    $task = Task::factory()->completed()->create(['project_id' => $project->id]);

    DB::table('task_metrics')->insert([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'task_type' => 'code_review',
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'cost' => 0.05,
        'duration' => 30,
        'severity_critical' => 0,
        'severity_high' => 0,
        'severity_medium' => 0,
        'severity_low' => 0,
        'findings_count' => 0,
        'created_at' => now(),
    ]);

    $service = new MetricsQueryService;
    $result = $service->byPeriod(collect([$project->id]), months: 12);

    expect($result)->toHaveCount(1);
    expect($result->first()->period_month)->toBe(now()->format('Y-m'));
});

// ------------------------------------------------------------------
//  Materialized view paths (mocked hasMaterializedViews)
// ------------------------------------------------------------------

it('byProject queries mv_metrics_by_project when materialized views are available', function (): void {
    $project = Project::factory()->enabled()->create();

    // Create the fake materialized view table in SQLite
    DB::statement('CREATE TABLE IF NOT EXISTS mv_metrics_by_project (
        project_id INTEGER,
        task_count INTEGER,
        total_input_tokens INTEGER,
        total_output_tokens INTEGER,
        total_tokens INTEGER,
        total_cost REAL,
        avg_cost REAL,
        avg_duration REAL,
        total_severity_critical INTEGER,
        total_severity_high INTEGER,
        total_severity_medium INTEGER,
        total_severity_low INTEGER,
        total_findings INTEGER
    )');

    DB::table('mv_metrics_by_project')->insert([
        'project_id' => $project->id,
        'task_count' => 10,
        'total_input_tokens' => 50000,
        'total_output_tokens' => 25000,
        'total_tokens' => 75000,
        'total_cost' => 2.50,
        'avg_cost' => 0.25,
        'avg_duration' => 45.0,
        'total_severity_critical' => 1,
        'total_severity_high' => 3,
        'total_severity_medium' => 5,
        'total_severity_low' => 8,
        'total_findings' => 17,
    ]);

    // Use partial mock to fake hasMaterializedViews returning true
    $service = Mockery::mock(MetricsQueryService::class)->makePartial();
    $service->shouldReceive('hasMaterializedViews')->andReturn(true);

    $result = $service->byProject(collect([$project->id]));

    expect($result)->toHaveCount(1);
    expect((int) $result->first()->task_count)->toBe(10);
    expect((int) $result->first()->total_tokens)->toBe(75000);
    expect((float) $result->first()->total_cost)->toBe(2.50);
});

it('byType queries mv_metrics_by_type when materialized views are available', function (): void {
    $project = Project::factory()->enabled()->create();

    DB::statement('CREATE TABLE IF NOT EXISTS mv_metrics_by_type (
        project_id INTEGER,
        task_type TEXT,
        task_count INTEGER,
        total_input_tokens INTEGER,
        total_output_tokens INTEGER,
        total_tokens INTEGER,
        total_cost REAL,
        avg_cost REAL,
        avg_duration REAL,
        total_severity_critical INTEGER,
        total_severity_high INTEGER,
        total_severity_medium INTEGER,
        total_severity_low INTEGER,
        total_findings INTEGER
    )');

    DB::table('mv_metrics_by_type')->insert([
        'project_id' => $project->id,
        'task_type' => 'code_review',
        'task_count' => 5,
        'total_input_tokens' => 20000,
        'total_output_tokens' => 10000,
        'total_tokens' => 30000,
        'total_cost' => 1.00,
        'avg_cost' => 0.20,
        'avg_duration' => 25.0,
        'total_severity_critical' => 0,
        'total_severity_high' => 2,
        'total_severity_medium' => 3,
        'total_severity_low' => 4,
        'total_findings' => 9,
    ]);
    DB::table('mv_metrics_by_type')->insert([
        'project_id' => $project->id,
        'task_type' => 'feature_dev',
        'task_count' => 3,
        'total_input_tokens' => 15000,
        'total_output_tokens' => 8000,
        'total_tokens' => 23000,
        'total_cost' => 0.75,
        'avg_cost' => 0.25,
        'avg_duration' => 50.0,
        'total_severity_critical' => 0,
        'total_severity_high' => 0,
        'total_severity_medium' => 0,
        'total_severity_low' => 0,
        'total_findings' => 0,
    ]);

    $service = Mockery::mock(MetricsQueryService::class)->makePartial();
    $service->shouldReceive('hasMaterializedViews')->andReturn(true);

    $result = $service->byType(collect([$project->id]));

    expect($result)->toHaveCount(2);
    $types = $result->pluck('task_type')->sort()->values()->all();
    expect($types)->toBe(['code_review', 'feature_dev']);
});

it('byPeriod queries mv_metrics_by_period when materialized views are available', function (): void {
    $project = Project::factory()->enabled()->create();

    DB::statement('CREATE TABLE IF NOT EXISTS mv_metrics_by_period (
        project_id INTEGER,
        task_type TEXT,
        period_month TEXT,
        task_count INTEGER,
        total_input_tokens INTEGER,
        total_output_tokens INTEGER,
        total_tokens INTEGER,
        total_cost REAL,
        avg_cost REAL,
        avg_duration REAL,
        total_severity_critical INTEGER,
        total_severity_high INTEGER,
        total_severity_medium INTEGER,
        total_severity_low INTEGER,
        total_findings INTEGER
    )');

    $currentMonth = now()->format('Y-m');
    $lastMonth = now()->subMonth()->format('Y-m');

    DB::table('mv_metrics_by_period')->insert([
        'project_id' => $project->id,
        'task_type' => 'code_review',
        'period_month' => $lastMonth,
        'task_count' => 8,
        'total_input_tokens' => 40000,
        'total_output_tokens' => 20000,
        'total_tokens' => 60000,
        'total_cost' => 2.00,
        'avg_cost' => 0.25,
        'avg_duration' => 35.0,
        'total_severity_critical' => 0,
        'total_severity_high' => 1,
        'total_severity_medium' => 2,
        'total_severity_low' => 3,
        'total_findings' => 6,
    ]);
    DB::table('mv_metrics_by_period')->insert([
        'project_id' => $project->id,
        'task_type' => 'code_review',
        'period_month' => $currentMonth,
        'task_count' => 4,
        'total_input_tokens' => 20000,
        'total_output_tokens' => 10000,
        'total_tokens' => 30000,
        'total_cost' => 1.00,
        'avg_cost' => 0.25,
        'avg_duration' => 30.0,
        'total_severity_critical' => 1,
        'total_severity_high' => 0,
        'total_severity_medium' => 0,
        'total_severity_low' => 0,
        'total_findings' => 1,
    ]);

    $service = Mockery::mock(MetricsQueryService::class)->makePartial();
    $service->shouldReceive('hasMaterializedViews')->andReturn(true);

    $result = $service->byPeriod(collect([$project->id]), months: 12);

    expect($result)->toHaveCount(2);
    // Results should be ordered by period_month
    expect($result->first()->period_month)->toBe($lastMonth);
    expect($result->last()->period_month)->toBe($currentMonth);
});

it('byPeriod with materialized views filters by date range', function (): void {
    $project = Project::factory()->enabled()->create();

    DB::statement('CREATE TABLE IF NOT EXISTS mv_metrics_by_period (
        project_id INTEGER,
        task_type TEXT,
        period_month TEXT,
        task_count INTEGER,
        total_input_tokens INTEGER,
        total_output_tokens INTEGER,
        total_tokens INTEGER,
        total_cost REAL,
        avg_cost REAL,
        avg_duration REAL,
        total_severity_critical INTEGER,
        total_severity_high INTEGER,
        total_severity_medium INTEGER,
        total_severity_low INTEGER,
        total_findings INTEGER
    )');

    // Insert a row from 24 months ago — should be excluded when querying 12 months
    $oldMonth = now()->subMonths(24)->format('Y-m');
    $currentMonth = now()->format('Y-m');

    DB::table('mv_metrics_by_period')->insert([
        'project_id' => $project->id,
        'task_type' => 'code_review',
        'period_month' => $oldMonth,
        'task_count' => 100,
        'total_input_tokens' => 0,
        'total_output_tokens' => 0,
        'total_tokens' => 0,
        'total_cost' => 0,
        'avg_cost' => 0,
        'avg_duration' => 0,
        'total_severity_critical' => 0,
        'total_severity_high' => 0,
        'total_severity_medium' => 0,
        'total_severity_low' => 0,
        'total_findings' => 0,
    ]);
    DB::table('mv_metrics_by_period')->insert([
        'project_id' => $project->id,
        'task_type' => 'code_review',
        'period_month' => $currentMonth,
        'task_count' => 5,
        'total_input_tokens' => 0,
        'total_output_tokens' => 0,
        'total_tokens' => 0,
        'total_cost' => 0,
        'avg_cost' => 0,
        'avg_duration' => 0,
        'total_severity_critical' => 0,
        'total_severity_high' => 0,
        'total_severity_medium' => 0,
        'total_severity_low' => 0,
        'total_findings' => 0,
    ]);

    $service = Mockery::mock(MetricsQueryService::class)->makePartial();
    $service->shouldReceive('hasMaterializedViews')->andReturn(true);

    $result = $service->byPeriod(collect([$project->id]), months: 12);

    // Only the current month should be returned; the 24-month-old row is excluded
    expect($result)->toHaveCount(1);
    expect((int) $result->first()->task_count)->toBe(5);
});

it('byProject with materialized views filters by project IDs', function (): void {
    $project1 = Project::factory()->enabled()->create();
    $project2 = Project::factory()->enabled()->create();

    DB::statement('CREATE TABLE IF NOT EXISTS mv_metrics_by_project (
        project_id INTEGER,
        task_count INTEGER,
        total_input_tokens INTEGER,
        total_output_tokens INTEGER,
        total_tokens INTEGER,
        total_cost REAL,
        avg_cost REAL,
        avg_duration REAL,
        total_severity_critical INTEGER,
        total_severity_high INTEGER,
        total_severity_medium INTEGER,
        total_severity_low INTEGER,
        total_findings INTEGER
    )');

    DB::table('mv_metrics_by_project')->insert([
        'project_id' => $project1->id,
        'task_count' => 10,
        'total_input_tokens' => 0,
        'total_output_tokens' => 0,
        'total_tokens' => 0,
        'total_cost' => 0,
        'avg_cost' => 0,
        'avg_duration' => 0,
        'total_severity_critical' => 0,
        'total_severity_high' => 0,
        'total_severity_medium' => 0,
        'total_severity_low' => 0,
        'total_findings' => 0,
    ]);
    DB::table('mv_metrics_by_project')->insert([
        'project_id' => $project2->id,
        'task_count' => 20,
        'total_input_tokens' => 0,
        'total_output_tokens' => 0,
        'total_tokens' => 0,
        'total_cost' => 0,
        'avg_cost' => 0,
        'avg_duration' => 0,
        'total_severity_critical' => 0,
        'total_severity_high' => 0,
        'total_severity_medium' => 0,
        'total_severity_low' => 0,
        'total_findings' => 0,
    ]);

    $service = Mockery::mock(MetricsQueryService::class)->makePartial();
    $service->shouldReceive('hasMaterializedViews')->andReturn(true);

    // Only request project1
    $result = $service->byProject(collect([$project1->id]));

    expect($result)->toHaveCount(1);
    expect((int) $result->first()->project_id)->toBe($project1->id);
});

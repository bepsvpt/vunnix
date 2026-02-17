<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Events\MetricsUpdated;
use App\Events\TaskStatusChanged;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskMetric;
use App\Services\MetricsAggregationService;
use App\Services\MetricsQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

// ─── Helper: seed task_metrics via observer ──────────────────────────

function seedTaskWithMetrics(array $attrs): Task
{
    $task = Task::factory()->create(array_merge([
        'status' => TaskStatus::Running,
    ], $attrs));

    // Suppress broadcasts during seeding
    Event::fake([TaskStatusChanged::class]);
    $task->transitionTo(TaskStatus::Completed);

    return $task->fresh();
}

// ─── MetricsQueryService: byProject aggregation ─────────────────────

test('byProject aggregates task_metrics by project', function (): void {
    $projectA = Project::factory()->enabled()->create();
    $projectB = Project::factory()->enabled()->create();

    seedTaskWithMetrics([
        'project_id' => $projectA->id,
        'type' => TaskType::CodeReview,
        'input_tokens' => 1000,
        'output_tokens' => 2000,
        'cost' => 0.500000,
        'duration_seconds' => 60,
    ]);

    seedTaskWithMetrics([
        'project_id' => $projectA->id,
        'type' => TaskType::FeatureDev,
        'input_tokens' => 3000,
        'output_tokens' => 5000,
        'cost' => 1.500000,
        'duration_seconds' => 120,
    ]);

    seedTaskWithMetrics([
        'project_id' => $projectB->id,
        'type' => TaskType::CodeReview,
        'input_tokens' => 500,
        'output_tokens' => 800,
        'cost' => 0.250000,
        'duration_seconds' => 30,
    ]);

    // Refresh materialized views so PostgreSQL CI sees the seeded data (D172)
    $this->artisan('metrics:aggregate');

    $service = app(MetricsQueryService::class);
    $result = $service->byProject(collect([$projectA->id, $projectB->id]));

    expect($result)->toHaveCount(2);

    $projectARow = $result->firstWhere('project_id', $projectA->id);
    expect((int) $projectARow->task_count)->toBe(2)
        ->and((int) $projectARow->total_input_tokens)->toBe(4000)
        ->and((int) $projectARow->total_output_tokens)->toBe(7000)
        ->and((int) $projectARow->total_tokens)->toBe(11000)
        ->and(round($projectARow->total_cost, 6))->toBe(2.0);

    $projectBRow = $result->firstWhere('project_id', $projectB->id);
    expect((int) $projectBRow->task_count)->toBe(1)
        ->and((int) $projectBRow->total_tokens)->toBe(1300);
});

// ─── MetricsQueryService: byType aggregation ────────────────────────

test('byType aggregates task_metrics by project and task type', function (): void {
    $project = Project::factory()->enabled()->create();

    seedTaskWithMetrics([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'input_tokens' => 1000,
        'output_tokens' => 2000,
        'cost' => 0.500000,
        'duration_seconds' => 60,
        'result' => [
            'summary' => [
                'total_findings' => 3,
                'findings_by_severity' => ['critical' => 1, 'major' => 1, 'minor' => 1],
            ],
        ],
    ]);

    seedTaskWithMetrics([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'input_tokens' => 2000,
        'output_tokens' => 3000,
        'cost' => 0.750000,
        'duration_seconds' => 90,
        'result' => [
            'summary' => [
                'total_findings' => 5,
                'findings_by_severity' => ['critical' => 2, 'major' => 2, 'minor' => 1],
            ],
        ],
    ]);

    seedTaskWithMetrics([
        'project_id' => $project->id,
        'type' => TaskType::FeatureDev,
        'input_tokens' => 5000,
        'output_tokens' => 8000,
        'cost' => 2.000000,
        'duration_seconds' => 180,
    ]);

    // Refresh materialized views so PostgreSQL CI sees the seeded data (D172)
    $this->artisan('metrics:aggregate');

    $service = app(MetricsQueryService::class);
    $result = $service->byType(collect([$project->id]));

    expect($result)->toHaveCount(2);

    $codeReview = $result->firstWhere('task_type', 'code_review');
    expect((int) $codeReview->task_count)->toBe(2)
        ->and((int) $codeReview->total_findings)->toBe(8)
        ->and((int) $codeReview->total_severity_critical)->toBe(3)
        ->and((int) $codeReview->total_severity_high)->toBe(3)
        ->and(round($codeReview->total_cost, 6))->toBe(1.25);

    $featureDev = $result->firstWhere('task_type', 'feature_dev');
    expect((int) $featureDev->task_count)->toBe(1)
        ->and((int) $featureDev->total_tokens)->toBe(13000);
});

// ─── MetricsQueryService: byPeriod aggregation ──────────────────────

test('byPeriod aggregates task_metrics by project, type, and month', function (): void {
    $project = Project::factory()->enabled()->create();

    // Use DB::table to bypass $fillable and set created_at explicitly for period testing
    $taskA = Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Completed]);
    $taskB = Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Completed]);
    $taskC = Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Completed]);

    DB::table('task_metrics')->insert([
        'task_id' => $taskA->id,
        'project_id' => $project->id,
        'task_type' => 'code_review',
        'input_tokens' => 1000,
        'output_tokens' => 2000,
        'cost' => 0.5,
        'duration' => 60,
        'findings_count' => 3,
        'severity_critical' => 0,
        'severity_high' => 0,
        'severity_medium' => 0,
        'severity_low' => 0,
        'created_at' => now()->startOfMonth(),
        'updated_at' => now()->startOfMonth(),
    ]);

    DB::table('task_metrics')->insert([
        'task_id' => $taskB->id,
        'project_id' => $project->id,
        'task_type' => 'code_review',
        'input_tokens' => 2000,
        'output_tokens' => 3000,
        'cost' => 0.75,
        'duration' => 90,
        'findings_count' => 5,
        'severity_critical' => 0,
        'severity_high' => 0,
        'severity_medium' => 0,
        'severity_low' => 0,
        'created_at' => now()->startOfMonth(),
        'updated_at' => now()->startOfMonth(),
    ]);

    DB::table('task_metrics')->insert([
        'task_id' => $taskC->id,
        'project_id' => $project->id,
        'task_type' => 'feature_dev',
        'input_tokens' => 5000,
        'output_tokens' => 8000,
        'cost' => 2.0,
        'duration' => 180,
        'findings_count' => 0,
        'severity_critical' => 0,
        'severity_high' => 0,
        'severity_medium' => 0,
        'severity_low' => 0,
        'created_at' => now()->subMonth()->startOfMonth(),
        'updated_at' => now()->subMonth()->startOfMonth(),
    ]);

    // Refresh materialized views so PostgreSQL CI sees the seeded data (D172)
    $this->artisan('metrics:aggregate');

    $service = app(MetricsQueryService::class);
    $result = $service->byPeriod(collect([$project->id]), 12);

    // Should have 2 rows: last month (feature_dev) + this month (code_review)
    expect($result)->toHaveCount(2);

    $currentMonth = now()->format('Y-m');
    $lastMonth = now()->subMonth()->format('Y-m');

    $thisMonthRows = $result->where('period_month', $currentMonth);
    expect($thisMonthRows)->toHaveCount(1);
    expect((int) $thisMonthRows->first()->task_count)->toBe(2)
        ->and(round($thisMonthRows->first()->total_cost, 6))->toBe(1.25);

    $lastMonthRows = $result->where('period_month', $lastMonth);
    expect($lastMonthRows)->toHaveCount(1);
    expect((int) $lastMonthRows->first()->task_count)->toBe(1)
        ->and(round($lastMonthRows->first()->total_cost, 6))->toBe(2.0);
});

// ─── MetricsQueryService: empty project_ids ─────────────────────────

test('returns empty collection when no project IDs provided', function (): void {
    $service = app(MetricsQueryService::class);

    expect($service->byProject(collect()))->toBeEmpty()
        ->and($service->byType(collect()))->toBeEmpty()
        ->and($service->byPeriod(collect()))->toBeEmpty();
});

// ─── MetricsQueryService: project scoping ───────────────────────────

test('byProject only returns data for requested project IDs', function (): void {
    $projectA = Project::factory()->enabled()->create();
    $projectB = Project::factory()->enabled()->create();

    seedTaskWithMetrics([
        'project_id' => $projectA->id,
        'type' => TaskType::CodeReview,
        'input_tokens' => 1000,
        'output_tokens' => 2000,
        'cost' => 0.500000,
    ]);

    seedTaskWithMetrics([
        'project_id' => $projectB->id,
        'type' => TaskType::CodeReview,
        'input_tokens' => 5000,
        'output_tokens' => 8000,
        'cost' => 2.000000,
    ]);

    // Refresh materialized views so PostgreSQL CI sees the seeded data (D172)
    $this->artisan('metrics:aggregate');

    $service = app(MetricsQueryService::class);

    // Only request project A
    $result = $service->byProject(collect([$projectA->id]));

    expect($result)->toHaveCount(1)
        ->and((int) $result->first()->project_id)->toBe($projectA->id);
});

// ─── Integration: 10 tasks → aggregate → verify ─────────────────────

test('10 tasks with known metrics produce correct aggregation', function (): void {
    $projectA = Project::factory()->enabled()->create();
    $projectB = Project::factory()->enabled()->create();

    // 6 code reviews in project A
    for ($i = 0; $i < 6; $i++) {
        seedTaskWithMetrics([
            'project_id' => $projectA->id,
            'type' => TaskType::CodeReview,
            'input_tokens' => 1000,
            'output_tokens' => 2000,
            'cost' => 0.500000,
            'duration_seconds' => 60,
            'result' => [
                'summary' => [
                    'total_findings' => 2,
                    'findings_by_severity' => ['critical' => 1, 'major' => 1, 'minor' => 0],
                ],
            ],
        ]);
    }

    // 2 feature devs in project A
    for ($i = 0; $i < 2; $i++) {
        seedTaskWithMetrics([
            'project_id' => $projectA->id,
            'type' => TaskType::FeatureDev,
            'input_tokens' => 5000,
            'output_tokens' => 8000,
            'cost' => 2.000000,
            'duration_seconds' => 180,
        ]);
    }

    // 2 code reviews in project B
    for ($i = 0; $i < 2; $i++) {
        seedTaskWithMetrics([
            'project_id' => $projectB->id,
            'type' => TaskType::CodeReview,
            'input_tokens' => 2000,
            'output_tokens' => 3000,
            'cost' => 0.750000,
            'duration_seconds' => 90,
            'result' => [
                'summary' => [
                    'total_findings' => 4,
                    'findings_by_severity' => ['critical' => 2, 'major' => 1, 'minor' => 1],
                ],
            ],
        ]);
    }

    // Verify 10 task_metrics records exist
    expect(TaskMetric::count())->toBe(10);

    // Refresh materialized views so PostgreSQL CI sees the seeded data (D172)
    $this->artisan('metrics:aggregate');

    $service = app(MetricsQueryService::class);
    $projectIds = collect([$projectA->id, $projectB->id]);

    // ─── By Project ─────────────────────────
    $byProject = $service->byProject($projectIds);

    $projectARow = $byProject->firstWhere('project_id', $projectA->id);
    expect((int) $projectARow->task_count)->toBe(8)
        // 6 reviews × (1000+2000) + 2 features × (5000+8000) = 18000 + 26000 = 44000
        ->and((int) $projectARow->total_tokens)->toBe(44000)
        // 6 × 0.50 + 2 × 2.00 = 3.00 + 4.00 = 7.00
        ->and(round($projectARow->total_cost, 2))->toBe(7.0);

    $projectBRow = $byProject->firstWhere('project_id', $projectB->id);
    expect((int) $projectBRow->task_count)->toBe(2)
        // 2 × (2000+3000) = 10000
        ->and((int) $projectBRow->total_tokens)->toBe(10000)
        // 2 × 0.75 = 1.50
        ->and(round($projectBRow->total_cost, 2))->toBe(1.5);

    // ─── By Type ────────────────────────────
    $byType = $service->byType($projectIds);

    // Code reviews across both projects: 6 + 2 = 8
    $codeReviews = $byType->where('task_type', 'code_review');
    $totalCodeReviewCount = $codeReviews->sum('task_count');
    expect((int) $totalCodeReviewCount)->toBe(8);

    // Total findings: 6×2 + 2×4 = 12 + 8 = 20
    $totalFindings = $codeReviews->sum('total_findings');
    expect((int) $totalFindings)->toBe(20);

    // Total severity_critical: 6×1 + 2×2 = 6 + 4 = 10
    $totalCritical = $codeReviews->sum('total_severity_critical');
    expect((int) $totalCritical)->toBe(10);

    // Feature devs: only in project A = 2
    $featureDevs = $byType->where('task_type', 'feature_dev');
    expect((int) $featureDevs->sum('task_count'))->toBe(2);

    // ─── By Period ──────────────────────────
    $byPeriod = $service->byPeriod($projectIds, 12);

    // All created in the current month
    $currentMonth = now()->format('Y-m');
    $thisMonthRows = $byPeriod->where('period_month', $currentMonth);

    // All 10 tasks should be in this month's period
    expect((int) $thisMonthRows->sum('task_count'))->toBe(10);
});

// ─── Artisan command: metrics:aggregate ──────────────────────────────

test('metrics:aggregate command runs successfully', function (): void {
    $this->artisan('metrics:aggregate')
        ->expectsOutputToContain('Starting metrics aggregation')
        ->expectsOutputToContain('Metrics aggregation completed')
        ->assertSuccessful();
});

test('metrics:aggregate command reports view count based on driver', function (): void {
    $expected = DB::connection()->getDriverName() === 'pgsql'
        ? '3 views refreshed'
        : '0 views refreshed';

    $this->artisan('metrics:aggregate')
        ->expectsOutputToContain($expected)
        ->assertSuccessful();
});

// ─── MetricsAggregationService: broadcasts events ───────────────────

test('aggregate broadcasts MetricsUpdated event per project', function (): void {
    Event::fake([MetricsUpdated::class, TaskStatusChanged::class]);

    $projectA = Project::factory()->enabled()->create();
    $projectB = Project::factory()->enabled()->create();

    TaskMetric::create([
        'task_id' => Task::factory()->create(['project_id' => $projectA->id, 'status' => TaskStatus::Completed])->id,
        'project_id' => $projectA->id,
        'task_type' => 'code_review',
        'input_tokens' => 100,
        'output_tokens' => 200,
        'cost' => 0.01,
        'duration' => 10,
    ]);

    TaskMetric::create([
        'task_id' => Task::factory()->create(['project_id' => $projectB->id, 'status' => TaskStatus::Completed])->id,
        'project_id' => $projectB->id,
        'task_type' => 'feature_dev',
        'input_tokens' => 500,
        'output_tokens' => 800,
        'cost' => 0.05,
        'duration' => 30,
    ]);

    $service = app(MetricsAggregationService::class);
    $result = $service->aggregate();

    Event::assertDispatched(MetricsUpdated::class, 2);
    Event::assertDispatched(MetricsUpdated::class, fn ($e): bool => $e->projectId === $projectA->id);
    Event::assertDispatched(MetricsUpdated::class, fn ($e): bool => $e->projectId === $projectB->id);
});

// ─── MetricsAggregationService: returns result ──────────────────────

test('aggregate returns views_refreshed and duration_ms', function (): void {
    $service = app(MetricsAggregationService::class);
    $result = $service->aggregate();

    expect($result)->toHaveKeys(['views_refreshed', 'duration_ms'])
        ->and($result['views_refreshed'])->toBeInt()
        ->and($result['duration_ms'])->toBeInt()
        ->and($result['duration_ms'])->toBeGreaterThanOrEqual(0);
});

// ─── Scheduler registration ─────────────────────────────────────────

test('metrics:aggregate is scheduled every 15 minutes', function (): void {
    $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);

    $events = collect($schedule->events())->filter(
        fn ($event): bool => str_contains($event->command ?? '', 'metrics:aggregate')
    );

    expect($events)->toHaveCount(1);
    expect($events->first()->expression)->toBe('*/15 * * * *');
});

<?php

use App\Models\CostAlert;
use App\Models\Project;
use App\Models\Task;
use App\Services\CostAlertService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->project = Project::factory()->enabled()->create();
});

// ─── Monthly anomaly ─────────────────────────────────────────────────

it('creates alert when monthly spend exceeds 2x rolling 3-month average', function (): void {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // Historical: 3 months × ~$100 avg = $300 total
    seedMetric($this->project, ['cost' => 90.0, 'created_at' => $now->copy()->subMonths(3)->addDays(5)]);
    seedMetric($this->project, ['cost' => 110.0, 'created_at' => $now->copy()->subMonths(2)->addDays(5)]);
    seedMetric($this->project, ['cost' => 100.0, 'created_at' => $now->copy()->subMonths(1)->addDays(5)]);

    // Current month: $250 (> 2 × $100 avg = $200)
    seedMetric($this->project, ['cost' => 250.0, 'created_at' => $now->copy()->startOfMonth()->addDays(5)]);

    $service = new CostAlertService;
    $alert = $service->evaluateMonthlyAnomaly($now);

    expect($alert)->not->toBeNull()
        ->and($alert->rule)->toBe('monthly_anomaly')
        ->and($alert->severity)->toBe('critical')
        ->and((float) $alert->context['current_spend'])->toBe(250.0);
});

it('does not create alert when monthly spend is within 2x average', function (): void {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // Historical: $100/month avg
    seedMetric($this->project, ['cost' => 100.0, 'created_at' => $now->copy()->subMonths(2)->addDays(5)]);
    seedMetric($this->project, ['cost' => 100.0, 'created_at' => $now->copy()->subMonths(1)->addDays(5)]);

    // Current month: $150 (< 2 × $100 = $200)
    seedMetric($this->project, ['cost' => 150.0, 'created_at' => $now->copy()->startOfMonth()->addDays(5)]);

    $service = new CostAlertService;
    $alert = $service->evaluateMonthlyAnomaly($now);

    expect($alert)->toBeNull();
});

it('skips monthly anomaly when no historical data', function (): void {
    $now = Carbon::parse('2026-02-15 12:00:00');

    seedMetric($this->project, ['cost' => 500.0, 'created_at' => $now->copy()->startOfMonth()->addDays(5)]);

    $service = new CostAlertService;
    $alert = $service->evaluateMonthlyAnomaly($now);

    expect($alert)->toBeNull();
});

// ─── Daily spike ─────────────────────────────────────────────────────

it('creates alert when daily spend exceeds 5x daily average', function (): void {
    $now = Carbon::parse('2026-02-15 14:00:00');

    // Historical: 10 days × $10 = $100 total, $10/day avg
    for ($i = 1; $i <= 10; $i++) {
        seedMetric($this->project, ['cost' => 10.0, 'created_at' => $now->copy()->subDays($i)->setTime(12, 0)]);
    }

    // Today: $60 (> 5 × $10 = $50)
    seedMetric($this->project, ['cost' => 60.0, 'created_at' => $now->copy()->setTime(10, 0)]);

    $service = new CostAlertService;
    $alert = $service->evaluateDailySpike($now);

    expect($alert)->not->toBeNull()
        ->and($alert->rule)->toBe('daily_spike')
        ->and($alert->severity)->toBe('critical')
        ->and((float) $alert->context['today_spend'])->toBe(60.0);
});

it('does not create alert when daily spend is within 5x average', function (): void {
    $now = Carbon::parse('2026-02-15 14:00:00');

    // Historical: $10/day avg
    for ($i = 1; $i <= 10; $i++) {
        seedMetric($this->project, ['cost' => 10.0, 'created_at' => $now->copy()->subDays($i)->setTime(12, 0)]);
    }

    // Today: $40 (< 5 × $10 = $50)
    seedMetric($this->project, ['cost' => 40.0, 'created_at' => $now->copy()->setTime(10, 0)]);

    $service = new CostAlertService;
    $alert = $service->evaluateDailySpike($now);

    expect($alert)->toBeNull();
});

// ─── Single task outlier ─────────────────────────────────────────────

it('creates alert when single task cost exceeds 3x type average', function (): void {
    // Historical: 5 code_review tasks with avg cost $0.50
    for ($i = 0; $i < 5; $i++) {
        seedMetric($this->project, ['cost' => 0.50, 'task_type' => 'code_review']);
    }

    $service = new CostAlertService;
    $alert = $service->evaluateSingleTaskOutlier(
        taskId: 999999, // ID that doesn't match any existing metric
        taskType: 'code_review',
        taskCost: 2.00, // > 3 × $0.50 = $1.50
    );

    expect($alert)->not->toBeNull()
        ->and($alert->rule)->toBe('single_task_outlier')
        ->and($alert->severity)->toBe('warning')
        ->and($alert->context['task_id'])->toBe(999999)
        ->and($alert->context['task_type'])->toBe('code_review');
});

it('does not create alert when single task cost is within 3x type average', function (): void {
    for ($i = 0; $i < 5; $i++) {
        seedMetric($this->project, ['cost' => 0.50, 'task_type' => 'code_review']);
    }

    $service = new CostAlertService;
    $alert = $service->evaluateSingleTaskOutlier(
        taskId: 999999,
        taskType: 'code_review',
        taskCost: 1.00, // < 3 × $0.50 = $1.50
    );

    expect($alert)->toBeNull();
});

it('skips single task outlier when no history for type', function (): void {
    $service = new CostAlertService;
    $alert = $service->evaluateSingleTaskOutlier(
        taskId: 999999,
        taskType: 'code_review',
        taskCost: 5.00,
    );

    expect($alert)->toBeNull();
});

// ─── Approaching projection ─────────────────────────────────────────

it('creates alert when projected month-end exceeds 2x last month', function (): void {
    $now = Carbon::parse('2026-02-15 12:00:00'); // Day 15 of 28

    // Last month: $100 total
    seedMetric($this->project, ['cost' => 100.0, 'created_at' => Carbon::parse('2026-01-15 12:00:00')]);

    // Current month (first 15 days): $120
    // Projection: ($120/15) × 28 = $224 (> 2 × $100 = $200)
    seedMetric($this->project, ['cost' => 120.0, 'created_at' => $now->copy()->startOfMonth()->addDays(5)]);

    $service = new CostAlertService;
    $alert = $service->evaluateApproachingProjection($now);

    expect($alert)->not->toBeNull()
        ->and($alert->rule)->toBe('approaching_projection')
        ->and($alert->severity)->toBe('warning')
        ->and((float) $alert->context['last_month_spend'])->toBe(100.0);
});

it('does not create alert when projection is within 2x last month', function (): void {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // Last month: $200
    seedMetric($this->project, ['cost' => 200.0, 'created_at' => Carbon::parse('2026-01-15 12:00:00')]);

    // Current month: $100 over 15 days → projection ($100/15)*28 = $186.67 (< $400)
    seedMetric($this->project, ['cost' => 100.0, 'created_at' => $now->copy()->startOfMonth()->addDays(5)]);

    $service = new CostAlertService;
    $alert = $service->evaluateApproachingProjection($now);

    expect($alert)->toBeNull();
});

it('skips projection when too early in month', function (): void {
    $now = Carbon::parse('2026-02-02 12:00:00'); // Day 2

    seedMetric($this->project, ['cost' => 100.0, 'created_at' => Carbon::parse('2026-01-15 12:00:00')]);
    seedMetric($this->project, ['cost' => 500.0, 'created_at' => $now->copy()->startOfMonth()->addDay()]);

    $service = new CostAlertService;
    $alert = $service->evaluateApproachingProjection($now);

    expect($alert)->toBeNull();
});

// ─── Deduplication ───────────────────────────────────────────────────

it('does not create duplicate alert for same rule on same day', function (): void {
    $now = Carbon::parse('2026-02-15 14:00:00');

    // Historical data that would trigger monthly anomaly
    seedMetric($this->project, ['cost' => 100.0, 'created_at' => $now->copy()->subMonths(1)->addDays(5)]);
    seedMetric($this->project, ['cost' => 500.0, 'created_at' => $now->copy()->startOfMonth()->addDays(5)]);

    $service = new CostAlertService;

    // First evaluation → alert
    $alert1 = $service->evaluateMonthlyAnomaly($now);
    expect($alert1)->not->toBeNull();

    // Second evaluation same day → no duplicate
    $alert2 = $service->evaluateMonthlyAnomaly($now);
    expect($alert2)->toBeNull();

    expect(CostAlert::where('rule', 'monthly_anomaly')->count())->toBe(1);
});

// ─── evaluateAll ─────────────────────────────────────────────────────

it('evaluateAll runs 3 aggregate rules and returns created alerts', function (): void {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // Set up data that triggers monthly anomaly
    // Historical: $50/month avg over 2 months
    seedMetric($this->project, ['cost' => 50.0, 'created_at' => $now->copy()->subMonths(2)->addDays(5)]);
    seedMetric($this->project, ['cost' => 50.0, 'created_at' => $now->copy()->subMonths(1)->addDays(5)]);

    // Current month: $150 (> 2 × $50 = $100)
    seedMetric($this->project, ['cost' => 150.0, 'created_at' => $now->copy()->startOfMonth()->addDay()]);

    $service = new CostAlertService;
    $alerts = $service->evaluateAll($now);

    // evaluateAll should run all 3 aggregate rules and return any triggered
    expect($alerts)->not->toBeEmpty();
    $rules = collect($alerts)->pluck('rule')->all();
    expect($rules)->toContain('monthly_anomaly');
});

// ─── Helper ──────────────────────────────────────────────────────────

function seedMetric(Project $project, array $overrides = []): void
{
    $task = Task::factory()->create([
        'project_id' => $project->id,
    ]);

    $defaults = [
        'task_id' => $task->id,
        'project_id' => $project->id,
        'task_type' => 'code_review',
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'cost' => 0.10,
        'duration' => 60,
        'severity_critical' => 0,
        'severity_high' => 0,
        'severity_medium' => 0,
        'severity_low' => 0,
        'findings_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('task_metrics')->insert(
        array_merge($defaults, $overrides)
    );
}

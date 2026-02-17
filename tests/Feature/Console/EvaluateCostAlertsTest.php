<?php

use App\Models\CostAlert;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('cost-alerts:evaluate command runs aggregate rules', function (): void {
    $project = Project::factory()->enabled()->create();

    // Seed historical data: $50/month avg over 2 months
    $task1 = Task::factory()->create(['project_id' => $project->id]);
    DB::table('task_metrics')->insert([
        'task_id' => $task1->id,
        'project_id' => $project->id,
        'task_type' => 'code_review',
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'cost' => 50.0,
        'duration' => 60,
        'severity_critical' => 0,
        'severity_high' => 0,
        'severity_medium' => 0,
        'severity_low' => 0,
        'findings_count' => 0,
        'created_at' => now()->subMonths(2)->addDays(5),
        'updated_at' => now()->subMonths(2)->addDays(5),
    ]);

    $task2 = Task::factory()->create(['project_id' => $project->id]);
    DB::table('task_metrics')->insert([
        'task_id' => $task2->id,
        'project_id' => $project->id,
        'task_type' => 'code_review',
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'cost' => 50.0,
        'duration' => 60,
        'severity_critical' => 0,
        'severity_high' => 0,
        'severity_medium' => 0,
        'severity_low' => 0,
        'findings_count' => 0,
        'created_at' => now()->subMonths(1)->addDays(5),
        'updated_at' => now()->subMonths(1)->addDays(5),
    ]);

    // Current month: $150 (> 2 × $50 = $100) → should trigger monthly_anomaly
    $task3 = Task::factory()->create(['project_id' => $project->id]);
    DB::table('task_metrics')->insert([
        'task_id' => $task3->id,
        'project_id' => $project->id,
        'task_type' => 'code_review',
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'cost' => 150.0,
        'duration' => 60,
        'severity_critical' => 0,
        'severity_high' => 0,
        'severity_medium' => 0,
        'severity_low' => 0,
        'findings_count' => 0,
        'created_at' => now()->startOfMonth()->addDay(),
        'updated_at' => now()->startOfMonth()->addDay(),
    ]);

    $this->artisan('cost-alerts:evaluate')
        ->expectsOutputToContain('cost alert(s) created')
        ->assertSuccessful();

    expect(CostAlert::where('rule', 'monthly_anomaly')->count())->toBe(1);
});

test('cost-alerts:evaluate reports no alerts when none triggered', function (): void {
    // No data at all → no alerts
    $this->artisan('cost-alerts:evaluate')
        ->expectsOutputToContain('No cost alerts triggered')
        ->assertSuccessful();

    expect(CostAlert::count())->toBe(0);
});

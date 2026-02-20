<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\ArchitectureIterationMetric;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('collects and persists architecture iteration metrics snapshot', function (): void {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $failed = Task::factory()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Failed,
        'mr_iid' => 42,
        'result' => null,
    ]);
    $failed->forceFill([
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDays(3)->addMinutes(5),
    ])->saveQuietly();

    $followUp = Task::factory()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'mr_iid' => 42,
        'result' => [
            'files_changed' => ['a.php', 'b.php', 'c.php'],
        ],
    ]);
    $followUp->forceFill([
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2)->addHours(2),
    ])->saveQuietly();

    $featureTask = Task::factory()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Completed,
        'mr_iid' => 99,
        'result' => [
            'files_changed' => ['x.ts'],
        ],
    ]);
    $featureTask->forceFill([
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay()->addHours(1),
    ])->saveQuietly();

    $this->artisan('architecture:collect-iteration-metrics --date='.now()->toDateString())
        ->expectsOutputToContain('Architecture iteration metrics collected.')
        ->expectsOutputToContain('module_touch_breadth=')
        ->expectsOutputToContain('median_files_changed=')
        ->expectsOutputToContain('reopened_regressions_count=')
        ->expectsOutputToContain('lead_time_hours_p50=')
        ->assertSuccessful();

    $metric = ArchitectureIterationMetric::query()->first();

    expect($metric)->not->toBeNull()
        ->and($metric?->module_touch_breadth)->toBe(2)
        ->and($metric?->reopened_regressions_count)->toBe(1)
        ->and($metric?->median_files_changed)->toBe('2.00')
        ->and($metric?->lead_time_hours_p50)->toBe('1.50');
});

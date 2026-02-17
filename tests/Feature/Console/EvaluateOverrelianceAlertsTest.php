<?php

use App\Models\FindingAcceptance;
use App\Models\OverrelianceAlert;
use App\Models\Project;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('evaluates overreliance rules and reports results', function (): void {
    $this->artisan('overreliance:evaluate')
        ->expectsOutput('No over-reliance alerts triggered.')
        ->assertExitCode(0);
});

it('reports created alerts count', function (): void {
    $project = Project::factory()->enabled()->create();
    $now = Carbon::parse('2026-02-15 12:00:00');

    Carbon::setTestNow($now);

    // Create enough accepted findings for 2 consecutive weeks
    $task1 = Task::factory()->create(['project_id' => $project->id]);
    for ($i = 0; $i < 20; $i++) {
        FindingAcceptance::create([
            'task_id' => $task1->id,
            'project_id' => $project->id,
            'mr_iid' => 1,
            'finding_id' => (string) $i,
            'file' => 'src/test.php',
            'line' => $i,
            'severity' => 'major',
            'title' => "Finding $i",
            'status' => 'accepted',
            'emoji_positive_count' => 0,
            'emoji_negative_count' => 0,
            'emoji_sentiment' => 'neutral',
            'created_at' => $now->copy()->subWeeks(2)->addDay(),
            'updated_at' => $now->copy()->subWeeks(2)->addDay(),
        ]);
    }

    $task2 = Task::factory()->create(['project_id' => $project->id]);
    for ($i = 0; $i < 20; $i++) {
        FindingAcceptance::create([
            'task_id' => $task2->id,
            'project_id' => $project->id,
            'mr_iid' => 2,
            'finding_id' => (string) $i,
            'file' => 'src/test.php',
            'line' => $i,
            'severity' => 'major',
            'title' => "Finding $i",
            'status' => 'accepted',
            'emoji_positive_count' => 0,
            'emoji_negative_count' => 0,
            'emoji_sentiment' => 'neutral',
            'created_at' => $now->copy()->subWeek()->addDay(),
            'updated_at' => $now->copy()->subWeek()->addDay(),
        ]);
    }

    $this->artisan('overreliance:evaluate')
        ->assertExitCode(0);

    // At least one alert should have been created
    expect(OverrelianceAlert::count())->toBeGreaterThanOrEqual(1);

    Carbon::setTestNow(null);
});

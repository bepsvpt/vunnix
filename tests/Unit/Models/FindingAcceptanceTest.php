<?php

use App\Models\FindingAcceptance;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('defines a task BelongsTo relationship', function (): void {
    $finding = new FindingAcceptance;
    $relation = $finding->task();

    expect($relation)->toBeInstanceOf(BelongsTo::class);
    expect($relation->getRelated())->toBeInstanceOf(Task::class);
});

it('defines a project BelongsTo relationship', function (): void {
    $finding = new FindingAcceptance;
    $relation = $finding->project();

    expect($relation)->toBeInstanceOf(BelongsTo::class);
    expect($relation->getRelated())->toBeInstanceOf(Project::class);
});

it('loads task relationship from database', function (): void {
    $task = Task::factory()->create();
    $project = Project::find($task->project_id);

    $finding = FindingAcceptance::create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'mr_iid' => 10,
        'finding_id' => '1',
        'file' => 'src/app.php',
        'line' => 42,
        'severity' => 'major',
        'title' => 'Test finding',
        'status' => 'pending',
    ]);

    $loaded = FindingAcceptance::with('task')->find($finding->id);

    expect($loaded->task)->toBeInstanceOf(Task::class)
        ->and($loaded->task->id)->toBe($task->id);
});

it('loads project relationship from database', function (): void {
    $task = Task::factory()->create();
    $project = Project::find($task->project_id);

    $finding = FindingAcceptance::create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'mr_iid' => 10,
        'finding_id' => '2',
        'file' => 'src/app.php',
        'line' => 50,
        'severity' => 'minor',
        'title' => 'Another finding',
        'status' => 'pending',
    ]);

    $loaded = FindingAcceptance::with('project')->find($finding->id);

    expect($loaded->project)->toBeInstanceOf(Project::class)
        ->and($loaded->project->id)->toBe($project->id);
});

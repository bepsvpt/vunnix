<?php

use App\Models\HealthSnapshot;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('casts details and score correctly', function (): void {
    $snapshot = HealthSnapshot::factory()->create([
        'details' => ['coverage_percent' => 81.5],
        'score' => '81.50',
    ]);

    expect($snapshot->details)->toBeArray()
        ->and($snapshot->score)->toBeFloat()
        ->and($snapshot->score)->toBe(81.5);
});

it('filters by project, dimension, and recent scopes', function (): void {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();

    HealthSnapshot::factory()->create([
        'project_id' => $project->id,
        'dimension' => 'coverage',
        'created_at' => now()->subDays(2),
    ]);

    HealthSnapshot::factory()->create([
        'project_id' => $project->id,
        'dimension' => 'dependency',
        'created_at' => now()->subDays(40),
    ]);

    HealthSnapshot::factory()->create([
        'project_id' => $otherProject->id,
        'dimension' => 'coverage',
    ]);

    $count = HealthSnapshot::query()
        ->forProject($project->id)
        ->ofDimension('coverage')
        ->recent(30)
        ->count();

    expect($count)->toBe(1);
});

it('belongs to a project', function (): void {
    $project = Project::factory()->create();
    $snapshot = HealthSnapshot::factory()->create(['project_id' => $project->id]);

    expect($snapshot->project->is($project))->toBeTrue();
});

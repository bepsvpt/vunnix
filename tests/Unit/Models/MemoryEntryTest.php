<?php

use App\Models\MemoryEntry;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('casts structured fields correctly', function (): void {
    $entry = MemoryEntry::factory()->create([
        'content' => ['pattern' => 'Use queue workers for async jobs'],
        'source_meta' => ['conversation_id' => 'conv-1'],
        'confidence' => '80',
        'applied_count' => '2',
    ]);

    expect($entry->content)->toBeArray()
        ->and($entry->source_meta)->toBeArray()
        ->and($entry->confidence)->toBeInt()
        ->and($entry->applied_count)->toBeInt();
});

it('active scope excludes archived entries', function (): void {
    $project = Project::factory()->create();
    MemoryEntry::factory()->create(['project_id' => $project->id, 'archived_at' => null]);
    MemoryEntry::factory()->create(['project_id' => $project->id, 'archived_at' => now()]);

    expect(MemoryEntry::query()->forProject($project->id)->active()->count())->toBe(1);
});

it('filters by project, type, and confidence scopes', function (): void {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();

    MemoryEntry::factory()->create([
        'project_id' => $projectA->id,
        'type' => 'review_pattern',
        'confidence' => 75,
    ]);
    MemoryEntry::factory()->create([
        'project_id' => $projectA->id,
        'type' => 'conversation_fact',
        'confidence' => 30,
    ]);
    MemoryEntry::factory()->create([
        'project_id' => $projectB->id,
        'type' => 'review_pattern',
        'confidence' => 90,
    ]);

    $count = MemoryEntry::query()
        ->forProject($projectA->id)
        ->ofType('review_pattern')
        ->highConfidence(40)
        ->count();

    expect($count)->toBe(1);
});

it('belongs to project and optional source task', function (): void {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);
    $entry = MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'source_task_id' => $task->id,
    ]);

    expect($entry->project->is($project))->toBeTrue();
    expect($entry->sourceTask?->is($task))->toBeTrue();
});

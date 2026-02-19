<?php

use App\Models\MemoryEntry;
use App\Models\Project;
use App\Services\ProjectMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'vunnix.memory.enabled' => true,
        'vunnix.memory.min_confidence' => 40,
        'vunnix.memory.retention_days' => 90,
    ]);
});

it('returns active memories ordered by confidence', function (): void {
    $project = Project::factory()->create();
    MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'review_pattern',
        'confidence' => 50,
        'archived_at' => null,
    ]);
    MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'review_pattern',
        'confidence' => 80,
        'archived_at' => null,
    ]);
    MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'review_pattern',
        'confidence' => 90,
        'archived_at' => now(),
    ]);

    $service = app(ProjectMemoryService::class);
    $entries = $service->getActiveMemories($project, 'review_pattern');

    expect($entries)->toHaveCount(2);
    expect($entries->first()?->confidence)->toBe(80);
});

it('archives expired entries based on retention window', function (): void {
    $project = Project::factory()->create();
    $old = MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'archived_at' => null,
    ]);
    $fresh = MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'archived_at' => null,
    ]);

    $old->forceFill(['created_at' => now()->subDays(120)])->save();
    $fresh->forceFill(['created_at' => now()->subDays(10)])->save();

    $service = app(ProjectMemoryService::class);
    $archivedCount = $service->archiveExpired($project);

    expect($archivedCount)->toBe(1);
    expect($old->fresh()?->archived_at)->not->toBeNull();
    expect($fresh->fresh()?->archived_at)->toBeNull();
});

it('records applied usage count', function (): void {
    $entry = MemoryEntry::factory()->create(['applied_count' => 0]);

    $service = app(ProjectMemoryService::class);
    $service->recordApplied($entry);

    expect($entry->fresh()?->applied_count)->toBe(1);
});

it('returns aggregate stats for a project', function (): void {
    $project = Project::factory()->create();
    MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'review_pattern',
        'category' => 'false_positive',
        'confidence' => 80,
    ]);
    MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'conversation_fact',
        'category' => 'fact',
        'confidence' => 60,
    ]);

    $service = app(ProjectMemoryService::class);
    $stats = $service->getStats($project);

    expect($stats['total_entries'])->toBe(2);
    expect($stats['by_type']['review_pattern'])->toBe(1);
    expect($stats['average_confidence'])->toBe(70.0);
    expect($stats['last_created_at'])->not->toBeNull();
});

it('archives a single entry via deleteEntry', function (): void {
    $entry = MemoryEntry::factory()->create([
        'archived_at' => null,
    ]);

    $service = app(ProjectMemoryService::class);
    $service->deleteEntry($entry);

    expect($entry->fresh()?->archived_at)->not->toBeNull();
});

it('returns safe defaults when memory is globally disabled', function (): void {
    config([
        'vunnix.memory.enabled' => false,
    ]);

    $project = Project::factory()->create();
    $entry = MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'applied_count' => 0,
    ]);

    $service = app(ProjectMemoryService::class);

    expect($service->getActiveMemories($project))->toHaveCount(0);
    expect($service->archiveExpired($project))->toBe(0);

    $service->recordApplied($entry);
    expect($entry->fresh()?->applied_count)->toBe(0);

    $service->deleteEntry($entry);
    expect($entry->fresh()?->archived_at)->toBeNull();

    expect($service->getStats($project))->toBe([
        'total_entries' => 0,
        'by_type' => [],
        'by_category' => [],
        'average_confidence' => 0.0,
        'last_created_at' => null,
    ]);
});

it('gracefully handles schema lookup failures as unavailable memory', function (): void {
    config(['vunnix.memory.enabled' => true]);

    Schema::shouldReceive('hasTable')->andThrow(new RuntimeException('db unavailable'));

    $project = Project::factory()->create();
    $service = app(ProjectMemoryService::class);

    expect($service->getActiveMemories($project))->toHaveCount(0);
});

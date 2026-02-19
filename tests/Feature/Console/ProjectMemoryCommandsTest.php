<?php

use App\Models\MemoryEntry;
use App\Models\Project;
use App\Services\MemoryExtractionService;
use App\Services\ProjectMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('runs memory:analyze-patterns without error', function (): void {
    config([
        'vunnix.memory.enabled' => true,
        'vunnix.memory.cross_mr_patterns' => true,
    ]);

    Project::factory()->enabled()->create();

    $this->artisan('memory:analyze-patterns')
        ->assertSuccessful();
});

it('skips cross-MR analysis when feature flags are disabled', function (): void {
    config([
        'vunnix.memory.enabled' => false,
        'vunnix.memory.cross_mr_patterns' => true,
    ]);

    $this->artisan('memory:analyze-patterns')
        ->expectsOutput('Cross-MR pattern analysis disabled via feature flag.')
        ->assertSuccessful();
});

it('creates entries during cross-MR analysis and reports singular output', function (): void {
    config([
        'vunnix.memory.enabled' => true,
        'vunnix.memory.cross_mr_patterns' => true,
    ]);

    $project = Project::factory()->enabled()->create();
    $entry = new MemoryEntry([
        'project_id' => $project->id,
        'type' => 'cross_mr_pattern',
        'category' => 'hotspot',
        'content' => ['pattern' => 'Hotspot in app/Services/TaskDispatcher.php'],
        'confidence' => 95,
        'applied_count' => 0,
    ]);

    $extraction = Mockery::mock(MemoryExtractionService::class);
    $extraction->shouldReceive('detectCrossMRPatterns')
        ->once()
        ->withArgs(fn (Project $arg): bool => $arg->is($project))
        ->andReturn(collect([$entry]));
    app()->instance(MemoryExtractionService::class, $extraction);

    $memoryService = Mockery::mock(ProjectMemoryService::class);
    $memoryService->shouldReceive('invalidateProjectCache')->once()->with($project->id);
    app()->instance(ProjectMemoryService::class, $memoryService);

    $this->artisan('memory:analyze-patterns')
        ->expectsOutput('Created 1 cross-MR memory entry.')
        ->assertSuccessful();

    expect(
        MemoryEntry::query()
            ->where('project_id', $project->id)
            ->where('type', 'cross_mr_pattern')
            ->count()
    )->toBe(1);
});

it('archives expired entries via memory:archive-expired', function (): void {
    config([
        'vunnix.memory.enabled' => true,
        'vunnix.memory.retention_days' => 30,
    ]);

    $project = Project::factory()->create();
    $entry = MemoryEntry::factory()->create(['project_id' => $project->id, 'archived_at' => null]);
    $entry->forceFill(['created_at' => now()->subDays(60)])->save();

    $this->artisan('memory:archive-expired')
        ->assertSuccessful();

    expect($entry->fresh()?->archived_at)->not->toBeNull();
});

it('prints no-op message when there are no expired memory entries', function (): void {
    config([
        'vunnix.memory.enabled' => true,
        'vunnix.memory.retention_days' => 30,
    ]);

    Project::factory()->create();

    $this->artisan('memory:archive-expired')
        ->expectsOutput('No expired memory entries to archive.')
        ->assertSuccessful();
});

<?php

use App\Jobs\ExtractReviewPatterns;
use App\Models\FindingAcceptance;
use App\Models\MemoryEntry;
use App\Models\Project;
use App\Models\Task;
use App\Services\MemoryExtractionService;
use App\Services\MemoryInjectionService;
use App\Services\ProjectMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('runs the project memory flywheel end-to-end', function (): void {
    config([
        'vunnix.memory.enabled' => true,
        'vunnix.memory.review_learning' => true,
        'vunnix.memory.min_sample_size' => 5,
        'vunnix.memory.retention_days' => 0,
    ]);

    $project = Project::factory()->enabled()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    // >60% dismissed in same category => false-positive pattern
    FindingAcceptance::factory()->count(5)->create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'category' => 'style',
        'status' => 'dismissed',
        'severity' => 'minor',
        'title' => 'Stylistic spacing issue',
    ]);
    FindingAcceptance::factory()->count(2)->create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'category' => 'style',
        'status' => 'accepted',
        'severity' => 'minor',
        'title' => 'Stylistic spacing issue',
    ]);

    (new ExtractReviewPatterns($task->id))->handle(
        app(MemoryExtractionService::class),
        app(ProjectMemoryService::class),
    );

    $entry = MemoryEntry::query()
        ->where('project_id', $project->id)
        ->where('type', 'review_pattern')
        ->first();

    expect($entry)->not->toBeNull();
    expect((string) ($entry?->content['pattern'] ?? ''))->toContain('style');

    $guidance = app(MemoryInjectionService::class)->buildReviewGuidance($project);
    expect($guidance)->toContain('style');

    MemoryEntry::query()
        ->where('project_id', $project->id)
        ->update(['created_at' => now()->subDay()]);

    $archivedCount = app(ProjectMemoryService::class)->archiveExpired($project);
    expect($archivedCount)->toBeGreaterThan(0);
    expect(MemoryEntry::query()->where('project_id', $project->id)->whereNotNull('archived_at')->count())->toBeGreaterThan(0);
});

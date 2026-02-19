<?php

use App\Models\FindingAcceptance;
use App\Models\Project;
use App\Models\Task;
use App\Services\MemoryExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'vunnix.memory.enabled' => true,
        'vunnix.memory.min_sample_size' => 3,
    ]);
});

it('extracts false-positive review patterns from dismissal-heavy findings', function (): void {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    FindingAcceptance::factory()->count(4)->create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'category' => 'style',
        'status' => 'dismissed',
        'severity' => 'minor',
    ]);
    FindingAcceptance::factory()->create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'category' => 'style',
        'status' => 'accepted',
        'severity' => 'minor',
    ]);

    $service = app(MemoryExtractionService::class);
    $entries = $service->extractFromFindings($project, FindingAcceptance::where('project_id', $project->id)->get());

    expect($entries->where('category', 'false_positive')->count())->toBeGreaterThan(0);
});

it('extracts technical conversation facts from summaries', function (): void {
    $project = Project::factory()->create();
    $summary = 'We decided to use Redis queues for background processing. We also talked about lunch.';

    $service = app(MemoryExtractionService::class);
    $entries = $service->extractFromConversationSummary($project, $summary, ['conversation_id' => 'conv-123']);

    expect($entries)->toHaveCount(1);
    expect($entries->first()?->type)->toBe('conversation_fact');
    expect($entries->first()?->source_meta['conversation_id'])->toBe('conv-123');
});

it('detects cross-MR hotspots and conventions', function (): void {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    foreach ([1, 2, 3] as $mrIid) {
        FindingAcceptance::factory()->create([
            'task_id' => $task->id,
            'project_id' => $project->id,
            'mr_iid' => $mrIid,
            'file' => 'app/Services/TaskDispatcher.php',
            'category' => 'performance',
            'title' => 'Inefficient loop',
            'status' => 'dismissed',
        ]);
    }

    FindingAcceptance::factory()->create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'mr_iid' => 4,
        'title' => 'Inefficient loop',
        'status' => 'dismissed',
    ]);

    $service = app(MemoryExtractionService::class);
    $entries = $service->detectCrossMRPatterns($project, 60);

    expect($entries->where('category', 'hotspot')->count())->toBeGreaterThan(0);
    expect($entries->where('category', 'convention')->count())->toBeGreaterThan(0);
});

it('returns no findings patterns for empty datasets', function (): void {
    $project = Project::factory()->create();

    $service = app(MemoryExtractionService::class);
    $entries = $service->extractFromFindings($project, collect());

    expect($entries)->toHaveCount(0);
});

it('extracts severity calibration patterns when acceptance drifts from baseline', function (): void {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    config(['vunnix.memory.min_sample_size' => 2]);

    FindingAcceptance::factory()->count(4)->create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'severity' => 'major',
        'status' => 'accepted',
        'category' => 'bug',
    ]);
    FindingAcceptance::factory()->count(2)->create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'severity' => 'minor',
        'status' => 'dismissed',
        'category' => 'style',
    ]);

    $service = app(MemoryExtractionService::class);
    $entries = $service->extractFromFindings($project, FindingAcceptance::where('project_id', $project->id)->get());

    expect($entries->where('category', 'severity_calibration')->count())->toBeGreaterThan(0);
});

it('deduplicates conversation facts against existing similar memory entries', function (): void {
    $project = Project::factory()->create();

    \App\Models\MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'conversation_fact',
        'content' => ['fact' => 'We decided to use Redis queue workers for async jobs.'],
        'archived_at' => null,
    ]);

    $service = app(MemoryExtractionService::class);
    $entries = $service->extractFromConversationSummary(
        $project,
        'We decided to use redis queue workers for async jobs.',
        ['conversation_id' => 'conv-dedupe'],
    );

    expect($entries)->toHaveCount(0);
});

it('returns empty conversation facts for empty summaries', function (): void {
    $project = Project::factory()->create();

    $service = app(MemoryExtractionService::class);
    $entries = $service->extractFromConversationSummary($project, '   ', []);

    expect($entries)->toHaveCount(0);
});

it('returns no cross-MR patterns when there are no findings in lookback window', function (): void {
    $project = Project::factory()->create();

    $service = app(MemoryExtractionService::class);
    $entries = $service->detectCrossMRPatterns($project, 1);

    expect($entries)->toHaveCount(0);
});

it('does not duplicate an existing extracted review pattern', function (): void {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    config(['vunnix.memory.min_sample_size' => 3]);

    FindingAcceptance::factory()->count(4)->create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'category' => 'style',
        'status' => 'dismissed',
        'severity' => 'minor',
    ]);
    FindingAcceptance::factory()->create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'category' => 'style',
        'status' => 'accepted',
        'severity' => 'minor',
    ]);

    \App\Models\MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'review_pattern',
        'category' => 'false_positive',
        'confidence' => 5,
        'content' => [
            'pattern' => 'Findings in category "style" are frequently dismissed (80% dismissal over 5 samples).',
        ],
    ]);

    $service = app(MemoryExtractionService::class);
    $entries = $service->extractFromFindings($project, FindingAcceptance::where('project_id', $project->id)->get());

    expect($entries->where('category', 'false_positive'))->toHaveCount(0);
});

it('skips findings groups below minimum sample size', function (): void {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    config(['vunnix.memory.min_sample_size' => 5]);

    FindingAcceptance::factory()->count(4)->create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'category' => 'style',
        'status' => 'dismissed',
        'severity' => 'minor',
    ]);

    $service = app(MemoryExtractionService::class);
    $entries = $service->extractFromFindings($project, FindingAcceptance::where('project_id', $project->id)->get());

    expect($entries)->toHaveCount(0);
});

it('filters non-technical or too-short conversation sentences', function (): void {
    $project = Project::factory()->create();
    $summary = "   \nUse Redis.\nWe also talked about lunch.";

    $service = app(MemoryExtractionService::class);
    $entries = $service->extractFromConversationSummary($project, $summary, []);

    expect($entries)->toHaveCount(0);
});

it('avoids duplicating existing cross-MR hotspot and convention patterns', function (): void {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    foreach ([1, 2, 3] as $mrIid) {
        FindingAcceptance::factory()->create([
            'task_id' => $task->id,
            'project_id' => $project->id,
            'mr_iid' => $mrIid,
            'file' => 'app/Services/TaskDispatcher.php',
            'category' => 'performance',
            'title' => 'Inefficient loop',
            'status' => 'dismissed',
        ]);
    }

    // Add one repeated dismissal title with only one MR to exercise the <2 guard.
    FindingAcceptance::factory()->create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'mr_iid' => 99,
        'file' => 'app/Models/Task.php',
        'category' => 'style',
        'title' => 'Single dismissal',
        'status' => 'dismissed',
    ]);

    \App\Models\MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'cross_mr_pattern',
        'category' => 'hotspot',
        'confidence' => 60,
        'content' => [
            'pattern' => 'File hotspot detected: app/Services/TaskDispatcher.php was flagged 3 times across 3 merge requests.',
        ],
    ]);

    \App\Models\MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'cross_mr_pattern',
        'category' => 'convention',
        'confidence' => 60,
        'content' => [
            'pattern' => 'Category cluster: "performance" appears across 3 merge requests (3 findings).',
        ],
    ]);

    \App\Models\MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'cross_mr_pattern',
        'category' => 'convention',
        'confidence' => 60,
        'content' => [
            'pattern' => 'Repeated dismissal pattern: "Inefficient loop" was dismissed in 3 merge requests.',
        ],
    ]);

    $service = app(MemoryExtractionService::class);
    $entries = $service->detectCrossMRPatterns($project, 60);

    expect($entries)->toHaveCount(0);
});

<?php

use App\Models\MemoryEntry;
use App\Models\Project;
use App\Services\MemoryInjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'vunnix.memory.enabled' => true,
        'vunnix.memory.review_learning' => true,
        'vunnix.memory.conversation_continuity' => true,
        'vunnix.memory.cross_mr_patterns' => true,
        'vunnix.memory.max_context_tokens' => 2000,
        'vunnix.memory.min_confidence' => 40,
    ]);
});

it('builds review guidance and records entry usage', function (): void {
    $project = Project::factory()->create();
    $entry = MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'review_pattern',
        'content' => ['pattern' => 'Logic regressions are accepted less often than style findings.'],
        'applied_count' => 0,
    ]);

    $service = app(MemoryInjectionService::class);
    $guidance = $service->buildReviewGuidance($project);

    expect($guidance)->toContain('Logic regressions');
    expect($entry->fresh()?->applied_count)->toBe(1);
});

it('enforces max context token cap', function (): void {
    config(['vunnix.memory.max_context_tokens' => 4]);

    $project = Project::factory()->create();
    MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'review_pattern',
        'confidence' => 90,
        'content' => ['pattern' => 'Short pattern'],
    ]);
    MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'review_pattern',
        'confidence' => 80,
        'content' => ['pattern' => 'This second pattern should not fit in the very small token budget'],
    ]);

    $service = app(MemoryInjectionService::class);
    $guidance = $service->buildReviewGuidance($project);

    expect($guidance)->toContain('Short pattern');
    expect($guidance)->not->toContain('second pattern');
});

it('returns empty strings for projects without memory', function (): void {
    $project = Project::factory()->create();

    $service = app(MemoryInjectionService::class);

    expect($service->buildReviewGuidance($project))->toBe('');
    expect($service->buildConversationContext($project))->toBe('');
    expect($service->buildCrossMRContext($project))->toBe('');
});

it('returns empty guidance when review-learning flag is disabled', function (): void {
    config([
        'vunnix.memory.enabled' => true,
        'vunnix.memory.review_learning' => false,
    ]);

    $project = Project::factory()->create();
    MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'review_pattern',
        'content' => ['pattern' => 'Should be ignored by flag'],
    ]);

    $service = app(MemoryInjectionService::class);

    expect($service->buildReviewGuidance($project))->toBe('');
});

it('builds conversation and cross-MR context when enabled', function (): void {
    $project = Project::factory()->create();

    $fact = MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'conversation_fact',
        'content' => ['pattern' => 'Fallback pattern fact only'],
        'applied_count' => 0,
    ]);
    $crossMr = MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'cross_mr_pattern',
        'content' => ['pattern' => 'Hotspot in app/Services/TaskDispatcher.php'],
        'applied_count' => 0,
    ]);

    $service = app(MemoryInjectionService::class);
    $conversation = $service->buildConversationContext($project);
    $crossMrText = $service->buildCrossMRContext($project);

    expect($conversation)->toContain('Fallback pattern fact only');
    expect($crossMrText)->toContain('Hotspot in app/Services/TaskDispatcher.php');
    expect($fact->fresh()?->applied_count)->toBe(1);
    expect($crossMr->fresh()?->applied_count)->toBe(1);
});

it('returns empty context when capability flags are disabled', function (): void {
    config([
        'vunnix.memory.enabled' => true,
        'vunnix.memory.conversation_continuity' => false,
        'vunnix.memory.cross_mr_patterns' => false,
    ]);

    $project = Project::factory()->create();
    MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'conversation_fact',
        'content' => ['fact' => 'Should be ignored'],
    ]);
    MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'cross_mr_pattern',
        'content' => ['pattern' => 'Should be ignored'],
    ]);

    $service = app(MemoryInjectionService::class);

    expect($service->buildConversationContext($project))->toBe('');
    expect($service->buildCrossMRContext($project))->toBe('');
});

it('builds health guidance from health_signal memory entries', function (): void {
    config(['health.enabled' => true]);

    $project = Project::factory()->create();
    MemoryEntry::factory()->create([
        'project_id' => $project->id,
        'type' => 'health_signal',
        'category' => 'coverage',
        'content' => ['signal' => 'Test coverage is at 68% (warning 70%).'],
    ]);

    $service = app(MemoryInjectionService::class);
    $guidance = $service->buildHealthGuidance($project);

    expect($guidance)->toContain('Test coverage is at 68%');
});

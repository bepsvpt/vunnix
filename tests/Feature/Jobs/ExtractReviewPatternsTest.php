<?php

use App\Jobs\ExtractReviewPatterns;
use App\Models\FindingAcceptance;
use App\Models\MemoryEntry;
use App\Models\Project;
use App\Models\Task;
use App\Services\MemoryExtractionService;
use App\Services\ProjectMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('creates memory entries from finding acceptances', function (): void {
    config([
        'vunnix.memory.enabled' => true,
        'vunnix.memory.review_learning' => true,
        'vunnix.memory.min_sample_size' => 3,
    ]);

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

    $job = new ExtractReviewPatterns($task->id);
    $job->handle(
        app(MemoryExtractionService::class),
        app(ProjectMemoryService::class),
    );

    expect(MemoryEntry::where('project_id', $project->id)->count())->toBeGreaterThan(0);
    expect(MemoryEntry::where('project_id', $project->id)->where('type', 'review_pattern')->exists())->toBeTrue();
});

it('returns early when task does not exist', function (): void {
    $extraction = Mockery::mock(MemoryExtractionService::class);
    $extraction->shouldNotReceive('extractFromFindings');
    $memory = Mockery::mock(ProjectMemoryService::class);
    $memory->shouldNotReceive('invalidateProjectCache');

    $job = new ExtractReviewPatterns(999999);
    $job->handle($extraction, $memory);
});

it('logs a warning when extraction fails and does not rethrow', function (): void {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    $extraction = Mockery::mock(MemoryExtractionService::class);
    $extraction->shouldReceive('extractFromFindings')->once()->andThrow(new RuntimeException('boom'));

    $memory = Mockery::mock(ProjectMemoryService::class);
    $memory->shouldNotReceive('invalidateProjectCache');

    Log::spy();

    $job = new ExtractReviewPatterns($task->id);
    $job->handle($extraction, $memory);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'ExtractReviewPatterns failed'
            && $context['task_id'] === $task->id);
});

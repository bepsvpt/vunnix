<?php

use App\Enums\TaskType;
use App\Jobs\ExtractReviewPatterns;
use App\Jobs\PostInlineThreads;
use App\Jobs\PostLabelsAndStatus;
use App\Jobs\PostSummaryComment;
use App\Jobs\ProcessTaskResult;
use App\Models\FindingAcceptance;
use App\Models\Project;
use App\Models\Task;
use App\Services\MemoryExtractionService;
use App\Services\MemoryInjectionService;
use App\Services\ProjectMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'vunnix.memory.enabled' => true,
        'vunnix.memory.review_learning' => true,
        'vunnix.memory.min_sample_size' => 3,
    ]);
});

it('dispatches ExtractReviewPatterns from ProcessTaskResult for review tasks with findings', function (): void {
    Queue::fake([
        PostSummaryComment::class,
        PostInlineThreads::class,
        PostLabelsAndStatus::class,
        ExtractReviewPatterns::class,
    ]);

    $task = Task::factory()->running()->create([
        'type' => TaskType::CodeReview,
        'mr_iid' => 12,
        'result' => [
            'version' => '1.0',
            'summary' => [
                'risk_level' => 'medium',
                'total_findings' => 1,
                'findings_by_severity' => ['critical' => 0, 'major' => 1, 'minor' => 0],
                'walkthrough' => [
                    ['file' => 'app/Services/Example.php', 'change_summary' => 'Logic update'],
                ],
            ],
            'findings' => [[
                'id' => 1,
                'severity' => 'major',
                'category' => 'bug',
                'file' => 'app/Services/Example.php',
                'line' => 20,
                'end_line' => 20,
                'title' => 'Possible null reference',
                'description' => 'A variable may be null before use.',
                'suggestion' => 'Guard before using it.',
                'labels' => [],
            ]],
            'labels' => ['ai::needs-work'],
            'commit_status' => 'failed',
        ],
    ]);

    (new ProcessTaskResult($task->id))->handle(app(\App\Services\ResultProcessor::class));

    Queue::assertPushed(ExtractReviewPatterns::class, fn ($job): bool => $job->taskId === $task->id);
});

it('builds guidance after extraction creates memory entries', function (): void {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    FindingAcceptance::factory()->count(4)->create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'category' => 'style',
        'status' => 'dismissed',
        'severity' => 'minor',
    ]);
    FindingAcceptance::factory()->create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'category' => 'style',
        'status' => 'accepted',
        'severity' => 'minor',
    ]);

    (new ExtractReviewPatterns($task->id))->handle(
        app(MemoryExtractionService::class),
        app(ProjectMemoryService::class),
    );

    $guidance = app(MemoryInjectionService::class)->buildReviewGuidance($project);

    expect($guidance)->not->toBe('');
    expect($guidance)->toContain('style');
});

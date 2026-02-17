<?php

use App\Enums\TaskType;
use App\Jobs\PostInlineThreads;
use App\Jobs\PostLabelsAndStatus;
use App\Jobs\PostSummaryComment;
use App\Jobs\ProcessTaskResult;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ─── Task not found → logs warning and returns early ─────────────

it('logs warning and returns early when task does not exist', function (): void {
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class, PostLabelsAndStatus::class]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'ProcessTaskResult: task not found')
                && $context['task_id'] === 999999;
        });

    $job = new ProcessTaskResult(999999);
    $job->handle(app(\App\Services\ResultProcessor::class));

    Queue::assertNothingPushed();
});

// ─── Task no longer Running → logs info and returns early ────────

it('logs info and returns early when task is already Completed', function (): void {
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class, PostLabelsAndStatus::class]);

    $task = Task::factory()->completed()->create([
        'type' => TaskType::CodeReview,
        'mr_iid' => 42,
        'result' => [
            'version' => '1.0',
            'summary' => [
                'risk_level' => 'low',
                'total_findings' => 0,
                'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 0],
                'walkthrough' => [
                    ['file' => 'README.md', 'change_summary' => 'Updated docs'],
                ],
            ],
            'findings' => [],
            'labels' => ['ai::reviewed', 'ai::risk-low'],
            'commit_status' => 'success',
        ],
    ]);

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context) use ($task): bool {
            return str_contains($message, 'ProcessTaskResult: task no longer running')
                && $context['task_id'] === $task->id
                && $context['status'] === 'completed';
        });

    $job = new ProcessTaskResult($task->id);
    $job->handle(app(\App\Services\ResultProcessor::class));

    Queue::assertNothingPushed();
});

it('logs info and returns early when task is already Failed', function (): void {
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class, PostLabelsAndStatus::class]);

    $task = Task::factory()->failed()->create([
        'type' => TaskType::CodeReview,
        'mr_iid' => 42,
        'result' => [
            'version' => '1.0',
            'summary' => [
                'risk_level' => 'low',
                'total_findings' => 0,
                'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 0],
                'walkthrough' => [
                    ['file' => 'README.md', 'change_summary' => 'Updated docs'],
                ],
            ],
            'findings' => [],
            'labels' => ['ai::reviewed', 'ai::risk-low'],
            'commit_status' => 'success',
        ],
    ]);

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context) use ($task): bool {
            return str_contains($message, 'ProcessTaskResult: task no longer running')
                && $context['task_id'] === $task->id
                && $context['status'] === 'failed';
        });

    $job = new ProcessTaskResult($task->id);
    $job->handle(app(\App\Services\ResultProcessor::class));

    Queue::assertNothingPushed();
});

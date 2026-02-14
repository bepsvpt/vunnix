<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\PostInlineThreads;
use App\Jobs\PostLabelsAndStatus;
use App\Jobs\PostSummaryComment;
use App\Jobs\ProcessTaskResult;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('dispatches PostSummaryComment after successful code review processing', function () {
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class, PostLabelsAndStatus::class]);

    $task = Task::factory()->running()->create([
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

    $job = new ProcessTaskResult($task->id);
    $job->handle(app(\App\Services\ResultProcessor::class));

    Queue::assertPushed(PostSummaryComment::class, function ($job) use ($task) {
        return $job->taskId === $task->id;
    });
});

it('dispatches PostSummaryComment after successful security audit processing', function () {
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class, PostLabelsAndStatus::class]);

    $task = Task::factory()->running()->create([
        'type' => TaskType::SecurityAudit,
        'mr_iid' => 10,
        'result' => [
            'version' => '1.0',
            'summary' => [
                'risk_level' => 'low',
                'total_findings' => 0,
                'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 0],
                'walkthrough' => [
                    ['file' => 'src/app.py', 'change_summary' => 'Reviewed security'],
                ],
            ],
            'findings' => [],
            'labels' => ['ai::reviewed'],
            'commit_status' => 'success',
        ],
    ]);

    $job = new ProcessTaskResult($task->id);
    $job->handle(app(\App\Services\ResultProcessor::class));

    Queue::assertPushed(PostSummaryComment::class);
});

it('does not dispatch PostSummaryComment for non-review task types', function () {
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class, PostLabelsAndStatus::class]);

    $task = Task::factory()->running()->create([
        'type' => TaskType::FeatureDev,
        'result' => [
            'version' => '1.0',
            'branch' => 'ai/test-feature',
            'mr_title' => 'Test feature',
            'mr_description' => 'A test feature.',
            'files_changed' => [
                ['path' => 'src/test.py', 'action' => 'created', 'summary' => 'New file'],
            ],
            'tests_added' => true,
            'notes' => 'Done.',
        ],
    ]);

    $job = new ProcessTaskResult($task->id);
    $job->handle(app(\App\Services\ResultProcessor::class));

    Queue::assertNotPushed(PostSummaryComment::class);
});

it('does not dispatch PostSummaryComment when validation fails', function () {
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class, PostLabelsAndStatus::class]);

    $task = Task::factory()->running()->create([
        'type' => TaskType::CodeReview,
        'mr_iid' => 42,
        'result' => ['invalid' => 'not a valid schema'],
    ]);

    $job = new ProcessTaskResult($task->id);
    $job->handle(app(\App\Services\ResultProcessor::class));

    Queue::assertNotPushed(PostSummaryComment::class);
});

it('does not dispatch PostSummaryComment for tasks without mr_iid', function () {
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class, PostLabelsAndStatus::class]);

    $task = Task::factory()->running()->create([
        'type' => TaskType::CodeReview,
        'mr_iid' => null,
        'result' => [
            'version' => '1.0',
            'summary' => [
                'risk_level' => 'low',
                'total_findings' => 0,
                'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 0],
                'walkthrough' => [
                    ['file' => 'README.md', 'change_summary' => 'Updated'],
                ],
            ],
            'findings' => [],
            'labels' => ['ai::reviewed'],
            'commit_status' => 'success',
        ],
    ]);

    $job = new ProcessTaskResult($task->id);
    $job->handle(app(\App\Services\ResultProcessor::class));

    Queue::assertNotPushed(PostSummaryComment::class);
});

// ─── PostInlineThreads dispatch tests ───────────────────────────

it('dispatches PostInlineThreads after successful code review processing', function () {
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class, PostLabelsAndStatus::class]);

    $task = Task::factory()->running()->create([
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

    $job = new ProcessTaskResult($task->id);
    $job->handle(app(\App\Services\ResultProcessor::class));

    Queue::assertPushed(PostInlineThreads::class, function ($job) use ($task) {
        return $job->taskId === $task->id;
    });
});

it('dispatches PostInlineThreads after successful security audit processing', function () {
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class, PostLabelsAndStatus::class]);

    $task = Task::factory()->running()->create([
        'type' => TaskType::SecurityAudit,
        'mr_iid' => 10,
        'result' => [
            'version' => '1.0',
            'summary' => [
                'risk_level' => 'low',
                'total_findings' => 0,
                'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 0],
                'walkthrough' => [
                    ['file' => 'src/app.py', 'change_summary' => 'Reviewed security'],
                ],
            ],
            'findings' => [],
            'labels' => ['ai::reviewed'],
            'commit_status' => 'success',
        ],
    ]);

    $job = new ProcessTaskResult($task->id);
    $job->handle(app(\App\Services\ResultProcessor::class));

    Queue::assertPushed(PostInlineThreads::class);
});

it('does not dispatch PostInlineThreads for non-review task types', function () {
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class, PostLabelsAndStatus::class]);

    $task = Task::factory()->running()->create([
        'type' => TaskType::FeatureDev,
        'result' => [
            'version' => '1.0',
            'branch' => 'ai/test-feature',
            'mr_title' => 'Test feature',
            'mr_description' => 'A test feature.',
            'files_changed' => [
                ['path' => 'src/test.py', 'action' => 'created', 'summary' => 'New file'],
            ],
            'tests_added' => true,
            'notes' => 'Done.',
        ],
    ]);

    $job = new ProcessTaskResult($task->id);
    $job->handle(app(\App\Services\ResultProcessor::class));

    Queue::assertNotPushed(PostInlineThreads::class);
});

it('does not dispatch PostInlineThreads when validation fails', function () {
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class, PostLabelsAndStatus::class]);

    $task = Task::factory()->running()->create([
        'type' => TaskType::CodeReview,
        'mr_iid' => 42,
        'result' => ['invalid' => 'not a valid schema'],
    ]);

    $job = new ProcessTaskResult($task->id);
    $job->handle(app(\App\Services\ResultProcessor::class));

    Queue::assertNotPushed(PostInlineThreads::class);
});

it('does not dispatch PostInlineThreads for tasks without mr_iid', function () {
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class, PostLabelsAndStatus::class]);

    $task = Task::factory()->running()->create([
        'type' => TaskType::CodeReview,
        'mr_iid' => null,
        'result' => [
            'version' => '1.0',
            'summary' => [
                'risk_level' => 'low',
                'total_findings' => 0,
                'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 0],
                'walkthrough' => [
                    ['file' => 'README.md', 'change_summary' => 'Updated'],
                ],
            ],
            'findings' => [],
            'labels' => ['ai::reviewed'],
            'commit_status' => 'success',
        ],
    ]);

    $job = new ProcessTaskResult($task->id);
    $job->handle(app(\App\Services\ResultProcessor::class));

    Queue::assertNotPushed(PostInlineThreads::class);
});

// ─── PostLabelsAndStatus dispatch tests ──────────────────────────

it('dispatches PostLabelsAndStatus after successful code review processing', function () {
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class, PostLabelsAndStatus::class]);

    $task = Task::factory()->running()->create([
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

    $job = new ProcessTaskResult($task->id);
    $job->handle(app(\App\Services\ResultProcessor::class));

    Queue::assertPushed(PostLabelsAndStatus::class, function ($job) use ($task) {
        return $job->taskId === $task->id;
    });
});

it('dispatches PostLabelsAndStatus after successful security audit processing', function () {
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class, PostLabelsAndStatus::class]);

    $task = Task::factory()->running()->create([
        'type' => TaskType::SecurityAudit,
        'mr_iid' => 10,
        'result' => [
            'version' => '1.0',
            'summary' => [
                'risk_level' => 'low',
                'total_findings' => 0,
                'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 0],
                'walkthrough' => [
                    ['file' => 'src/app.py', 'change_summary' => 'Reviewed security'],
                ],
            ],
            'findings' => [],
            'labels' => ['ai::reviewed'],
            'commit_status' => 'success',
        ],
    ]);

    $job = new ProcessTaskResult($task->id);
    $job->handle(app(\App\Services\ResultProcessor::class));

    Queue::assertPushed(PostLabelsAndStatus::class);
});

it('does not dispatch PostLabelsAndStatus for non-review task types', function () {
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class, PostLabelsAndStatus::class]);

    $task = Task::factory()->running()->create([
        'type' => TaskType::FeatureDev,
        'result' => [
            'version' => '1.0',
            'branch' => 'ai/test-feature',
            'mr_title' => 'Test feature',
            'mr_description' => 'A test feature.',
            'files_changed' => [
                ['path' => 'src/test.py', 'action' => 'created', 'summary' => 'New file'],
            ],
            'tests_added' => true,
            'notes' => 'Done.',
        ],
    ]);

    $job = new ProcessTaskResult($task->id);
    $job->handle(app(\App\Services\ResultProcessor::class));

    Queue::assertNotPushed(PostLabelsAndStatus::class);
});

it('does not dispatch PostLabelsAndStatus when validation fails', function () {
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class, PostLabelsAndStatus::class]);

    $task = Task::factory()->running()->create([
        'type' => TaskType::CodeReview,
        'mr_iid' => 42,
        'result' => ['invalid' => 'not a valid schema'],
    ]);

    $job = new ProcessTaskResult($task->id);
    $job->handle(app(\App\Services\ResultProcessor::class));

    Queue::assertNotPushed(PostLabelsAndStatus::class);
});

it('does not dispatch PostLabelsAndStatus for tasks without mr_iid', function () {
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class, PostLabelsAndStatus::class]);

    $task = Task::factory()->running()->create([
        'type' => TaskType::CodeReview,
        'mr_iid' => null,
        'result' => [
            'version' => '1.0',
            'summary' => [
                'risk_level' => 'low',
                'total_findings' => 0,
                'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 0],
                'walkthrough' => [
                    ['file' => 'README.md', 'change_summary' => 'Updated'],
                ],
            ],
            'findings' => [],
            'labels' => ['ai::reviewed'],
            'commit_status' => 'success',
        ],
    ]);

    $job = new ProcessTaskResult($task->id);
    $job->handle(app(\App\Services\ResultProcessor::class));

    Queue::assertNotPushed(PostLabelsAndStatus::class);
});

<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\PostSummaryComment;
use App\Models\Task;
use App\Services\GitLabClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function completedCodeReviewTask(): Task
{
    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
        'mr_iid' => 42,
        'result' => [
            'version' => '1.0',
            'summary' => [
                'risk_level' => 'medium',
                'total_findings' => 1,
                'findings_by_severity' => ['critical' => 0, 'major' => 1, 'minor' => 0],
                'walkthrough' => [
                    ['file' => 'src/app.py', 'change_summary' => 'Added error handling'],
                ],
            ],
            'findings' => [
                [
                    'id' => 1,
                    'severity' => 'major',
                    'category' => 'bug',
                    'file' => 'src/app.py',
                    'line' => 15,
                    'end_line' => 20,
                    'title' => 'Unchecked return value',
                    'description' => 'Return value from save() is not checked.',
                    'suggestion' => 'Check the return value.',
                    'labels' => [],
                ],
            ],
            'labels' => ['ai::reviewed', 'ai::risk-medium'],
            'commit_status' => 'success',
        ],
    ]);

    return $task;
}

// ─── Posts comment and stores note ID ───────────────────────────

it('posts the summary comment to GitLab and stores the note ID', function () {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response([
            'id' => 9876,
            'body' => 'mocked',
        ], 201),
    ]);

    $task = completedCodeReviewTask();

    $job = new PostSummaryComment($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/notes')
            && str_contains($request['body'], '## 🤖 AI Code Review');
    });

    $task->refresh();
    expect($task->comment_id)->toBe(9876);
});

// ─── Skips if task not found ────────────────────────────────────

it('returns early if the task does not exist', function () {
    Http::fake();

    $job = new PostSummaryComment(999999);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Skips if task has no MR IID ────────────────────────────────

it('returns early if the task has no mr_iid', function () {
    Http::fake();

    $task = Task::factory()->create([
        'type' => TaskType::IssueDiscussion,
        'status' => TaskStatus::Completed,
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
        'mr_iid' => null,
        'result' => ['response' => 'Some discussion.'],
    ]);

    $job = new PostSummaryComment($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Skips if task has no result ────────────────────────────────

it('returns early if the task has no result', function () {
    Http::fake();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
        'result' => null,
    ]);

    $job = new PostSummaryComment($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

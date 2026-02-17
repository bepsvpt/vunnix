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
    return Task::factory()->create([
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
}

// ─── Creates new comment when no placeholder exists ─────────────

it('posts the summary comment to GitLab and stores the note ID', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response([
            'id' => 9876,
            'body' => 'mocked',
        ], 201),
    ]);

    $task = completedCodeReviewTask();

    $job = new PostSummaryComment($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertSent(function (array $request): bool {
        return str_contains($request->url(), '/notes')
            && str_contains($request['body'], '## 🤖 AI Code Review');
    });

    $task->refresh();
    expect($task->comment_id)->toBe(9876);
});

// ─── Skips if task not found ────────────────────────────────────

it('returns early if the task does not exist', function (): void {
    Http::fake();

    $job = new PostSummaryComment(999999);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Skips if task has no MR IID ────────────────────────────────

it('returns early if the task has no mr_iid', function (): void {
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

it('returns early if the task has no result', function (): void {
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

// ─── T36: Updates placeholder comment in-place ──────────────────

it('updates existing placeholder comment in-place when comment_id exists', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes/*' => Http::response([
            'id' => 5555,
            'body' => 'updated',
        ], 200),
    ]);

    $task = completedCodeReviewTask();
    $task->comment_id = 5555;
    $task->save();

    $job = new PostSummaryComment($task->id);
    $job->handle(app(GitLabClient::class));

    // Should use PUT (update), not POST (create)
    Http::assertSent(function (array $request): bool {
        return $request->method() === 'PUT'
            && str_contains($request->url(), '/notes/5555')
            && str_contains($request['body'], '## 🤖 AI Code Review');
    });

    // comment_id should remain unchanged
    $task->refresh();
    expect($task->comment_id)->toBe(5555);
});

// ─── T40: Includes updated timestamp for incremental reviews ─────

it('includes updated timestamp when task reuses a previous comment_id', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes/*' => Http::response([
            'id' => 5555,
            'body' => 'updated',
        ], 200),
    ]);

    // New task first — we need its project_id for the previous task
    $task = completedCodeReviewTask();
    $task->comment_id = 5555;
    $task->save();

    // Previous completed review on same project + MR with same comment_id
    $previousTask = Task::factory()->create([
        'project_id' => $task->project_id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'started_at' => now()->subMinutes(10),
        'completed_at' => now()->subMinutes(5),
        'mr_iid' => 42,
        'comment_id' => 5555,
        'result' => ['version' => '1.0'],
    ]);

    $job = new PostSummaryComment($task->id);
    $job->handle(app(GitLabClient::class));

    // Assert the PUT body contains the "Updated" timestamp
    Http::assertSent(function (array $request): bool {
        return $request->method() === 'PUT'
            && str_contains($request['body'] ?? '', '📝 Updated:');
    });
});

it('does not include timestamp when no previous task shares the comment_id', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes/*' => Http::response([
            'id' => 5555,
            'body' => 'updated',
        ], 200),
    ]);

    // Task with comment_id but no previous task sharing it (initial placeholder)
    $task = completedCodeReviewTask();
    $task->comment_id = 5555;
    $task->save();

    $job = new PostSummaryComment($task->id);
    $job->handle(app(GitLabClient::class));

    // Assert the PUT body does NOT contain the "Updated" timestamp
    Http::assertSent(function (array $request): bool {
        return $request->method() === 'PUT'
            && ! str_contains($request['body'] ?? '', '📝 Updated:');
    });
});

it('does not POST a new comment when updating placeholder in-place', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes/*' => Http::response([
            'id' => 5555,
            'body' => 'updated',
        ], 200),
    ]);

    $task = completedCodeReviewTask();
    $task->comment_id = 5555;
    $task->save();

    $job = new PostSummaryComment($task->id);
    $job->handle(app(GitLabClient::class));

    // Should NOT have any POST to /notes (only PUT to /notes/5555)
    Http::assertNotSent(function ($request): bool {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/notes');
    });
});

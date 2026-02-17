<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\PostInlineThreads;
use App\Models\Task;
use App\Services\GitLabClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────

function taskWithFindings(): Task
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
                'risk_level' => 'high',
                'total_findings' => 3,
                'findings_by_severity' => ['critical' => 1, 'major' => 1, 'minor' => 1],
                'walkthrough' => [
                    ['file' => 'src/auth.py', 'change_summary' => 'Added auth logic'],
                    ['file' => 'src/utils.py', 'change_summary' => 'Updated utils'],
                ],
            ],
            'findings' => [
                [
                    'id' => 1,
                    'severity' => 'critical',
                    'category' => 'security',
                    'file' => 'src/auth.py',
                    'line' => 42,
                    'end_line' => 45,
                    'title' => 'SQL injection risk',
                    'description' => 'User input in SQL query.',
                    'suggestion' => "```diff\n- bad\n+ good\n```",
                    'labels' => [],
                ],
                [
                    'id' => 2,
                    'severity' => 'major',
                    'category' => 'bug',
                    'file' => 'src/utils.py',
                    'line' => 18,
                    'end_line' => 22,
                    'title' => 'Null pointer dereference',
                    'description' => 'User may be null.',
                    'suggestion' => "```diff\n- user.name\n+ user?.name\n```",
                    'labels' => [],
                ],
                [
                    'id' => 3,
                    'severity' => 'minor',
                    'category' => 'style',
                    'file' => 'src/config.py',
                    'line' => 7,
                    'end_line' => 7,
                    'title' => 'Unused import',
                    'description' => 'os is unused.',
                    'suggestion' => "```diff\n- import os\n```",
                    'labels' => [],
                ],
            ],
            'labels' => ['ai::reviewed', 'ai::risk-high'],
            'commit_status' => 'failed',
        ],
    ]);
}

function fakeMrResponse(): array
{
    return [
        'iid' => 42,
        'diff_refs' => [
            'base_sha' => 'aaa111',
            'start_sha' => 'bbb222',
            'head_sha' => 'ccc333',
        ],
    ];
}

function fakeDiscussionResponse(string $id = 'disc-1'): array
{
    return [
        'id' => $id,
        'notes' => [['body' => 'mocked']],
    ];
}

// ─── Posts threads for high/medium findings only ─────────────────

it('creates discussion threads for critical and major findings only', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42' => Http::response(fakeMrResponse(), 200),
        '*/api/v4/projects/*/merge_requests/42/discussions*' => Http::sequence()
            ->push([], 200)                                    // T40: GET existing discussions (empty)
            ->push(fakeDiscussionResponse('disc-1'), 201)      // POST finding #1
            ->push(fakeDiscussionResponse('disc-2'), 201),     // POST finding #2
    ]);

    $task = taskWithFindings();

    $job = new PostInlineThreads($task->id);
    $job->handle(app(GitLabClient::class));

    // 1 MR GET + 1 discussions GET (T40 dedup) + 2 discussion POSTs
    Http::assertSentCount(4);
});

// ─── Sends correct position data ─────────────────────────────────

it('sends correct position data from MR diff_refs', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42' => Http::response(fakeMrResponse(), 200),
        '*/api/v4/projects/*/merge_requests/42/discussions*' => Http::sequence()
            ->push([], 200)                                    // T40: GET existing discussions
            ->push(fakeDiscussionResponse('disc-1'), 201)      // POST finding #1
            ->push(fakeDiscussionResponse('disc-2'), 201),     // POST finding #2
    ]);

    $task = taskWithFindings();

    $job = new PostInlineThreads($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        if (! str_contains($request->url(), '/discussions')) {
            return false;
        }

        $position = $request['position'] ?? [];

        return ($position['base_sha'] ?? '') === 'aaa111'
            && ($position['start_sha'] ?? '') === 'bbb222'
            && ($position['head_sha'] ?? '') === 'ccc333'
            && ($position['position_type'] ?? '') === 'text'
            && ($position['new_path'] ?? '') === 'src/auth.py'
            && ($position['new_line'] ?? 0) === 42;
    });
});

// ─── Thread body contains severity tag and suggestion ────────────

it('sends formatted finding body with severity tag', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42' => Http::response(fakeMrResponse(), 200),
        '*/api/v4/projects/*/merge_requests/42/discussions*' => Http::sequence()
            ->push([], 200)                                    // T40: GET existing discussions
            ->push(fakeDiscussionResponse('disc-1'), 201)      // POST finding #1
            ->push(fakeDiscussionResponse('disc-2'), 201),     // POST finding #2
    ]);

    $task = taskWithFindings();

    $job = new PostInlineThreads($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        if (! str_contains($request->url(), '/discussions')) {
            return false;
        }

        $body = $request['body'] ?? '';

        return str_contains($body, '🔴 **Critical**')
            || str_contains($body, '🟡 **Major**');
    });
});

// ─── Skips if task not found ────────────────────────────────────

it('returns early if the task does not exist', function (): void {
    Http::fake();

    $job = new PostInlineThreads(999999);
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

    $job = new PostInlineThreads($task->id);
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

    $job = new PostInlineThreads($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Skips if no high/medium findings ───────────────────────────

// ─── T40: Deduplicates threads for incremental review (D33) ──────

it('does not create duplicate threads for findings that already have unresolved discussions', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42' => Http::response(fakeMrResponse(), 200),
        '*/api/v4/projects/*/merge_requests/42/discussions*' => Http::sequence()
            // GET existing discussions — returns one matching finding #1 (SQL injection)
            ->push([
                [
                    'id' => 'existing-disc-1',
                    'notes' => [[
                        'body' => "🔴 **Critical** | Security\n\n**SQL injection risk**\n\nUser input in SQL query.",
                        'position' => [
                            'new_path' => 'src/auth.py',
                            'new_line' => 42,
                        ],
                    ]],
                ],
            ], 200)
            // POST creates new thread for finding #2 (not duplicated)
            ->push(fakeDiscussionResponse('new-disc-1'), 201),
    ]);

    $task = taskWithFindings();

    $job = new PostInlineThreads($task->id);
    $job->handle(app(GitLabClient::class));

    // Only 1 new discussion created (finding #2: Null pointer dereference), not 2
    // Total: 1 MR GET + 1 discussions GET + 1 discussion POST = 3
    Http::assertSentCount(3);

    // Verify the POST was for the second finding (Null pointer), not the first (SQL injection)
    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        if ($request->method() !== 'POST' || ! str_contains($request->url(), '/discussions')) {
            return false;
        }

        $body = $request['body'] ?? '';

        return str_contains($body, 'Null pointer dereference');
    });

    // Verify NO POST was made for the SQL injection finding
    Http::assertNotSent(function (\Illuminate\Http\Client\Request $request): bool {
        if ($request->method() !== 'POST' || ! str_contains($request->url(), '/discussions')) {
            return false;
        }

        $body = $request['body'] ?? '';

        return str_contains($body, 'SQL injection risk');
    });
});

it('creates all threads when discussion fetch fails gracefully', function (): void {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42' => Http::response(fakeMrResponse(), 200),
        '*/api/v4/projects/*/merge_requests/42/discussions*' => Http::sequence()
            ->push(null, 500)                                  // T40: GET discussions fails
            ->push(fakeDiscussionResponse('disc-1'), 201)      // POST finding #1
            ->push(fakeDiscussionResponse('disc-2'), 201),     // POST finding #2
    ]);

    $task = taskWithFindings();

    $job = new PostInlineThreads($task->id);
    $job->handle(app(GitLabClient::class));

    // Graceful fallback: 1 MR GET + 1 failed discussions GET + 2 discussion POSTs = 4
    Http::assertSentCount(4);
});

// ─── Skips if no high/medium findings ───────────────────────────

it('does not create threads when only minor findings exist', function (): void {
    Http::fake();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
        'mr_iid' => 42,
        'result' => [
            'version' => '1.0',
            'summary' => [
                'risk_level' => 'low',
                'total_findings' => 1,
                'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 1],
                'walkthrough' => [
                    ['file' => 'src/config.py', 'change_summary' => 'Updated config'],
                ],
            ],
            'findings' => [
                [
                    'id' => 1,
                    'severity' => 'minor',
                    'category' => 'style',
                    'file' => 'src/config.py',
                    'line' => 7,
                    'end_line' => 7,
                    'title' => 'Unused import',
                    'description' => 'os is unused.',
                    'suggestion' => "```diff\n- import os\n```",
                    'labels' => [],
                ],
            ],
            'labels' => ['ai::reviewed', 'ai::risk-low'],
            'commit_status' => 'success',
        ],
    ]);

    $job = new PostInlineThreads($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

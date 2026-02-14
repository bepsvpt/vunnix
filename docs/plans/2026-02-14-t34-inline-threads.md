# T34: Inline Discussion Threads — Layer 2 Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create resolvable GitLab discussion threads on specific diff lines for high/medium severity findings from code reviews.

**Architecture:** Follows the same pattern as T33 (Layer 1). An `InlineThreadFormatter` formats individual findings as markdown thread bodies. A `PostInlineThreads` job fetches the MR's diff refs from GitLab, filters findings to high/medium severity only, and creates one discussion thread per finding using `GitLabClient::createMergeRequestDiscussion()` with position data. `ProcessTaskResult` dispatches this job alongside `PostSummaryComment`.

**Tech Stack:** Laravel 11, Pest, GitLab REST API v4 (Discussions endpoint)

---

### Task 1: Write InlineThreadFormatter unit tests

**Files:**
- Create: `tests/Unit/Services/InlineThreadFormatterTest.php`

**Step 1: Write the failing tests**

```php
<?php

use App\Services\InlineThreadFormatter;

// ─── Helpers ─────────────────────────────────────────────────────

function criticalFinding(): array
{
    return [
        'id' => 1,
        'severity' => 'critical',
        'category' => 'security',
        'file' => 'src/auth.py',
        'line' => 42,
        'end_line' => 45,
        'title' => 'SQL injection risk',
        'description' => 'User input is interpolated directly into SQL query.',
        'suggestion' => "```diff\n-  query(f\"SELECT * FROM users WHERE id={id}\")\n+  query(\"SELECT * FROM users WHERE id=?\", [id])\n```",
        'labels' => [],
    ];
}

function majorFinding(): array
{
    return [
        'id' => 2,
        'severity' => 'major',
        'category' => 'bug',
        'file' => 'src/utils.py',
        'line' => 18,
        'end_line' => 22,
        'title' => 'Null pointer dereference',
        'description' => 'The user variable may be null when accessed.',
        'suggestion' => "```diff\n-  user.name\n+  user?.name ?? \"Unknown\"\n```",
        'labels' => [],
    ];
}

function minorFinding(): array
{
    return [
        'id' => 3,
        'severity' => 'minor',
        'category' => 'style',
        'file' => 'src/config.py',
        'line' => 7,
        'end_line' => 7,
        'title' => 'Unused import',
        'description' => 'The os module is imported but never used.',
        'suggestion' => "```diff\n-  import os\n```",
        'labels' => [],
    ];
}

// ─── Formats a critical finding with correct structure ──────────

it('formats a critical finding with severity tag, description, and suggestion', function () {
    $formatter = new InlineThreadFormatter();
    $markdown = $formatter->format(criticalFinding());

    expect($markdown)->toContain('🔴 **Critical**')
        ->and($markdown)->toContain('Security')
        ->and($markdown)->toContain('SQL injection risk')
        ->and($markdown)->toContain('User input is interpolated directly into SQL query.')
        ->and($markdown)->toContain('```diff');
});

// ─── Formats a major finding ────────────────────────────────────

it('formats a major finding with correct severity tag', function () {
    $formatter = new InlineThreadFormatter();
    $markdown = $formatter->format(majorFinding());

    expect($markdown)->toContain('🟡 **Major**')
        ->and($markdown)->toContain('Bug')
        ->and($markdown)->toContain('Null pointer dereference');
});

// ─── Includes suggestion block ──────────────────────────────────

it('includes the suggestion in the formatted output', function () {
    $formatter = new InlineThreadFormatter();
    $markdown = $formatter->format(criticalFinding());

    expect($markdown)->toContain('**Suggested fix:**')
        ->and($markdown)->toContain('```diff');
});

// ─── filterHighMedium returns only critical/major ───────────────

it('filters findings to only critical and major severity', function () {
    $formatter = new InlineThreadFormatter();
    $findings = [criticalFinding(), majorFinding(), minorFinding()];

    $filtered = $formatter->filterHighMedium($findings);

    expect($filtered)->toHaveCount(2)
        ->and($filtered[0]['severity'])->toBe('critical')
        ->and($filtered[1]['severity'])->toBe('major');
});

// ─── filterHighMedium with no qualifying findings ───────────────

it('returns empty array when no high/medium findings exist', function () {
    $formatter = new InlineThreadFormatter();
    $filtered = $formatter->filterHighMedium([minorFinding()]);

    expect($filtered)->toBeEmpty();
});

// ─── filterHighMedium with empty input ──────────────────────────

it('returns empty array for empty findings input', function () {
    $formatter = new InlineThreadFormatter();
    $filtered = $formatter->filterHighMedium([]);

    expect($filtered)->toBeEmpty();
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Services/InlineThreadFormatterTest.php`
Expected: FAIL — class InlineThreadFormatter not found

---

### Task 2: Implement InlineThreadFormatter

**Files:**
- Create: `app/Services/InlineThreadFormatter.php`

**Step 1: Write minimal implementation**

```php
<?php

namespace App\Services;

/**
 * Formats a single code review finding as a markdown discussion thread body.
 *
 * Layer 2 of the 3-Layer Comment Pattern (§4.5).
 * Each thread contains: severity tag, category, title, description, and suggested fix.
 *
 * @see \App\Schemas\CodeReviewSchema
 */
class InlineThreadFormatter
{
    private const SEVERITY_TAGS = [
        'critical' => '🔴 **Critical**',
        'major' => '🟡 **Major**',
        'minor' => '🟢 **Minor**',
    ];

    private const INLINE_SEVERITIES = ['critical', 'major'];

    /**
     * Format a single finding as a markdown discussion thread body.
     */
    public function format(array $finding): string
    {
        $severity = self::SEVERITY_TAGS[$finding['severity']] ?? $finding['severity'];
        $category = ucfirst($finding['category']);

        $lines = [];
        $lines[] = "{$severity} | {$category}";
        $lines[] = '';
        $lines[] = "**{$finding['title']}**";
        $lines[] = '';
        $lines[] = $finding['description'];
        $lines[] = '';
        $lines[] = '**Suggested fix:**';
        $lines[] = '';
        $lines[] = $finding['suggestion'];

        return implode("\n", $lines);
    }

    /**
     * Filter findings to only those that should get inline threads (high/medium severity).
     *
     * Per §4.5: "high/medium severity only" — critical and major findings get threads,
     * minor findings are informational only (appear in Layer 1 summary).
     *
     * @param  array<int, array>  $findings
     * @return array<int, array>
     */
    public function filterHighMedium(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            fn (array $finding) => in_array($finding['severity'], self::INLINE_SEVERITIES, true),
        ));
    }
}
```

**Step 2: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Services/InlineThreadFormatterTest.php`
Expected: All 6 tests PASS

**Step 3: Commit**

```bash
git add app/Services/InlineThreadFormatter.php tests/Unit/Services/InlineThreadFormatterTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T34.1: Add InlineThreadFormatter with tests for severity filtering and markdown output

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Write PostInlineThreads job tests

**Files:**
- Create: `tests/Feature/Jobs/PostInlineThreadsTest.php`

**Step 1: Write the failing tests**

The job should:
1. Load the task with its project
2. Skip if task/MR/result is missing
3. Filter findings to high/medium severity
4. Fetch MR details from GitLab to get `diff_refs` (base_sha, start_sha, head_sha)
5. Create one discussion thread per finding via `createMergeRequestDiscussion`

```php
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

it('creates discussion threads for critical and major findings only', function () {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42' => Http::response(fakeMrResponse(), 200),
        '*/api/v4/projects/*/merge_requests/42/discussions' => Http::sequence()
            ->push(fakeDiscussionResponse('disc-1'), 201)
            ->push(fakeDiscussionResponse('disc-2'), 201),
    ]);

    $task = taskWithFindings();

    $job = new PostInlineThreads($task->id);
    $job->handle(app(GitLabClient::class));

    // Should create exactly 2 discussions (critical + major, not minor)
    Http::assertSentCount(3); // 1 MR fetch + 2 discussion creates
});

// ─── Sends correct position data ─────────────────────────────────

it('sends correct position data from MR diff_refs', function () {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42' => Http::response(fakeMrResponse(), 200),
        '*/api/v4/projects/*/merge_requests/42/discussions' => Http::response(fakeDiscussionResponse(), 201),
    ]);

    $task = taskWithFindings();

    $job = new PostInlineThreads($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertSent(function ($request) {
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

it('sends formatted finding body with severity tag', function () {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42' => Http::response(fakeMrResponse(), 200),
        '*/api/v4/projects/*/merge_requests/42/discussions' => Http::response(fakeDiscussionResponse(), 201),
    ]);

    $task = taskWithFindings();

    $job = new PostInlineThreads($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/discussions')) {
            return false;
        }

        $body = $request['body'] ?? '';

        return str_contains($body, '🔴 **Critical**')
            || str_contains($body, '🟡 **Major**');
    });
});

// ─── Skips if task not found ────────────────────────────────────

it('returns early if the task does not exist', function () {
    Http::fake();

    $job = new PostInlineThreads(999999);
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

    $job = new PostInlineThreads($task->id);
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

    $job = new PostInlineThreads($task->id);
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Skips if no high/medium findings ───────────────────────────

it('does not create threads when only minor findings exist', function () {
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
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Jobs/PostInlineThreadsTest.php`
Expected: FAIL — class PostInlineThreads not found

---

### Task 4: Implement PostInlineThreads job

**Files:**
- Create: `app/Jobs/PostInlineThreads.php`

**Step 1: Write the implementation**

```php
<?php

namespace App\Jobs;

use App\Models\Task;
use App\Services\GitLabClient;
use App\Services\InlineThreadFormatter;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Post inline discussion threads (Layer 2) on a GitLab merge request.
 *
 * Creates one resolvable discussion thread per high/medium severity finding,
 * positioned on the specific diff line. Engineers can resolve threads individually.
 *
 * @see §4.5 3-Layer Comment Pattern — Layer 2
 */
class PostInlineThreads implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $taskId,
    ) {
        $this->queue = QueueNames::SERVER;
    }

    public function handle(GitLabClient $gitLab): void
    {
        $task = Task::with('project')->find($this->taskId);

        if ($task === null) {
            Log::warning('PostInlineThreads: task not found', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->mr_iid === null) {
            Log::info('PostInlineThreads: task has no MR, skipping', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->result === null) {
            Log::info('PostInlineThreads: task has no result, skipping', ['task_id' => $this->taskId]);

            return;
        }

        $formatter = new InlineThreadFormatter();
        $findings = $formatter->filterHighMedium($task->result['findings'] ?? []);

        if (empty($findings)) {
            Log::info('PostInlineThreads: no high/medium findings, skipping', ['task_id' => $this->taskId]);

            return;
        }

        $projectId = $task->project->gitlab_project_id;

        // Fetch MR to get diff_refs for positioning
        try {
            $mr = $gitLab->getMergeRequest($projectId, $task->mr_iid);
        } catch (\Throwable $e) {
            Log::warning('PostInlineThreads: failed to fetch MR', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $diffRefs = $mr['diff_refs'] ?? [];

        foreach ($findings as $finding) {
            $body = $formatter->format($finding);

            $position = [
                'base_sha' => $diffRefs['base_sha'] ?? '',
                'start_sha' => $diffRefs['start_sha'] ?? '',
                'head_sha' => $diffRefs['head_sha'] ?? '',
                'position_type' => 'text',
                'new_path' => $finding['file'],
                'new_line' => $finding['line'],
            ];

            try {
                $gitLab->createMergeRequestDiscussion(
                    $projectId,
                    $task->mr_iid,
                    $body,
                    $position,
                );

                Log::info('PostInlineThreads: created thread', [
                    'task_id' => $this->taskId,
                    'finding_id' => $finding['id'],
                    'file' => $finding['file'],
                    'line' => $finding['line'],
                ]);
            } catch (\Throwable $e) {
                Log::warning('PostInlineThreads: failed to create thread', [
                    'task_id' => $this->taskId,
                    'finding_id' => $finding['id'],
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }
    }
}
```

**Step 2: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Jobs/PostInlineThreadsTest.php`
Expected: All 7 tests PASS

**Step 3: Commit**

```bash
git add app/Jobs/PostInlineThreads.php tests/Feature/Jobs/PostInlineThreadsTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T34.2: Add PostInlineThreads job with GitLab discussion API integration

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Wire PostInlineThreads dispatch into ProcessTaskResult

**Files:**
- Modify: `app/Jobs/ProcessTaskResult.php`
- Modify: `tests/Feature/Jobs/ProcessTaskResultDispatchTest.php`

**Step 1: Write failing tests — add dispatch assertions for PostInlineThreads**

Append to the existing test file:

```php
// ─── PostInlineThreads dispatch tests ───────────────────────────

it('dispatches PostInlineThreads after successful code review processing', function () {
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class]);

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
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class]);

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
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class]);

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
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class]);

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
    Queue::fake([PostSummaryComment::class, PostInlineThreads::class]);

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
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Jobs/ProcessTaskResultDispatchTest.php`
Expected: FAIL — PostInlineThreads not dispatched

**Step 3: Wire the dispatch into ProcessTaskResult**

In `app/Jobs/ProcessTaskResult.php`, add the import at top:
```php
use App\Jobs\PostInlineThreads;
```

After line 67 (after the `PostSummaryComment` dispatch), add:
```php
if ($this->shouldPostInlineThreads($task)) {
    PostInlineThreads::dispatch($task->id);
}
```

Add the new private method (mirrors `shouldPostSummaryComment`):
```php
private function shouldPostInlineThreads(Task $task): bool
{
    return $task->mr_iid !== null
        && in_array($task->type, [TaskType::CodeReview, TaskType::SecurityAudit], true);
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Jobs/ProcessTaskResultDispatchTest.php`
Expected: All 10 tests PASS

**Step 5: Commit**

```bash
git add app/Jobs/ProcessTaskResult.php tests/Feature/Jobs/ProcessTaskResultDispatchTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T34.3: Wire PostInlineThreads dispatch into ProcessTaskResult

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Update sync-queue integration test

**Files:**
- Modify: `tests/Feature/TaskResultApiTest.php`

The existing sync-queue test (`'accepts a completed result and transitions task to completed via Result Processor'`) currently only fakes the `/notes` endpoint. Since `PostInlineThreads` now also runs inline with the sync queue, it will try to call:
1. `GET /merge_requests/{iid}` — to get diff_refs
2. `POST /merge_requests/{iid}/discussions` — to create threads

The test uses a zero-finding result (`validSchemaResult()`) which has no high/medium findings, so `PostInlineThreads` will early-return (no MR fetch, no discussions). But we should add a test that verifies the full sync pipeline with findings.

**Step 1: Add a sync-queue test with findings**

Append to `tests/Feature/TaskResultApiTest.php`:

```php
// ─── Sync pipeline: inline threads posted alongside summary ──────

it('posts inline threads alongside summary comment in sync queue pipeline', function () {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response(['id' => 1, 'body' => 'mocked'], 201),
        '*/api/v4/projects/*/merge_requests/*' => Http::response([
            'iid' => 42,
            'diff_refs' => [
                'base_sha' => 'aaa',
                'start_sha' => 'bbb',
                'head_sha' => 'ccc',
            ],
        ], 200),
        '*/api/v4/projects/*/merge_requests/*/discussions' => Http::response([
            'id' => 'disc-1',
            'notes' => [['body' => 'mocked']],
        ], 201),
    ]);

    $task = Task::factory()->running()->create(['mr_iid' => 42]);
    $token = generateToken($task->id);

    $resultWithFindings = [
        'version' => '1.0',
        'summary' => [
            'risk_level' => 'high',
            'total_findings' => 1,
            'findings_by_severity' => ['critical' => 1, 'major' => 0, 'minor' => 0],
            'walkthrough' => [
                ['file' => 'src/auth.py', 'change_summary' => 'Added auth'],
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
                'title' => 'SQL injection',
                'description' => 'Bad SQL.',
                'suggestion' => 'Use parameterized queries.',
                'labels' => [],
            ],
        ],
        'labels' => ['ai::reviewed', 'ai::risk-high'],
        'commit_status' => 'failed',
    ];

    $response = $this->postJson(resultUrl($task), validResultPayload([
        'result' => $resultWithFindings,
    ]), [
        'Authorization' => "Bearer {$token}",
    ]);

    $response->assertOk();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Completed);

    // Verify both summary comment and discussion thread were posted
    Http::assertSent(fn ($r) => str_contains($r->url(), '/notes'));
    Http::assertSent(fn ($r) => str_contains($r->url(), '/discussions'));
});
```

**Step 2: Run the full test file to verify**

Run: `php artisan test tests/Feature/TaskResultApiTest.php`
Expected: All tests PASS

**Step 3: Commit**

```bash
git add tests/Feature/TaskResultApiTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T34.4: Add sync-queue integration test for inline threads pipeline

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Add T34 verification checks to verify_m2.py

**Files:**
- Modify: `verify/verify_m2.py`

**Step 1: Add T34 section after T33 section (after the T33 test checks)**

Append after the T33 section at end of file (before `checker.summarize()`):

```python
# ============================================================
#  T34: Inline discussion threads — Layer 2
# ============================================================
section("T34: Inline Discussion Threads — Layer 2")

# Formatter service
checker.check(
    "InlineThreadFormatter service exists",
    file_exists("app/Services/InlineThreadFormatter.php"),
)
checker.check(
    "InlineThreadFormatter has format method",
    file_contains("app/Services/InlineThreadFormatter.php", "function format(array"),
)
checker.check(
    "InlineThreadFormatter has severity tag mapping",
    file_contains("app/Services/InlineThreadFormatter.php", "SEVERITY_TAGS"),
)
checker.check(
    "InlineThreadFormatter has filterHighMedium method",
    file_contains("app/Services/InlineThreadFormatter.php", "function filterHighMedium"),
)
checker.check(
    "InlineThreadFormatter filters to critical and major only",
    file_contains("app/Services/InlineThreadFormatter.php", "INLINE_SEVERITIES"),
)
checker.check(
    "InlineThreadFormatter includes suggested fix section",
    file_contains("app/Services/InlineThreadFormatter.php", "Suggested fix"),
)

# PostInlineThreads job
checker.check(
    "PostInlineThreads job exists",
    file_exists("app/Jobs/PostInlineThreads.php"),
)
checker.check(
    "PostInlineThreads implements ShouldQueue",
    file_contains("app/Jobs/PostInlineThreads.php", "ShouldQueue"),
)
checker.check(
    "PostInlineThreads uses vunnix-server queue",
    file_contains("app/Jobs/PostInlineThreads.php", "QueueNames::SERVER"),
)
checker.check(
    "PostInlineThreads calls createMergeRequestDiscussion",
    file_contains("app/Jobs/PostInlineThreads.php", "createMergeRequestDiscussion"),
)
checker.check(
    "PostInlineThreads fetches MR for diff_refs",
    file_contains("app/Jobs/PostInlineThreads.php", "getMergeRequest"),
)
checker.check(
    "PostInlineThreads sends position data with position_type",
    file_contains("app/Jobs/PostInlineThreads.php", "position_type"),
)
checker.check(
    "PostInlineThreads uses InlineThreadFormatter",
    file_contains("app/Jobs/PostInlineThreads.php", "InlineThreadFormatter"),
)

# ProcessTaskResult dispatches PostInlineThreads
checker.check(
    "ProcessTaskResult dispatches PostInlineThreads",
    file_contains("app/Jobs/ProcessTaskResult.php", "PostInlineThreads"),
)

# Tests
checker.check(
    "InlineThreadFormatter unit test exists",
    file_exists("tests/Unit/Services/InlineThreadFormatterTest.php"),
)
checker.check(
    "Test covers severity filtering",
    file_contains(
        "tests/Unit/Services/InlineThreadFormatterTest.php",
        "filterHighMedium",
    ),
)
checker.check(
    "PostInlineThreads feature test exists",
    file_exists("tests/Feature/Jobs/PostInlineThreadsTest.php"),
)
checker.check(
    "Test covers discussion creation",
    file_contains(
        "tests/Feature/Jobs/PostInlineThreadsTest.php",
        "createMergeRequestDiscussion" if file_exists("tests/Feature/Jobs/PostInlineThreadsTest.php")
        else "discussions",
    ),
)
checker.check(
    "ProcessTaskResult dispatch test covers PostInlineThreads",
    file_contains(
        "tests/Feature/Jobs/ProcessTaskResultDispatchTest.php",
        "PostInlineThreads",
    ),
)
```

**Step 2: Run verification**

Run: `python3 verify/verify_m2.py`
Expected: All T34 checks PASS

**Step 3: Commit**

```bash
git add verify/verify_m2.py
git commit --no-gpg-sign -m "$(cat <<'EOF'
T34.5: Add T34 verification checks to verify_m2.py

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Run full verification, update progress.md, finalize

**Step 1: Run full test suite**

Run: `php artisan test`
Expected: All tests PASS

**Step 2: Run M2 structural verification**

Run: `python3 verify/verify_m2.py`
Expected: All checks PASS (including new T34 checks)

**Step 3: Update progress.md**

- Mark T34 as `[x]`
- Bold T35 as the next task
- Update summary: Tasks Complete: 34 / 116 (29%), Last Verified: T34

**Step 4: Clear handoff.md**

Reset to empty template.

**Step 5: Final commit**

```bash
git add progress.md handoff.md
git commit --no-gpg-sign -m "$(cat <<'EOF'
T34: Complete inline discussion threads — update progress

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

**Step 6: Stop. Do not start T35.**

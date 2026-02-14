# T33: Summary Comment — Layer 1 Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Format code review findings as a markdown summary comment and post it to the GitLab MR via the bot account (Layer 1 of the 3-Layer Comment Pattern).

**Architecture:** A dedicated `SummaryCommentFormatter` service converts a validated `CodeReviewSchema` result array into a markdown string. A `PostSummaryComment` queued job receives the task ID, formats the comment, posts it via `GitLabClient::createMergeRequestNote()`, and stores the returned `note_id` on the task's `comment_id` field (for T36 placeholder-then-update). The `ProcessTaskResult` job dispatches `PostSummaryComment` after successful result processing.

**Tech Stack:** Laravel 11, Pest (testing), GitLab REST API v4 (notes endpoint)

---

### Task 1: Write failing tests for SummaryCommentFormatter — happy path

**Files:**
- Create: `tests/Unit/Services/SummaryCommentFormatterTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Services\SummaryCommentFormatter;

// ─── Helpers ─────────────────────────────────────────────────────

function mixedReviewResult(): array
{
    return [
        'version' => '1.0',
        'summary' => [
            'risk_level' => 'medium',
            'total_findings' => 3,
            'findings_by_severity' => ['critical' => 1, 'major' => 1, 'minor' => 1],
            'walkthrough' => [
                ['file' => 'src/auth.py', 'change_summary' => 'Added OAuth2 token refresh logic'],
                ['file' => 'tests/test_auth.py', 'change_summary' => 'Added 3 test cases'],
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
                'description' => 'User input is interpolated directly into SQL query.',
                'suggestion' => '```diff\n-  query(f"SELECT * FROM users WHERE id={id}")\n+  query("SELECT * FROM users WHERE id=?", [id])\n```',
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
                'description' => 'The user variable may be null when accessed.',
                'suggestion' => '```diff\n-  user.name\n+  user?.name ?? "Unknown"\n```',
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
                'description' => 'The os module is imported but never used.',
                'suggestion' => '```diff\n-  import os\n```',
                'labels' => [],
            ],
        ],
        'labels' => ['ai::reviewed', 'ai::risk-medium'],
        'commit_status' => 'success',
    ];
}

// ─── Happy path: mixed severities ───────────────────────────────

it('formats a mixed-severity review into correct markdown', function () {
    $formatter = new SummaryCommentFormatter();
    $markdown = $formatter->format(mixedReviewResult());

    // Header
    expect($markdown)->toContain('## 🤖 AI Code Review');

    // Risk badge line
    expect($markdown)->toContain('🟡 Medium')
        ->and($markdown)->toContain('**Issues Found:** 3')
        ->and($markdown)->toContain('**Files Changed:** 2');

    // Walkthrough section (collapsible)
    expect($markdown)->toContain('<details>')
        ->and($markdown)->toContain('📋 Walkthrough')
        ->and($markdown)->toContain('`src/auth.py`')
        ->and($markdown)->toContain('Added OAuth2 token refresh logic')
        ->and($markdown)->toContain('`tests/test_auth.py`')
        ->and($markdown)->toContain('Added 3 test cases');

    // Findings section (collapsible)
    expect($markdown)->toContain('🔍 Findings Summary')
        ->and($markdown)->toContain('🔴 Critical')
        ->and($markdown)->toContain('🟡 Major')
        ->and($markdown)->toContain('🟢 Minor')
        ->and($markdown)->toContain('`src/auth.py:42`')
        ->and($markdown)->toContain('SQL injection risk')
        ->and($markdown)->toContain('`src/utils.py:18`')
        ->and($markdown)->toContain('Null pointer dereference')
        ->and($markdown)->toContain('`src/config.py:7`')
        ->and($markdown)->toContain('Unused import');
});

it('includes the correct severity emojis in findings rows', function () {
    $formatter = new SummaryCommentFormatter();
    $markdown = $formatter->format(mixedReviewResult());

    // Each finding should have: | # | emoji Severity | Category | `file:line` | Title |
    expect($markdown)->toContain('| 1 | 🔴 Critical | Security |')
        ->and($markdown)->toContain('| 2 | 🟡 Major | Bug |')
        ->and($markdown)->toContain('| 3 | 🟢 Minor | Style |');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Services/SummaryCommentFormatterTest.php`
Expected: FAIL — class `App\Services\SummaryCommentFormatter` not found

---

### Task 2: Write failing tests for SummaryCommentFormatter — edge cases

**Files:**
- Modify: `tests/Unit/Services/SummaryCommentFormatterTest.php`

**Step 1: Add edge case tests**

Append to the test file:

```php
// ─── Edge: zero findings ────────────────────────────────────────

it('formats a zero-findings review correctly', function () {
    $result = [
        'version' => '1.0',
        'summary' => [
            'risk_level' => 'low',
            'total_findings' => 0,
            'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 0],
            'walkthrough' => [
                ['file' => 'src/config.py', 'change_summary' => 'Updated default timeout value'],
            ],
        ],
        'findings' => [],
        'labels' => ['ai::reviewed', 'ai::risk-low'],
        'commit_status' => 'success',
    ];

    $formatter = new SummaryCommentFormatter();
    $markdown = $formatter->format($result);

    expect($markdown)->toContain('## 🤖 AI Code Review')
        ->and($markdown)->toContain('🟢 Low')
        ->and($markdown)->toContain('**Issues Found:** 0')
        ->and($markdown)->toContain('**Files Changed:** 1')
        ->and($markdown)->toContain('📋 Walkthrough')
        ->and($markdown)->toContain('`src/config.py`')
        ->and($markdown)->toContain('🔍 Findings Summary');
});

// ─── Edge: all critical findings ────────────────────────────────

it('formats an all-critical review with high risk correctly', function () {
    $result = [
        'version' => '1.0',
        'summary' => [
            'risk_level' => 'high',
            'total_findings' => 2,
            'findings_by_severity' => ['critical' => 2, 'major' => 0, 'minor' => 0],
            'walkthrough' => [
                ['file' => 'src/auth.py', 'change_summary' => 'Disabled authentication check'],
                ['file' => 'src/db.py', 'change_summary' => 'Added raw SQL queries'],
            ],
        ],
        'findings' => [
            [
                'id' => 1,
                'severity' => 'critical',
                'category' => 'security',
                'file' => 'src/auth.py',
                'line' => 10,
                'end_line' => 15,
                'title' => 'Authentication bypass',
                'description' => 'Auth check removed entirely.',
                'suggestion' => 'Restore the auth middleware.',
                'labels' => [],
            ],
            [
                'id' => 2,
                'severity' => 'critical',
                'category' => 'security',
                'file' => 'src/db.py',
                'line' => 25,
                'end_line' => 30,
                'title' => 'SQL injection',
                'description' => 'Raw user input in SQL.',
                'suggestion' => 'Use parameterized queries.',
                'labels' => [],
            ],
        ],
        'labels' => ['ai::reviewed', 'ai::risk-high'],
        'commit_status' => 'failed',
    ];

    $formatter = new SummaryCommentFormatter();
    $markdown = $formatter->format($result);

    expect($markdown)->toContain('🔴 High')
        ->and($markdown)->toContain('**Issues Found:** 2')
        ->and($markdown)->toContain('**Files Changed:** 2')
        ->and($markdown)->toContain('| 1 | 🔴 Critical | Security |')
        ->and($markdown)->toContain('| 2 | 🔴 Critical | Security |');
});

// ─── Edge: category display uses title case ─────────────────────

it('capitalizes category names in the findings table', function () {
    $result = mixedReviewResult();

    $formatter = new SummaryCommentFormatter();
    $markdown = $formatter->format($result);

    expect($markdown)->toContain('| Security |')
        ->and($markdown)->toContain('| Bug |')
        ->and($markdown)->toContain('| Style |');
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/Services/SummaryCommentFormatterTest.php`
Expected: FAIL — class still not found (5 total failing tests)

---

### Task 3: Implement SummaryCommentFormatter

**Files:**
- Create: `app/Services/SummaryCommentFormatter.php`

**Step 1: Implement the formatter**

```php
<?php

namespace App\Services;

/**
 * Formats a validated CodeReviewSchema result as a markdown summary comment.
 *
 * Layer 1 of the 3-Layer Comment Pattern (§4.5).
 * Produces: header + risk badge + collapsible walkthrough + collapsible findings.
 *
 * @see \App\Schemas\CodeReviewSchema
 */
class SummaryCommentFormatter
{
    private const RISK_BADGES = [
        'high' => '🔴 High',
        'medium' => '🟡 Medium',
        'low' => '🟢 Low',
    ];

    private const SEVERITY_BADGES = [
        'critical' => '🔴 Critical',
        'major' => '🟡 Major',
        'minor' => '🟢 Minor',
    ];

    /**
     * Format a validated code review result as a markdown summary comment.
     *
     * @param  array  $result  A validated CodeReviewSchema array.
     */
    public function format(array $result): string
    {
        $summary = $result['summary'];
        $findings = $result['findings'];

        $riskBadge = self::RISK_BADGES[$summary['risk_level']] ?? $summary['risk_level'];
        $issueCount = $summary['total_findings'];
        $filesChanged = count($summary['walkthrough']);

        $lines = [];

        // Header + risk badge
        $lines[] = '## 🤖 AI Code Review';
        $lines[] = '';
        $lines[] = "**Risk Level:** {$riskBadge} | **Issues Found:** {$issueCount} | **Files Changed:** {$filesChanged}";
        $lines[] = '';

        // Walkthrough (collapsible)
        $lines[] = '<details>';
        $lines[] = '<summary>📋 Walkthrough</summary>';
        $lines[] = '';
        $lines[] = '| File | Change |';
        $lines[] = '|------|--------|';
        foreach ($summary['walkthrough'] as $entry) {
            $file = '`' . $entry['file'] . '`';
            $lines[] = "| {$file} | {$entry['change_summary']} |";
        }
        $lines[] = '';
        $lines[] = '</details>';
        $lines[] = '';

        // Findings (collapsible)
        $lines[] = '<details>';
        $lines[] = '<summary>🔍 Findings Summary</summary>';
        $lines[] = '';
        $lines[] = '| # | Severity | Category | File | Description |';
        $lines[] = '|---|----------|----------|------|-------------|';
        foreach ($findings as $finding) {
            $severity = self::SEVERITY_BADGES[$finding['severity']] ?? $finding['severity'];
            $category = ucfirst($finding['category']);
            $file = '`' . $finding['file'] . ':' . $finding['line'] . '`';
            $lines[] = "| {$finding['id']} | {$severity} | {$category} | {$file} | {$finding['title']} |";
        }
        $lines[] = '';
        $lines[] = '</details>';

        return implode("\n", $lines);
    }
}
```

**Step 2: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Services/SummaryCommentFormatterTest.php`
Expected: PASS (5 tests)

**Step 3: Commit**

```bash
git add app/Services/SummaryCommentFormatter.php tests/Unit/Services/SummaryCommentFormatterTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T33.1: Add SummaryCommentFormatter with tests for all edge cases

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Write failing test for PostSummaryComment job

**Files:**
- Create: `tests/Feature/Jobs/PostSummaryCommentTest.php`

**Step 1: Write the failing test**

The job should:
1. Load the task by ID
2. Format the result with `SummaryCommentFormatter`
3. Call `GitLabClient::createMergeRequestNote()` to post the comment
4. Store the returned `note_id` on `task->comment_id`

```php
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
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Jobs/PostSummaryCommentTest.php`
Expected: FAIL — class `App\Jobs\PostSummaryComment` not found

---

### Task 5: Implement PostSummaryComment job

**Files:**
- Create: `app/Jobs/PostSummaryComment.php`

**Step 1: Implement the job**

```php
<?php

namespace App\Jobs;

use App\Models\Task;
use App\Services\GitLabClient;
use App\Services\SummaryCommentFormatter;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Post a summary comment (Layer 1) on a GitLab merge request.
 *
 * Formats the validated code review result as markdown and posts it
 * as an MR-level note via the bot account. Stores the returned note ID
 * on the task's comment_id for the placeholder-then-update pattern (T36).
 *
 * @see §4.5 3-Layer Comment Pattern — Layer 1
 */
class PostSummaryComment implements ShouldQueue
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
            Log::warning('PostSummaryComment: task not found', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->mr_iid === null) {
            Log::info('PostSummaryComment: task has no MR, skipping', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->result === null) {
            Log::info('PostSummaryComment: task has no result, skipping', ['task_id' => $this->taskId]);

            return;
        }

        $formatter = new SummaryCommentFormatter();
        $markdown = $formatter->format($task->result);

        try {
            $note = $gitLab->createMergeRequestNote(
                $task->project->gitlab_project_id,
                $task->mr_iid,
                $markdown,
            );

            $task->comment_id = $note['id'];
            $task->save();

            Log::info('PostSummaryComment: posted', [
                'task_id' => $this->taskId,
                'note_id' => $note['id'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('PostSummaryComment: failed to post comment', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
```

**Step 2: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Jobs/PostSummaryCommentTest.php`
Expected: PASS (4 tests)

**Step 3: Commit**

```bash
git add app/Jobs/PostSummaryComment.php tests/Feature/Jobs/PostSummaryCommentTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T33.2: Add PostSummaryComment job with GitLab API integration

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Wire PostSummaryComment dispatch into ProcessTaskResult

**Files:**
- Modify: `app/Jobs/ProcessTaskResult.php`
- Create: `tests/Feature/Jobs/ProcessTaskResultDispatchTest.php`

**Step 1: Write the failing test**

Create `tests/Feature/Jobs/ProcessTaskResultDispatchTest.php`:

```php
<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\PostSummaryComment;
use App\Jobs\ProcessTaskResult;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('dispatches PostSummaryComment after successful code review processing', function () {
    Queue::fake([PostSummaryComment::class]);

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
    Queue::fake([PostSummaryComment::class]);

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
    Queue::fake([PostSummaryComment::class]);

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
    Queue::fake([PostSummaryComment::class]);

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
    Queue::fake([PostSummaryComment::class]);

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
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Jobs/ProcessTaskResultDispatchTest.php`
Expected: FAIL — PostSummaryComment is never dispatched (first two tests fail)

**Step 3: Modify ProcessTaskResult to dispatch PostSummaryComment**

Modify `app/Jobs/ProcessTaskResult.php`. In the `handle()` method, after the `$result = $processor->process($task);` call, add the dispatch logic:

```php
// At the top of the file, add the import:
use App\Enums\TaskType;

// After the existing result processing block, add:
if ($result['success'] && $this->shouldPostSummaryComment($task)) {
    PostSummaryComment::dispatch($task->id);
}
```

Add a new private method to the class:

```php
private function shouldPostSummaryComment(Task $task): bool
{
    return $task->mr_iid !== null
        && in_array($task->type, [TaskType::CodeReview, TaskType::SecurityAudit], true);
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Jobs/ProcessTaskResultDispatchTest.php`
Expected: PASS (5 tests)

**Step 5: Commit**

```bash
git add app/Jobs/ProcessTaskResult.php tests/Feature/Jobs/ProcessTaskResultDispatchTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T33.3: Wire PostSummaryComment dispatch into ProcessTaskResult

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Add T33 verification checks to verify_m2.py

**Files:**
- Modify: `verify/verify_m2.py`

**Step 1: Add T33 structural checks**

Insert the following block after the T32 section (before the `# Runtime checks` section):

```python
# ============================================================
#  T33: Summary comment — Layer 1
# ============================================================
section("T33: Summary Comment — Layer 1")

# Formatter service
checker.check(
    "SummaryCommentFormatter service exists",
    file_exists("app/Services/SummaryCommentFormatter.php"),
)
checker.check(
    "SummaryCommentFormatter has format method",
    file_contains("app/Services/SummaryCommentFormatter.php", "function format(array"),
)
checker.check(
    "SummaryCommentFormatter has risk badge mapping",
    file_contains("app/Services/SummaryCommentFormatter.php", "RISK_BADGES"),
)
checker.check(
    "SummaryCommentFormatter has severity badge mapping",
    file_contains("app/Services/SummaryCommentFormatter.php", "SEVERITY_BADGES"),
)
checker.check(
    "SummaryCommentFormatter produces correct header",
    file_contains("app/Services/SummaryCommentFormatter.php", "AI Code Review"),
)
checker.check(
    "SummaryCommentFormatter produces collapsible walkthrough",
    file_contains("app/Services/SummaryCommentFormatter.php", "Walkthrough"),
)
checker.check(
    "SummaryCommentFormatter produces collapsible findings",
    file_contains("app/Services/SummaryCommentFormatter.php", "Findings Summary"),
)

# PostSummaryComment job
checker.check(
    "PostSummaryComment job exists",
    file_exists("app/Jobs/PostSummaryComment.php"),
)
checker.check(
    "PostSummaryComment implements ShouldQueue",
    file_contains("app/Jobs/PostSummaryComment.php", "ShouldQueue"),
)
checker.check(
    "PostSummaryComment uses vunnix-server queue",
    file_contains("app/Jobs/PostSummaryComment.php", "QueueNames::SERVER"),
)
checker.check(
    "PostSummaryComment calls createMergeRequestNote",
    file_contains("app/Jobs/PostSummaryComment.php", "createMergeRequestNote"),
)
checker.check(
    "PostSummaryComment stores comment_id on task",
    file_contains("app/Jobs/PostSummaryComment.php", "comment_id"),
)
checker.check(
    "PostSummaryComment uses SummaryCommentFormatter",
    file_contains("app/Jobs/PostSummaryComment.php", "SummaryCommentFormatter"),
)

# ProcessTaskResult dispatches PostSummaryComment
checker.check(
    "ProcessTaskResult dispatches PostSummaryComment",
    file_contains("app/Jobs/ProcessTaskResult.php", "PostSummaryComment"),
)
checker.check(
    "ProcessTaskResult checks task type for summary comment dispatch",
    file_contains("app/Jobs/ProcessTaskResult.php", "CodeReview"),
)

# Tests
checker.check(
    "SummaryCommentFormatter unit test exists",
    file_exists("tests/Unit/Services/SummaryCommentFormatterTest.php"),
)
checker.check(
    "Test covers mixed-severity formatting",
    file_contains(
        "tests/Unit/Services/SummaryCommentFormatterTest.php",
        "mixed-severity review",
    ),
)
checker.check(
    "Test covers zero-findings edge case",
    file_contains(
        "tests/Unit/Services/SummaryCommentFormatterTest.php",
        "zero-findings review",
    ),
)
checker.check(
    "Test covers all-critical edge case",
    file_contains(
        "tests/Unit/Services/SummaryCommentFormatterTest.php",
        "all-critical review",
    ),
)
checker.check(
    "PostSummaryComment feature test exists",
    file_exists("tests/Feature/Jobs/PostSummaryCommentTest.php"),
)
checker.check(
    "Test covers posting comment and storing note ID",
    file_contains(
        "tests/Feature/Jobs/PostSummaryCommentTest.php",
        "stores the note ID",
    ),
)
checker.check(
    "ProcessTaskResult dispatch test exists",
    file_exists("tests/Feature/Jobs/ProcessTaskResultDispatchTest.php"),
)
checker.check(
    "Test covers dispatch for code review",
    file_contains(
        "tests/Feature/Jobs/ProcessTaskResultDispatchTest.php",
        "code review processing",
    ),
)
checker.check(
    "Test covers no dispatch for non-review types",
    file_contains(
        "tests/Feature/Jobs/ProcessTaskResultDispatchTest.php",
        "non-review task types",
    ),
)
checker.check(
    "Test covers no dispatch on validation failure",
    file_contains(
        "tests/Feature/Jobs/ProcessTaskResultDispatchTest.php",
        "validation fails",
    ),
)
```

**Step 2: Run verification**

Run: `python3 verify/verify_m2.py`
Expected: All T33 checks pass

**Step 3: Commit**

```bash
git add verify/verify_m2.py
git commit --no-gpg-sign -m "$(cat <<'EOF'
T33.4: Add T33 verification checks to verify_m2.py

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Run full verification and update progress

**Files:**
- Modify: `progress.md`
- Modify: `handoff.md`

**Step 1: Run full test suite**

Run: `php artisan test`
Expected: All tests pass (existing + new T33 tests)

**Step 2: Run M2 verification**

Run: `python3 verify/verify_m2.py`
Expected: All checks pass including T33

**Step 3: Squash sub-task commits into single T33 commit**

```bash
git reset --soft HEAD~4
git commit --no-gpg-sign -m "$(cat <<'EOF'
T33: Add Summary Comment formatter and posting job (Layer 1)

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

**Step 4: Update progress.md**

- Check `[x]` for T33
- Bold T34 as next task
- Update summary: Tasks Complete: 33 / 116 (28%)
- Update Last Verified: T33

**Step 5: Clear handoff.md**

Reset to empty template.

**Step 6: Final commit**

```bash
git add progress.md handoff.md
git commit --no-gpg-sign -m "$(cat <<'EOF'
T33: Update progress — Summary Comment Layer 1 complete

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

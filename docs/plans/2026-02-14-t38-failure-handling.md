# T38: Failure Handling (DLQ + Failure Comment) Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** When a task permanently fails (max retries exhausted or non-retryable error), transition it to Failed, create a dead letter queue record with full error history, and post a failure comment on the GitLab MR/Issue.

**Architecture:** The `ProcessTask` and `ProcessTaskResult` jobs already use `RetryWithBackoff` middleware. We add a `failed()` method to each job class — Laravel calls this automatically when `$job->fail()` is invoked. The `failed()` method: (1) transitions the Task to Failed, (2) creates a `DeadLetterEntry` record with the task snapshot + error details, and (3) dispatches a `PostFailureComment` job. The `PostFailureComment` job posts/updates a comment on the GitLab MR or Issue. Non-task jobs (PostSummaryComment, PostInlineThreads, PostLabelsAndStatus) that fail after retries do NOT create DLQ entries — they're downstream posting jobs and the task is already completed; their failures are logged but not DLQ'd.

**Tech Stack:** Laravel 11 (Pest testing, Eloquent, Queue jobs), PostgreSQL (JSONB)

---

### Task 1: Create DeadLetterEntry model

**Files:**
- Create: `app/Models/DeadLetterEntry.php`
- Test: `tests/Unit/Models/DeadLetterEntryTest.php`

**Step 1: Write the failing test**

```php
// tests/Unit/Models/DeadLetterEntryTest.php
<?php

use App\Models\DeadLetterEntry;

it('has correct table name', function () {
    $entry = new DeadLetterEntry();
    expect($entry->getTable())->toBe('dead_letter_queue');
});

it('casts task_record to array', function () {
    $entry = new DeadLetterEntry();
    $casts = $entry->getCasts();
    expect($casts['task_record'])->toBe('array');
});

it('casts attempts to array', function () {
    $entry = new DeadLetterEntry();
    $casts = $entry->getCasts();
    expect($casts['attempts'])->toBe('array');
});

it('casts dismissed to boolean', function () {
    $entry = new DeadLetterEntry();
    $casts = $entry->getCasts();
    expect($casts['dismissed'])->toBe('boolean');
});

it('casts timestamps correctly', function () {
    $entry = new DeadLetterEntry();
    $casts = $entry->getCasts();
    expect($casts['originally_queued_at'])->toBe('datetime');
    expect($casts['dead_lettered_at'])->toBe('datetime');
    expect($casts['dismissed_at'])->toBe('datetime');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Models/DeadLetterEntryTest.php`
Expected: FAIL — class not found

**Step 3: Write minimal implementation**

```php
// app/Models/DeadLetterEntry.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dead letter queue entry for tasks that failed permanently.
 *
 * Stores a full snapshot of the task at failure time, the failure reason,
 * error details, and retry attempt history. Admin can inspect, retry, or dismiss.
 *
 * @see §19.4 Dead Letter Queue
 */
class DeadLetterEntry extends Model
{
    protected $table = 'dead_letter_queue';

    protected $fillable = [
        'task_record',
        'failure_reason',
        'error_details',
        'attempts',
        'dismissed',
        'dismissed_at',
        'dismissed_by',
        'originally_queued_at',
        'dead_lettered_at',
    ];

    protected function casts(): array
    {
        return [
            'task_record' => 'array',
            'attempts' => 'array',
            'dismissed' => 'boolean',
            'originally_queued_at' => 'datetime',
            'dead_lettered_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────

    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Models/DeadLetterEntryTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Models/DeadLetterEntry.php tests/Unit/Models/DeadLetterEntryTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T38.1: Add DeadLetterEntry Eloquent model

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Create FailureHandler service

**Files:**
- Create: `app/Services/FailureHandler.php`
- Test: `tests/Feature/Services/FailureHandlerTest.php`

This is the central service that all job `failed()` methods will call. It handles:
1. Transitioning the task to Failed status
2. Creating the DLQ entry with task snapshot + error details
3. Dispatching the PostFailureComment job

**Step 1: Write the failing test**

```php
// tests/Feature/Services/FailureHandlerTest.php
<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\PostFailureComment;
use App\Models\DeadLetterEntry;
use App\Models\Task;
use App\Services\FailureHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ─── Creates DLQ entry on permanent failure ──────────────────────

it('creates a DLQ entry when a task fails permanently', function () {
    Queue::fake();

    $task = Task::factory()->create([
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'retry_count' => 3,
    ]);

    $handler = new FailureHandler();
    $handler->handlePermanentFailure(
        task: $task,
        failureReason: 'max_retries_exceeded',
        errorDetails: 'HTTP 503: Service Unavailable',
        attempts: [
            ['attempt' => 1, 'timestamp' => '2026-02-14T10:00:00Z', 'error' => 'HTTP 503'],
            ['attempt' => 2, 'timestamp' => '2026-02-14T10:00:30Z', 'error' => 'HTTP 503'],
            ['attempt' => 3, 'timestamp' => '2026-02-14T10:02:30Z', 'error' => 'HTTP 503'],
        ],
    );

    // Task should be Failed
    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed);
    expect($task->error_reason)->toBe('max_retries_exceeded');

    // DLQ entry should exist
    expect(DeadLetterEntry::count())->toBe(1);

    $entry = DeadLetterEntry::first();
    expect($entry->failure_reason)->toBe('max_retries_exceeded');
    expect($entry->error_details)->toBe('HTTP 503: Service Unavailable');
    expect($entry->attempts)->toHaveCount(3);
    expect($entry->task_record)->toBeArray();
    expect($entry->task_record['id'])->toBe($task->id);
    expect($entry->dead_lettered_at)->not->toBeNull();
});

// ─── Dispatches failure comment job ──────────────────────────────

it('dispatches PostFailureComment job on permanent failure', function () {
    Queue::fake();

    $task = Task::factory()->create([
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
    ]);

    $handler = new FailureHandler();
    $handler->handlePermanentFailure(
        task: $task,
        failureReason: 'max_retries_exceeded',
        errorDetails: 'HTTP 503',
        attempts: [],
    );

    Queue::assertPushed(PostFailureComment::class, function ($job) use ($task) {
        return $job->taskId === $task->id
            && $job->failureReason === 'max_retries_exceeded'
            && $job->errorDetails === 'HTTP 503';
    });
});

// ─── Skips failure comment for tasks with no MR and no Issue ─────

it('does not dispatch PostFailureComment for tasks without MR or Issue', function () {
    Queue::fake();

    $task = Task::factory()->create([
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => null,
        'issue_iid' => null,
    ]);

    $handler = new FailureHandler();
    $handler->handlePermanentFailure(
        task: $task,
        failureReason: 'max_retries_exceeded',
        errorDetails: 'HTTP 503',
        attempts: [],
    );

    Queue::assertNotPushed(PostFailureComment::class);

    // DLQ entry should still be created
    expect(DeadLetterEntry::count())->toBe(1);
});

// ─── Handles task already in terminal state ──────────────────────

it('does not transition or DLQ a task already in terminal state', function () {
    Queue::fake();

    $task = Task::factory()->create([
        'status' => TaskStatus::Completed,
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
    ]);

    $handler = new FailureHandler();
    $handler->handlePermanentFailure(
        task: $task,
        failureReason: 'max_retries_exceeded',
        errorDetails: 'HTTP 503',
        attempts: [],
    );

    // Should not create DLQ entry — task already completed
    expect(DeadLetterEntry::count())->toBe(0);
    Queue::assertNotPushed(PostFailureComment::class);

    // Status should remain Completed
    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Completed);
});

// ─── Snapshots full task record ──────────────────────────────────

it('snapshots the full task record including relationships', function () {
    Queue::fake();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
        'commit_sha' => 'abc123',
        'retry_count' => 3,
    ]);

    $handler = new FailureHandler();
    $handler->handlePermanentFailure(
        task: $task,
        failureReason: 'max_retries_exceeded',
        errorDetails: 'HTTP 503',
        attempts: [],
    );

    $entry = DeadLetterEntry::first();
    expect($entry->task_record['type'])->toBe('code_review');
    expect($entry->task_record['mr_iid'])->toBe(42);
    expect($entry->task_record['commit_sha'])->toBe('abc123');
    expect($entry->originally_queued_at)->not->toBeNull();
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Services/FailureHandlerTest.php`
Expected: FAIL — FailureHandler class not found

**Step 3: Write minimal implementation**

```php
// app/Services/FailureHandler.php
<?php

namespace App\Services;

use App\Jobs\PostFailureComment;
use App\Models\DeadLetterEntry;
use App\Models\Task;
use Illuminate\Support\Facades\Log;

/**
 * Handles permanent task failures: DLQ entry + failure comment.
 *
 * Called by job `failed()` methods when max retries are exhausted
 * or a non-retryable error occurs.
 *
 * @see §19.4 Dead Letter Queue
 * @see §3.4 Task Dispatcher & Task Executor
 */
class FailureHandler
{
    /**
     * Handle a task that has permanently failed.
     *
     * @param  Task  $task  The task that failed
     * @param  string  $failureReason  Classification: max_retries_exceeded, invalid_request, context_exceeded, scheduling_timeout, expired
     * @param  string  $errorDetails  Last error message / HTTP status / response body
     * @param  array<int, array{attempt: int, timestamp: string, error: string}>  $attempts  Retry attempt history
     */
    public function handlePermanentFailure(
        Task $task,
        string $failureReason,
        string $errorDetails,
        array $attempts,
    ): void {
        // Don't process tasks already in terminal state
        if ($task->isTerminal()) {
            Log::info('FailureHandler: task already terminal, skipping', [
                'task_id' => $task->id,
                'status' => $task->status->value,
            ]);

            return;
        }

        // 1. Transition task to Failed
        $task->transitionTo(\App\Enums\TaskStatus::Failed, $failureReason);

        Log::error('FailureHandler: task permanently failed', [
            'task_id' => $task->id,
            'failure_reason' => $failureReason,
            'error_details' => $errorDetails,
        ]);

        // 2. Create DLQ entry
        DeadLetterEntry::create([
            'task_record' => $task->toArray(),
            'failure_reason' => $failureReason,
            'error_details' => $errorDetails,
            'attempts' => $attempts,
            'originally_queued_at' => $task->created_at,
            'dead_lettered_at' => now(),
        ]);

        // 3. Dispatch failure comment (only for tasks with MR or Issue)
        if ($task->mr_iid !== null || $task->issue_iid !== null) {
            PostFailureComment::dispatch(
                taskId: $task->id,
                failureReason: $failureReason,
                errorDetails: $errorDetails,
            );
        }
    }
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Services/FailureHandlerTest.php`
Expected: FAIL — PostFailureComment class not found (that's OK, we need Task 3 first)

> **Note:** Task 3 creates the `PostFailureComment` job. Run both Task 2 and Task 3 tests together after Task 3.

**Step 5: Commit** (after Task 3)

---

### Task 3: Create PostFailureComment job

**Files:**
- Create: `app/Jobs/PostFailureComment.php`
- Test: `tests/Feature/Jobs/PostFailureCommentTest.php`

**Step 1: Write the failing test**

```php
// tests/Feature/Jobs/PostFailureCommentTest.php
<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\PostFailureComment;
use App\Models\Task;
use App\Services\GitLabClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// ─── Posts failure comment on MR ─────────────────────────────────

it('posts a failure comment on the MR', function () {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response([
            'id' => 1234,
            'body' => 'mocked',
        ], 201),
    ]);

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Failed,
        'mr_iid' => 42,
        'error_reason' => 'max_retries_exceeded',
    ]);

    $job = new PostFailureComment(
        taskId: $task->id,
        failureReason: 'max_retries_exceeded',
        errorDetails: 'HTTP 503: Service Unavailable',
    );
    $job->handle(app(GitLabClient::class));

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/notes')
            && str_contains($request['body'], '🤖 AI review failed')
            && str_contains($request['body'], 'Service Unavailable');
    });
});

// ─── Updates placeholder comment in-place on failure ─────────────

it('updates placeholder comment in-place when comment_id exists', function () {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes/*' => Http::response([
            'id' => 5555,
            'body' => 'updated',
        ], 200),
    ]);

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Failed,
        'mr_iid' => 42,
        'comment_id' => 5555,
        'error_reason' => 'max_retries_exceeded',
    ]);

    $job = new PostFailureComment(
        taskId: $task->id,
        failureReason: 'max_retries_exceeded',
        errorDetails: 'HTTP 503: Service Unavailable',
    );
    $job->handle(app(GitLabClient::class));

    // Should use PUT (update), not POST (create)
    Http::assertSent(function ($request) {
        return $request->method() === 'PUT'
            && str_contains($request->url(), '/notes/5555')
            && str_contains($request['body'], '🤖 AI review failed');
    });
});

// ─── Posts failure comment on Issue ──────────────────────────────

it('posts a failure comment on an Issue when task has issue_iid', function () {
    Http::fake([
        '*/api/v4/projects/*/issues/*/notes' => Http::response([
            'id' => 5678,
            'body' => 'mocked',
        ], 201),
    ]);

    $task = Task::factory()->create([
        'type' => TaskType::IssueDiscussion,
        'status' => TaskStatus::Failed,
        'mr_iid' => null,
        'issue_iid' => 10,
        'error_reason' => 'invalid_request',
    ]);

    $job = new PostFailureComment(
        taskId: $task->id,
        failureReason: 'invalid_request',
        errorDetails: 'HTTP 400: Bad Request',
    );
    $job->handle(app(GitLabClient::class));

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/issues/')
            && str_contains($request->url(), '/notes')
            && str_contains($request['body'], '🤖 AI review failed');
    });
});

// ─── Skips if task not found ─────────────────────────────────────

it('returns early if the task does not exist', function () {
    Http::fake();

    $job = new PostFailureComment(
        taskId: 999999,
        failureReason: 'max_retries_exceeded',
        errorDetails: 'HTTP 503',
    );
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Skips if no MR and no Issue ─────────────────────────────────

it('returns early if task has no MR and no Issue', function () {
    Http::fake();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Failed,
        'mr_iid' => null,
        'issue_iid' => null,
    ]);

    $job = new PostFailureComment(
        taskId: $task->id,
        failureReason: 'max_retries_exceeded',
        errorDetails: 'HTTP 503',
    );
    $job->handle(app(GitLabClient::class));

    Http::assertNothingSent();
});

// ─── Failure comment includes human-readable reason ──────────────

it('formats the failure reason as human-readable text', function () {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response([
            'id' => 1234,
            'body' => 'mocked',
        ], 201),
    ]);

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Failed,
        'mr_iid' => 42,
    ]);

    $job = new PostFailureComment(
        taskId: $task->id,
        failureReason: 'context_exceeded',
        errorDetails: 'Input too large for context window',
    );
    $job->handle(app(GitLabClient::class));

    Http::assertSent(function ($request) {
        $body = $request['body'];
        return str_contains($body, 'too large')
            || str_contains($body, 'context window');
    });
});

// ─── Best-effort: logs but does not re-throw on GitLab API error ─

it('catches and logs GitLab API errors without re-throwing', function () {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/*/notes' => Http::response('Server Error', 500),
    ]);

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Failed,
        'mr_iid' => 42,
    ]);

    // Should not throw
    $job = new PostFailureComment(
        taskId: $task->id,
        failureReason: 'max_retries_exceeded',
        errorDetails: 'HTTP 503',
    );
    $job->handle(app(GitLabClient::class));

    // If we get here without exception, the test passes
    expect(true)->toBeTrue();
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Jobs/PostFailureCommentTest.php`
Expected: FAIL — PostFailureComment class not found

**Step 3: Write minimal implementation**

```php
// app/Jobs/PostFailureComment.php
<?php

namespace App\Jobs;

use App\Models\Task;
use App\Services\GitLabClient;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Post a failure comment on a GitLab MR or Issue when a task permanently fails.
 *
 * Best-effort: catches exceptions to avoid infinite retry loops on failure comments.
 * If a placeholder comment exists (from T36), updates it in-place.
 *
 * @see §19.3 Job Timeout & Retry Policy — "After Max Retries" column
 * @see §19.4 Dead Letter Queue
 */
class PostFailureComment implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    private const REASON_MESSAGES = [
        'max_retries_exceeded' => 'The service encountered repeated errors and could not complete after multiple retries.',
        'invalid_request' => 'The request was invalid and could not be processed.',
        'context_exceeded' => 'The merge request may be too large for analysis. Consider splitting it into smaller MRs.',
        'scheduling_timeout' => 'The task could not be scheduled for execution within the time limit.',
        'expired' => 'The task expired while waiting in the queue due to service unavailability. Push a new commit to trigger a fresh review.',
        'pipeline_trigger_failed' => 'Failed to trigger the CI pipeline for execution.',
        'missing_trigger_token' => 'The CI trigger token is not configured for this project.',
    ];

    public function __construct(
        public readonly int $taskId,
        public readonly string $failureReason,
        public readonly string $errorDetails,
    ) {
        $this->queue = QueueNames::SERVER;
    }

    public function handle(GitLabClient $gitLab): void
    {
        $task = Task::with('project')->find($this->taskId);

        if ($task === null) {
            Log::warning('PostFailureComment: task not found', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->mr_iid === null && $task->issue_iid === null) {
            Log::info('PostFailureComment: task has no MR or Issue, skipping', ['task_id' => $this->taskId]);

            return;
        }

        $body = $this->formatFailureComment();

        try {
            if ($task->mr_iid !== null) {
                $this->postToMergeRequest($gitLab, $task, $body);
            } else {
                $this->postToIssue($gitLab, $task, $body);
            }
        } catch (\Throwable $e) {
            // Best-effort: log but don't re-throw.
            // The task is already in DLQ — we don't want the failure comment
            // itself to enter a retry loop.
            Log::warning('PostFailureComment: failed to post comment', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function postToMergeRequest(GitLabClient $gitLab, Task $task, string $body): void
    {
        $projectId = $task->project->gitlab_project_id;

        if ($task->comment_id !== null) {
            // Update placeholder in-place
            $gitLab->updateMergeRequestNote($projectId, $task->mr_iid, $task->comment_id, $body);

            Log::info('PostFailureComment: updated placeholder with failure', [
                'task_id' => $this->taskId,
                'note_id' => $task->comment_id,
            ]);
        } else {
            $note = $gitLab->createMergeRequestNote($projectId, $task->mr_iid, $body);

            Log::info('PostFailureComment: posted failure comment on MR', [
                'task_id' => $this->taskId,
                'note_id' => $note['id'],
            ]);
        }
    }

    private function postToIssue(GitLabClient $gitLab, Task $task, string $body): void
    {
        $projectId = $task->project->gitlab_project_id;

        $gitLab->createIssueNote($projectId, $task->issue_iid, $body);

        Log::info('PostFailureComment: posted failure comment on Issue', [
            'task_id' => $this->taskId,
            'issue_iid' => $task->issue_iid,
        ]);
    }

    private function formatFailureComment(): string
    {
        $reason = self::REASON_MESSAGES[$this->failureReason]
            ?? "An unexpected error occurred ({$this->failureReason}).";

        return "🤖 AI review failed — {$reason}\n\n"
            . "<details>\n<summary>Error details</summary>\n\n"
            . "```\n{$this->errorDetails}\n```\n\n"
            . "</details>";
    }
}
```

**Step 4: Run tests for both Task 2 and Task 3**

Run: `php artisan test tests/Feature/Services/FailureHandlerTest.php tests/Feature/Jobs/PostFailureCommentTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Services/FailureHandler.php tests/Feature/Services/FailureHandlerTest.php app/Jobs/PostFailureComment.php tests/Feature/Jobs/PostFailureCommentTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T38.2: Add FailureHandler service and PostFailureComment job

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Wire ProcessTask job to FailureHandler

**Files:**
- Modify: `app/Jobs/ProcessTask.php`
- Test: `tests/Feature/Jobs/ProcessTaskFailureTest.php`

The key integration: add a `failed()` method to `ProcessTask`. Laravel calls `failed()` automatically when `$job->fail($exception)` is invoked by the middleware. This method resolves the Task model and delegates to `FailureHandler`.

**Step 1: Write the failing test**

```php
// tests/Feature/Jobs/ProcessTaskFailureTest.php
<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Exceptions\GitLabApiException;
use App\Jobs\PostFailureComment;
use App\Jobs\ProcessTask;
use App\Models\DeadLetterEntry;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ─── failed() creates DLQ entry ──────────────────────────────────

it('creates a DLQ entry when ProcessTask fails permanently', function () {
    Queue::fake();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
    ]);

    $exception = new GitLabApiException(
        message: 'HTTP 503: Service Unavailable',
        statusCode: 503,
        responseBody: 'Service Unavailable',
        context: 'triggerPipeline',
    );

    $job = new ProcessTask($task->id);
    $job->failed($exception);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed);
    expect($task->error_reason)->toBe('max_retries_exceeded');

    expect(DeadLetterEntry::count())->toBe(1);
    $entry = DeadLetterEntry::first();
    expect($entry->failure_reason)->toBe('max_retries_exceeded');
    expect($entry->error_details)->toContain('503');
});

// ─── failed() classifies 400 as invalid_request ─────────────────

it('classifies 400 errors as invalid_request in DLQ', function () {
    Queue::fake();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
    ]);

    $exception = new GitLabApiException(
        message: 'HTTP 400: Bad Request',
        statusCode: 400,
        responseBody: '{"error":"invalid"}',
        context: 'triggerPipeline',
    );

    $job = new ProcessTask($task->id);
    $job->failed($exception);

    $entry = DeadLetterEntry::first();
    expect($entry->failure_reason)->toBe('invalid_request');
});

// ─── failed() dispatches PostFailureComment ──────────────────────

it('dispatches PostFailureComment when task has MR', function () {
    Queue::fake();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
    ]);

    $exception = new GitLabApiException(
        message: 'HTTP 503',
        statusCode: 503,
        responseBody: '',
        context: 'test',
    );

    $job = new ProcessTask($task->id);
    $job->failed($exception);

    Queue::assertPushed(PostFailureComment::class, function ($job) use ($task) {
        return $job->taskId === $task->id;
    });
});

// ─── failed() handles non-GitLabApiException ─────────────────────

it('handles non-GitLabApiException in failed() method', function () {
    Queue::fake();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
    ]);

    $exception = new \RuntimeException('Something unexpected happened');

    $job = new ProcessTask($task->id);
    $job->failed($exception);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed);

    $entry = DeadLetterEntry::first();
    expect($entry->failure_reason)->toBe('max_retries_exceeded');
    expect($entry->error_details)->toContain('Something unexpected happened');
});

// ─── failed() skips if task not found ────────────────────────────

it('does not throw if task does not exist', function () {
    Queue::fake();

    $job = new ProcessTask(999999);
    $job->failed(new \RuntimeException('test'));

    // Should not throw — no DLQ entry either
    expect(DeadLetterEntry::count())->toBe(0);
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Jobs/ProcessTaskFailureTest.php`
Expected: FAIL — `failed()` method doesn't exist or doesn't create DLQ

**Step 3: Modify ProcessTask to add failed() method**

Add the following to `app/Jobs/ProcessTask.php`:

```php
// Add these imports at the top:
use App\Exceptions\GitLabApiException;
use App\Services\FailureHandler;

// Add this method to the class:

    /**
     * Handle permanent job failure.
     *
     * Called by Laravel when $job->fail() is invoked (by RetryWithBackoff middleware
     * after max retries, or on non-retryable errors).
     */
    public function failed(?\Throwable $exception): void
    {
        $task = Task::find($this->taskId);

        if ($task === null) {
            Log::warning('ProcessTask::failed: task not found', ['task_id' => $this->taskId]);

            return;
        }

        $failureReason = $this->classifyFailureReason($exception);
        $errorDetails = $exception?->getMessage() ?? 'Unknown error';

        if ($exception instanceof GitLabApiException) {
            $errorDetails = "HTTP {$exception->statusCode}: {$exception->responseBody}";
        }

        app(FailureHandler::class)->handlePermanentFailure(
            task: $task,
            failureReason: $failureReason,
            errorDetails: $errorDetails,
            attempts: [],
        );
    }

    private function classifyFailureReason(?\Throwable $exception): string
    {
        if ($exception instanceof GitLabApiException) {
            if ($exception->isInvalidRequest()) {
                return 'invalid_request';
            }
        }

        return 'max_retries_exceeded';
    }
```

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Jobs/ProcessTaskFailureTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Jobs/ProcessTask.php tests/Feature/Jobs/ProcessTaskFailureTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T38.3: Wire ProcessTask failed() to FailureHandler

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Wire ProcessTaskResult job to FailureHandler

**Files:**
- Modify: `app/Jobs/ProcessTaskResult.php`
- Test: `tests/Feature/Jobs/ProcessTaskResultFailureTest.php`

Same pattern as Task 4 but for `ProcessTaskResult`.

**Step 1: Write the failing test**

```php
// tests/Feature/Jobs/ProcessTaskResultFailureTest.php
<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Exceptions\GitLabApiException;
use App\Jobs\PostFailureComment;
use App\Jobs\ProcessTaskResult;
use App\Models\DeadLetterEntry;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('creates a DLQ entry when ProcessTaskResult fails permanently', function () {
    Queue::fake();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
    ]);

    $exception = new GitLabApiException(
        message: 'HTTP 500',
        statusCode: 500,
        responseBody: 'Internal Server Error',
        context: 'processResult',
    );

    $job = new ProcessTaskResult($task->id);
    $job->failed($exception);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed);

    expect(DeadLetterEntry::count())->toBe(1);
    $entry = DeadLetterEntry::first();
    expect($entry->failure_reason)->toBe('max_retries_exceeded');
});

it('dispatches PostFailureComment when ProcessTaskResult fails', function () {
    Queue::fake();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Running,
        'started_at' => now(),
        'mr_iid' => 42,
    ]);

    $exception = new \RuntimeException('Processing error');

    $job = new ProcessTaskResult($task->id);
    $job->failed($exception);

    Queue::assertPushed(PostFailureComment::class);
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Jobs/ProcessTaskResultFailureTest.php`
Expected: FAIL

**Step 3: Modify ProcessTaskResult to add failed() method**

Add to `app/Jobs/ProcessTaskResult.php`:

```php
// Add these imports at the top:
use App\Exceptions\GitLabApiException;
use App\Services\FailureHandler;

// Add this method to the class:

    /**
     * Handle permanent job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        $task = Task::find($this->taskId);

        if ($task === null) {
            Log::warning('ProcessTaskResult::failed: task not found', ['task_id' => $this->taskId]);

            return;
        }

        $failureReason = 'max_retries_exceeded';
        $errorDetails = $exception?->getMessage() ?? 'Unknown error';

        if ($exception instanceof GitLabApiException) {
            $errorDetails = "HTTP {$exception->statusCode}: {$exception->responseBody}";

            if ($exception->isInvalidRequest()) {
                $failureReason = 'invalid_request';
            }
        }

        app(FailureHandler::class)->handlePermanentFailure(
            task: $task,
            failureReason: $failureReason,
            errorDetails: $errorDetails,
            attempts: [],
        );
    }
```

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Jobs/ProcessTaskResultFailureTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Jobs/ProcessTaskResult.php tests/Feature/Jobs/ProcessTaskResultFailureTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T38.4: Wire ProcessTaskResult failed() to FailureHandler

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Add T38 verification checks to verify_m2.py

**Files:**
- Modify: `verify/verify_m2.py`

**Step 1: Add verification checks**

Append the following section to `verify/verify_m2.py`, after the T37 section:

```python
# ============================================================
#  T38: Failure handling (DLQ, failure comment)
# ============================================================
section("T38: Failure Handling (DLQ, Failure Comment)")

# DeadLetterEntry model
checker.check(
    "DeadLetterEntry model exists",
    file_exists("app/Models/DeadLetterEntry.php"),
)
checker.check(
    "DeadLetterEntry uses dead_letter_queue table",
    file_contains("app/Models/DeadLetterEntry.php", "dead_letter_queue"),
)
checker.check(
    "DeadLetterEntry casts task_record to array",
    file_contains("app/Models/DeadLetterEntry.php", "'task_record' => 'array'"),
)
checker.check(
    "DeadLetterEntry casts attempts to array",
    file_contains("app/Models/DeadLetterEntry.php", "'attempts' => 'array'"),
)

# FailureHandler service
checker.check(
    "FailureHandler service exists",
    file_exists("app/Services/FailureHandler.php"),
)
checker.check(
    "FailureHandler creates DLQ entry",
    file_contains("app/Services/FailureHandler.php", "DeadLetterEntry::create"),
)
checker.check(
    "FailureHandler dispatches PostFailureComment",
    file_contains("app/Services/FailureHandler.php", "PostFailureComment::dispatch"),
)
checker.check(
    "FailureHandler transitions task to Failed",
    file_contains("app/Services/FailureHandler.php", "TaskStatus::Failed"),
)

# PostFailureComment job
checker.check(
    "PostFailureComment job exists",
    file_exists("app/Jobs/PostFailureComment.php"),
)
checker.check(
    "PostFailureComment posts failure emoji message",
    file_contains("app/Jobs/PostFailureComment.php", "AI review failed"),
)
checker.check(
    "PostFailureComment handles MR comments",
    file_contains("app/Jobs/PostFailureComment.php", "createMergeRequestNote"),
)
checker.check(
    "PostFailureComment handles Issue comments",
    file_contains("app/Jobs/PostFailureComment.php", "createIssueNote"),
)
checker.check(
    "PostFailureComment supports placeholder update",
    file_contains("app/Jobs/PostFailureComment.php", "updateMergeRequestNote"),
)
checker.check(
    "PostFailureComment is best-effort (catches exceptions)",
    file_contains("app/Jobs/PostFailureComment.php", "catch (\\Throwable"),
)

# Job wiring — ProcessTask
checker.check(
    "ProcessTask has failed() method",
    file_contains("app/Jobs/ProcessTask.php", "public function failed"),
)
checker.check(
    "ProcessTask::failed uses FailureHandler",
    file_contains("app/Jobs/ProcessTask.php", "FailureHandler"),
)

# Job wiring — ProcessTaskResult
checker.check(
    "ProcessTaskResult has failed() method",
    file_contains("app/Jobs/ProcessTaskResult.php", "public function failed"),
)
checker.check(
    "ProcessTaskResult::failed uses FailureHandler",
    file_contains("app/Jobs/ProcessTaskResult.php", "FailureHandler"),
)

# Tests
checker.check(
    "DeadLetterEntry model test exists",
    file_exists("tests/Unit/Models/DeadLetterEntryTest.php"),
)
checker.check(
    "FailureHandler test exists",
    file_exists("tests/Feature/Services/FailureHandlerTest.php"),
)
checker.check(
    "PostFailureComment test exists",
    file_exists("tests/Feature/Jobs/PostFailureCommentTest.php"),
)
checker.check(
    "ProcessTask failure test exists",
    file_exists("tests/Feature/Jobs/ProcessTaskFailureTest.php"),
)
checker.check(
    "ProcessTaskResult failure test exists",
    file_exists("tests/Feature/Jobs/ProcessTaskResultFailureTest.php"),
)
```

**Step 2: Run verification**

Run: `python3 verify/verify_m2.py`
Expected: All T38 checks pass (along with all existing checks)

**Step 3: Commit**

```bash
git add verify/verify_m2.py
git commit --no-gpg-sign -m "$(cat <<'EOF'
T38.5: Add T38 verification checks to verify_m2.py

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Run full verification and finalize

**Step 1: Run full test suite**

Run: `php artisan test`
Expected: All tests pass

**Step 2: Run M2 verification**

Run: `python3 verify/verify_m2.py`
Expected: All checks pass

**Step 3: Update progress.md**

- Check the box for T38
- Bold the next task (T39)
- Update the M2 count (26/35)
- Update the summary

**Step 4: Update handoff.md**

Clear handoff.md back to the empty template.

**Step 5: Final commit**

```bash
git add progress.md handoff.md
git commit --no-gpg-sign -m "$(cat <<'EOF'
T38: Add failure handling — DLQ entry + failure comment on permanent failure

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Design Notes

**Why `failed()` methods instead of events/listeners:**
Laravel's `failed()` method on job classes is the idiomatic way to handle permanent failures. It's called automatically by the framework when `$job->fail()` is invoked. Using events/listeners would add indirection and make the failure path harder to trace.

**Why PostFailureComment is best-effort (no retries):**
The task is already dead — it's in the DLQ. If the failure comment itself fails to post (e.g., GitLab is still down), we log it and move on. Retrying the failure comment would be counterproductive when the underlying issue is likely GitLab unavailability.

**Why downstream jobs (PostSummaryComment, etc.) don't create DLQ entries:**
These jobs run AFTER the task is already `Completed` — they're cosmetic GitLab posting jobs. If they fail, the task result is still stored. The admin can see the failure in logs. DLQ is for task-level failures, not comment-posting failures.

**Why `$tries = 1` on PostFailureComment:**
Since it's best-effort and catches all exceptions internally, retries would be wasteful. One attempt is sufficient.

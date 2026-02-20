# T96: Dead Letter Queue — Backend Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Complete the DLQ backend so failed tasks have full attempt history, and admins can retry (re-queue) or dismiss DLQ entries.

**Architecture:** The `DeadLetterEntry` model, migration, and `FailureHandler` already exist from T37/T38. This task fills the gaps: adds a `task_id` FK + retry tracking columns to the DLQ table, creates a `DeadLetterService` for retry/dismiss operations, wires attempt history into the job failure handlers, and adds the M5 verification checks.

**Tech Stack:** Laravel 11 (Pest tests, migrations, Eloquent), PostgreSQL JSONB

---

### Task 1: Add migration for DLQ retry tracking columns

**Files:**
- Create: `database/migrations/2026_02_15_080000_add_retry_columns_to_dead_letter_queue_table.php`

**Step 1: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('dead_letter_queue', function (Blueprint $table) {
            // Direct FK to the original task for easy lookup
            $table->foreignId('task_id')->nullable()->after('id')->constrained('tasks')->nullOnDelete();

            // Retry tracking
            $table->boolean('retried')->default(false)->after('dismissed_by');
            $table->timestamp('retried_at')->nullable()->after('retried');
            $table->foreignId('retried_by')->nullable()->after('retried_at')->constrained('users')->nullOnDelete();
            $table->foreignId('retried_task_id')->nullable()->after('retried_by')->constrained('tasks')->nullOnDelete();

            // Index for admin filtering
            $table->index('task_id');
            $table->index('retried');
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('dead_letter_queue', function (Blueprint $table) {
            $table->dropForeign(['task_id']);
            $table->dropForeign(['retried_by']);
            $table->dropForeign(['retried_task_id']);
            $table->dropIndex(['task_id']);
            $table->dropIndex(['retried']);
            $table->dropColumn(['task_id', 'retried', 'retried_at', 'retried_by', 'retried_task_id']);
        });
    }
};
```

**Step 2: Run migration**

Run: `php artisan migrate`
Expected: Migration succeeds (or skips on SQLite test env)

**Step 3: Commit**

```bash
git add database/migrations/2026_02_15_080000_add_retry_columns_to_dead_letter_queue_table.php
git commit --no-gpg-sign -m "T96.1: Add retry tracking columns to dead_letter_queue table"
```

---

### Task 2: Update DeadLetterEntry model with new columns and relationships

**Files:**
- Modify: `app/Models/DeadLetterEntry.php`

**Step 1: Update model**

Add `task_id`, `retried`, `retried_at`, `retried_by`, `retried_task_id` to `$fillable`. Add `retried` cast to `boolean`, `retried_at` cast to `datetime`. Add `task()`, `retriedBy()`, `retriedTask()` relationships. Add `scopeActive` (not dismissed and not retried), `scopeForProject` (filter by task_record JSONB project_id).

```php
protected $fillable = [
    'task_id',
    'task_record',
    'failure_reason',
    'error_details',
    'attempts',
    'dismissed',
    'dismissed_at',
    'dismissed_by',
    'retried',
    'retried_at',
    'retried_by',
    'retried_task_id',
    'originally_queued_at',
    'dead_lettered_at',
];

protected function casts(): array
{
    return [
        'task_record' => 'array',
        'attempts' => 'array',
        'dismissed' => 'boolean',
        'retried' => 'boolean',
        'originally_queued_at' => 'datetime',
        'dead_lettered_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'retried_at' => 'datetime',
    ];
}

public function task(): BelongsTo
{
    return $this->belongsTo(Task::class);
}

public function retriedBy(): BelongsTo
{
    return $this->belongsTo(User::class, 'retried_by');
}

public function retriedTask(): BelongsTo
{
    return $this->belongsTo(Task::class, 'retried_task_id');
}

public function scopeActive(Builder $query): Builder
{
    return $query->where('dismissed', false)->where('retried', false);
}
```

**Step 2: Run existing tests to confirm no regression**

Run: `php artisan test --filter=DeadLetterEntry`
Expected: All existing model tests pass

**Step 3: Commit**

```bash
git add app/Models/DeadLetterEntry.php
git commit --no-gpg-sign -m "T96.2: Update DeadLetterEntry model with retry tracking and scopes"
```

---

### Task 3: Update FailureHandler to pass task_id to DLQ

**Files:**
- Modify: `app/Services/FailureHandler.php`

**Step 1: Add task_id to DLQ entry creation**

In `handlePermanentFailure()`, add `'task_id' => $task->id` to the `DeadLetterEntry::create()` call.

```php
DeadLetterEntry::create([
    'task_id' => $task->id,
    'task_record' => $task->toArray(),
    'failure_reason' => $failureReason,
    'error_details' => $errorDetails,
    'attempts' => $attempts,
    'originally_queued_at' => $task->created_at,
    'dead_lettered_at' => now(),
]);
```

**Step 2: Run FailureHandler tests**

Run: `php artisan test --filter=FailureHandler`
Expected: All pass (task_id is nullable in SQLite, fillable in model)

**Step 3: Commit**

```bash
git add app/Services/FailureHandler.php
git commit --no-gpg-sign -m "T96.3: Pass task_id to DLQ entry in FailureHandler"
```

---

### Task 4: Create DeadLetterService with retry and dismiss methods

**Files:**
- Create: `app/Services/DeadLetterService.php`
- Create: `tests/Feature/Services/DeadLetterServiceTest.php`

**Step 1: Write the failing tests**

```php
<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\ProcessTask;
use App\Models\DeadLetterEntry;
use App\Models\Task;
use App\Models\User;
use App\Services\DeadLetterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ─── Retry creates new task and marks DLQ entry ──────────────────

it('retries a DLQ entry by creating a new queued task', function () {
    Queue::fake();

    $originalTask = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Failed,
        'project_id' => 1,
        'mr_iid' => 42,
        'commit_sha' => 'abc123',
        'retry_count' => 3,
        'error_reason' => 'max_retries_exceeded',
        'started_at' => now()->subMinutes(10),
    ]);

    $entry = DeadLetterEntry::create([
        'task_id' => $originalTask->id,
        'task_record' => $originalTask->toArray(),
        'failure_reason' => 'max_retries_exceeded',
        'error_details' => 'HTTP 503',
        'attempts' => [],
        'originally_queued_at' => $originalTask->created_at,
        'dead_lettered_at' => now(),
    ]);

    $admin = User::factory()->create();

    $service = new DeadLetterService();
    $newTask = $service->retry($entry, $admin);

    // DLQ entry should be marked as retried
    $entry->refresh();
    expect($entry->retried)->toBeTrue();
    expect($entry->retried_at)->not->toBeNull();
    expect($entry->retried_by)->toBe($admin->id);
    expect($entry->retried_task_id)->toBe($newTask->id);

    // New task should be created and queued
    expect($newTask->type)->toBe(TaskType::CodeReview);
    expect($newTask->status)->toBe(TaskStatus::Queued);
    expect($newTask->project_id)->toBe($originalTask->project_id);
    expect($newTask->mr_iid)->toBe(42);
    expect($newTask->commit_sha)->toBe('abc123');
    expect($newTask->retry_count)->toBe(0);
    expect($newTask->error_reason)->toBeNull();

    // ProcessTask job should be dispatched
    Queue::assertPushed(ProcessTask::class, fn ($job) => $job->taskId === $newTask->id);
});

// ─── Retry fails for already retried entry ───────────────────────

it('throws when retrying an already retried DLQ entry', function () {
    $entry = DeadLetterEntry::factory()->create([
        'retried' => true,
        'retried_at' => now(),
    ]);

    $admin = User::factory()->create();
    $service = new DeadLetterService();

    $service->retry($entry, $admin);
})->throws(\LogicException::class, 'already been retried');

// ─── Retry fails for dismissed entry ─────────────────────────────

it('throws when retrying a dismissed DLQ entry', function () {
    $entry = DeadLetterEntry::factory()->create([
        'dismissed' => true,
        'dismissed_at' => now(),
    ]);

    $admin = User::factory()->create();
    $service = new DeadLetterService();

    $service->retry($entry, $admin);
})->throws(\LogicException::class, 'dismissed');

// ─── Dismiss marks entry as acknowledged ─────────────────────────

it('dismisses a DLQ entry', function () {
    $entry = DeadLetterEntry::factory()->create([
        'dismissed' => false,
    ]);

    $admin = User::factory()->create();
    $service = new DeadLetterService();
    $service->dismiss($entry, $admin);

    $entry->refresh();
    expect($entry->dismissed)->toBeTrue();
    expect($entry->dismissed_at)->not->toBeNull();
    expect($entry->dismissed_by)->toBe($admin->id);
});

// ─── Dismiss fails for already dismissed entry ───────────────────

it('throws when dismissing an already dismissed DLQ entry', function () {
    $entry = DeadLetterEntry::factory()->create([
        'dismissed' => true,
        'dismissed_at' => now(),
    ]);

    $admin = User::factory()->create();
    $service = new DeadLetterService();

    $service->dismiss($entry, $admin);
})->throws(\LogicException::class, 'already dismissed');

// ─── Dismiss fails for retried entry ─────────────────────────────

it('throws when dismissing a retried DLQ entry', function () {
    $entry = DeadLetterEntry::factory()->create([
        'retried' => true,
        'retried_at' => now(),
    ]);

    $admin = User::factory()->create();
    $service = new DeadLetterService();

    $service->dismiss($entry, $admin);
})->throws(\LogicException::class, 'retried');
```

**Step 2: Create DeadLetterEntry factory**

Check if `database/factories/DeadLetterEntryFactory.php` exists. If not, create it:

```php
<?php

namespace Database\Factories;

use App\Models\DeadLetterEntry;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeadLetterEntryFactory extends Factory
{
    protected $model = DeadLetterEntry::class;

    public function definition(): array
    {
        return [
            'task_record' => ['id' => 1, 'type' => 'code_review', 'status' => 'failed'],
            'failure_reason' => $this->faker->randomElement([
                'max_retries_exceeded', 'expired', 'invalid_request',
                'context_exceeded', 'scheduling_timeout',
            ]),
            'error_details' => 'Test error: ' . $this->faker->sentence(),
            'attempts' => [],
            'dismissed' => false,
            'retried' => false,
            'originally_queued_at' => now()->subHour(),
            'dead_lettered_at' => now(),
        ];
    }
}
```

Also add `use HasFactory;` to `DeadLetterEntry` model.

**Step 3: Run tests to verify they fail**

Run: `php artisan test --filter=DeadLetterServiceTest`
Expected: FAIL (DeadLetterService class not found)

**Step 4: Write DeadLetterService**

```php
<?php

namespace App\Services;

use App\Enums\TaskOrigin;
use App\Enums\TaskStatus;
use App\Jobs\ProcessTask;
use App\Models\DeadLetterEntry;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class DeadLetterService
{
    /**
     * Retry a DLQ entry by creating a new task and dispatching it.
     *
     * Creates a fresh task from the original task's data, transitions it
     * to Queued, and dispatches a ProcessTask job. The DLQ entry is marked
     * as retried with a reference to the new task.
     *
     * @throws \LogicException if the entry has already been retried or dismissed
     */
    public function retry(DeadLetterEntry $entry, User $admin): Task
    {
        if ($entry->retried) {
            throw new \LogicException('This DLQ entry has already been retried.');
        }

        if ($entry->dismissed) {
            throw new \LogicException('Cannot retry a dismissed DLQ entry.');
        }

        $taskData = $entry->task_record;

        // Create a new task from the original's data
        $newTask = Task::create([
            'type' => $taskData['type'],
            'origin' => $taskData['origin'] ?? TaskOrigin::Webhook->value,
            'user_id' => $taskData['user_id'] ?? null,
            'project_id' => $taskData['project_id'],
            'priority' => $taskData['priority'] ?? 'normal',
            'status' => TaskStatus::Received,
            'mr_iid' => $taskData['mr_iid'] ?? null,
            'issue_iid' => $taskData['issue_iid'] ?? null,
            'commit_sha' => $taskData['commit_sha'] ?? null,
            'conversation_id' => $taskData['conversation_id'] ?? null,
        ]);

        $newTask->transitionTo(TaskStatus::Queued);

        // Mark DLQ entry as retried
        $entry->update([
            'retried' => true,
            'retried_at' => now(),
            'retried_by' => $admin->id,
            'retried_task_id' => $newTask->id,
        ]);

        // Dispatch the job
        $job = new ProcessTask($newTask->id);
        $job->resolveQueue($newTask);
        dispatch($job);

        Log::info('DeadLetterService: retried DLQ entry', [
            'dlq_id' => $entry->id,
            'original_task_id' => $entry->task_id,
            'new_task_id' => $newTask->id,
            'admin_id' => $admin->id,
        ]);

        return $newTask;
    }

    /**
     * Dismiss (acknowledge) a DLQ entry.
     *
     * Removes it from the active DLQ view but retains in database
     * per D96 indefinite retention.
     *
     * @throws \LogicException if the entry is already dismissed or retried
     */
    public function dismiss(DeadLetterEntry $entry, User $admin): void
    {
        if ($entry->dismissed) {
            throw new \LogicException('This DLQ entry has already been dismissed.');
        }

        if ($entry->retried) {
            throw new \LogicException('Cannot dismiss a retried DLQ entry.');
        }

        $entry->update([
            'dismissed' => true,
            'dismissed_at' => now(),
            'dismissed_by' => $admin->id,
        ]);

        Log::info('DeadLetterService: dismissed DLQ entry', [
            'dlq_id' => $entry->id,
            'task_id' => $entry->task_id,
            'admin_id' => $admin->id,
        ]);
    }
}
```

**Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=DeadLetterServiceTest`
Expected: All 6 tests pass

**Step 6: Commit**

```bash
git add app/Services/DeadLetterService.php tests/Feature/Services/DeadLetterServiceTest.php database/factories/DeadLetterEntryFactory.php app/Models/DeadLetterEntry.php
git commit --no-gpg-sign -m "T96.4: Add DeadLetterService with retry and dismiss operations"
```

---

### Task 5: Wire attempt history into ProcessTask and ProcessTaskResult

**Files:**
- Modify: `app/Jobs/ProcessTask.php`
- Modify: `app/Jobs/ProcessTaskResult.php`
- Modify: `app/Jobs/Middleware/RetryWithBackoff.php`

**Step 1: Add attempt tracking property to jobs**

Both `ProcessTask` and `ProcessTaskResult` need a public `array $attemptHistory = []` property that gets populated by `RetryWithBackoff` middleware on each transient error.

In `RetryWithBackoff::handleGitLabException()`, before `$job->release($delay)`, push to the history:

```php
// Record this attempt in the job's history
if (property_exists($job, 'attemptHistory')) {
    $job->attemptHistory[] = [
        'attempt' => $attempts,
        'timestamp' => now()->toIso8601String(),
        'error' => "HTTP {$e->statusCode}: " . mb_substr($e->responseBody, 0, 500),
    ];
}
```

In both jobs' `failed()` methods, pass `$this->attemptHistory` instead of `[]`:

```php
app(FailureHandler::class)->handlePermanentFailure(
    task: $task,
    failureReason: $failureReason,
    errorDetails: $errorDetails,
    attempts: $this->attemptHistory,
);
```

**Step 2: Run existing failure tests**

Run: `php artisan test --filter=ProcessTaskFailure`
Expected: All pass (attemptHistory defaults to [])

Run: `php artisan test --filter=ProcessTaskResultFailure`
Expected: All pass

**Step 3: Commit**

```bash
git add app/Jobs/ProcessTask.php app/Jobs/ProcessTaskResult.php app/Jobs/Middleware/RetryWithBackoff.php
git commit --no-gpg-sign -m "T96.5: Wire attempt history into job failure handlers"
```

---

### Task 6: Add T96 structural checks to verify_m5.py

**Files:**
- Modify: `verify/verify_m5.py`

**Step 1: Add T96 section before Summary**

```python
# ============================================================
#  T96: Dead letter queue — backend
# ============================================================
section("T96: Dead Letter Queue — Backend")

# Migration
checker.check(
    "DLQ retry columns migration exists",
    file_exists("database/migrations/2026_02_15_080000_add_retry_columns_to_dead_letter_queue_table.php"),
)

# Model
checker.check(
    "DeadLetterEntry model exists",
    file_exists("app/Models/DeadLetterEntry.php"),
)
checker.check(
    "DeadLetterEntry has task_id fillable",
    file_contains("app/Models/DeadLetterEntry.php", "'task_id'"),
)
checker.check(
    "DeadLetterEntry has retried fillable",
    file_contains("app/Models/DeadLetterEntry.php", "'retried'"),
)
checker.check(
    "DeadLetterEntry has retried_task_id fillable",
    file_contains("app/Models/DeadLetterEntry.php", "'retried_task_id'"),
)
checker.check(
    "DeadLetterEntry has scopeActive",
    file_contains("app/Models/DeadLetterEntry.php", "scopeActive"),
)
checker.check(
    "DeadLetterEntry has task relationship",
    file_contains("app/Models/DeadLetterEntry.php", "function task()"),
)
checker.check(
    "DeadLetterEntry has retriedTask relationship",
    file_contains("app/Models/DeadLetterEntry.php", "function retriedTask()"),
)
checker.check(
    "DeadLetterEntry factory exists",
    file_exists("database/factories/DeadLetterEntryFactory.php"),
)

# Service
checker.check(
    "DeadLetterService exists",
    file_exists("app/Services/DeadLetterService.php"),
)
checker.check(
    "DeadLetterService has retry method",
    file_contains("app/Services/DeadLetterService.php", "function retry"),
)
checker.check(
    "DeadLetterService has dismiss method",
    file_contains("app/Services/DeadLetterService.php", "function dismiss"),
)
checker.check(
    "DeadLetterService dispatches ProcessTask on retry",
    file_contains("app/Services/DeadLetterService.php", "ProcessTask"),
)

# FailureHandler passes task_id
checker.check(
    "FailureHandler passes task_id to DLQ",
    file_contains("app/Services/FailureHandler.php", "'task_id'"),
)

# Attempt history wiring
checker.check(
    "ProcessTask has attemptHistory property",
    file_contains("app/Jobs/ProcessTask.php", "attemptHistory"),
)
checker.check(
    "ProcessTaskResult has attemptHistory property",
    file_contains("app/Jobs/ProcessTaskResult.php", "attemptHistory"),
)
checker.check(
    "RetryWithBackoff records attempt history",
    file_contains("app/Jobs/Middleware/RetryWithBackoff.php", "attemptHistory"),
)

# Tests
checker.check(
    "DeadLetterService test exists",
    file_exists("tests/Feature/Services/DeadLetterServiceTest.php"),
)
checker.check(
    "DeadLetterService test covers retry",
    file_contains("tests/Feature/Services/DeadLetterServiceTest.php", "retry"),
)
checker.check(
    "DeadLetterService test covers dismiss",
    file_contains("tests/Feature/Services/DeadLetterServiceTest.php", "dismiss"),
)
```

**Step 2: Run verify_m5.py**

Run: `python3 verify/verify_m5.py`
Expected: T96 checks all pass (after completing Tasks 1-5)

**Step 3: Commit**

```bash
git add verify/verify_m5.py
git commit --no-gpg-sign -m "T96.6: Add T96 structural checks to verify_m5.py"
```

---

### Task 7: Run full verification and mark complete

**Step 1: Run full test suite**

Run: `php artisan test --parallel`
Expected: All tests pass

**Step 2: Run M5 structural checks**

Run: `python3 verify/verify_m5.py`
Expected: All checks pass

**Step 3: Update progress.md**

- Check `[x]` for T96
- Bold T97 as next task
- Update M5 count to (9/18)
- Update tasks complete to 97/116 (83.6%)

**Step 4: Clear handoff.md**

Reset to empty template.

**Step 5: Commit**

```bash
git add progress.md handoff.md
git commit --no-gpg-sign -m "T96.7: Mark T96 complete, advance to T97"
```

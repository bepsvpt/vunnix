# T86: Acceptance Tracking Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement webhook-driven acceptance tracking that classifies AI review findings as accepted or dismissed based on GitLab discussion thread state at MR merge, with near-real-time updates on thread resolution and code change correlation from push events.

**Architecture:** Three webhook event paths feed into an `AcceptanceTrackingService`: (1) MR merge triggers final classification of all AI threads, (2) MR update detects individual thread resolutions for near-real-time tracking, (3) Push events correlate code changes with finding locations. Data is stored in a new `finding_acceptances` table linked to tasks. The WebhookController dispatches a `ProcessAcceptanceTracking` job for merge events, and inline handlers for update/push events.

**Tech Stack:** Laravel 11, PostgreSQL, Pest tests, GitLab REST API v4

---

### Task 1: Create `finding_acceptances` migration

**Files:**
- Create: `database/migrations/2026_02_15_040000_create_finding_acceptances_table.php`

**Step 1: Write the migration**

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
        Schema::create('finding_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('mr_iid');

            // Finding identification (from task result)
            $table->string('finding_id');     // e.g. "1", "2" — the finding's id field
            $table->string('file');
            $table->unsignedInteger('line');
            $table->string('severity');       // critical, major, minor
            $table->string('title');

            // GitLab thread state
            $table->string('gitlab_discussion_id')->nullable(); // GitLab discussion ID
            $table->string('status')->default('pending');       // pending, accepted, accepted_auto, dismissed
            $table->timestamp('resolved_at')->nullable();

            // Code change correlation
            $table->boolean('code_change_correlated')->default(false);
            $table->string('correlated_commit_sha', 40)->nullable();

            // Bulk resolution detection (over-reliance signal D113)
            $table->boolean('bulk_resolved')->default(false);

            $table->timestamps();

            // Indexes
            $table->index(['project_id', 'mr_iid']);
            $table->index(['task_id', 'finding_id']);
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finding_acceptances');
    }
};
```

**Step 2: Run migration (when services available)**

Run: `php artisan migrate`
Expected: Table created successfully.

**Step 3: Commit**

```bash
git add database/migrations/2026_02_15_040000_create_finding_acceptances_table.php
git commit --no-gpg-sign -m "T86.1: Add finding_acceptances migration"
```

---

### Task 2: Create `FindingAcceptance` model

**Files:**
- Create: `app/Models/FindingAcceptance.php`
- Modify: `app/Models/Task.php` — add `findingAcceptances()` relationship

**Step 1: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FindingAcceptance extends Model
{
    protected $fillable = [
        'task_id',
        'project_id',
        'mr_iid',
        'finding_id',
        'file',
        'line',
        'severity',
        'title',
        'gitlab_discussion_id',
        'status',
        'resolved_at',
        'code_change_correlated',
        'correlated_commit_sha',
        'bulk_resolved',
    ];

    protected function casts(): array
    {
        return [
            'line' => 'integer',
            'code_change_correlated' => 'boolean',
            'bulk_resolved' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
```

**Step 2: Add relationship to Task model**

In `app/Models/Task.php`, add after the existing `metric()` relationship:

```php
public function findingAcceptances(): HasMany
{
    return $this->hasMany(FindingAcceptance::class);
}
```

Add the `HasMany` import at the top of the file.

**Step 3: Commit**

```bash
git add app/Models/FindingAcceptance.php app/Models/Task.php
git commit --no-gpg-sign -m "T86.2: Add FindingAcceptance model with Task relationship"
```

---

### Task 3: Create `AcceptanceTrackingService`

**Files:**
- Create: `app/Services/AcceptanceTrackingService.php`
- Test: `tests/Unit/Services/AcceptanceTrackingServiceTest.php`

This is the core service. It handles three responsibilities:
1. **Final classification** (on MR merge): Fetch all AI discussion threads, classify as accepted/dismissed
2. **Thread resolution tracking** (on MR update): Detect thread resolution in webhook `changes` field
3. **Code change correlation** (on push): Match modified file:line ranges against existing findings

**Step 1: Write the failing test for the acceptance classifier**

```php
<?php

use App\Services\AcceptanceTrackingService;

// ─── classifyThreadState ─────────────────────────────────────────

it('classifies resolved thread as accepted', function () {
    $service = new AcceptanceTrackingService();

    $discussion = [
        'id' => 'disc-1',
        'notes' => [[
            'body' => "🔴 **Critical** | Security\n\n**SQL injection risk**",
            'resolved' => true,
            'position' => ['new_path' => 'src/auth.py', 'new_line' => 42],
        ]],
    ];

    expect($service->classifyThreadState($discussion))->toBe('accepted');
});

it('classifies unresolved thread as dismissed', function () {
    $service = new AcceptanceTrackingService();

    $discussion = [
        'id' => 'disc-2',
        'notes' => [[
            'body' => "🟡 **Major** | Bug\n\n**Null pointer**",
            'resolved' => false,
            'position' => ['new_path' => 'src/utils.py', 'new_line' => 18],
        ]],
    ];

    expect($service->classifyThreadState($discussion))->toBe('dismissed');
});

// ─── detectBulkResolution ────────────────────────────────────────

it('detects bulk resolution when all threads resolved within 60 seconds', function () {
    $service = new AcceptanceTrackingService();

    $discussions = [
        ['id' => 'disc-1', 'notes' => [['resolved' => true, 'updated_at' => '2026-02-15T10:00:00Z']]],
        ['id' => 'disc-2', 'notes' => [['resolved' => true, 'updated_at' => '2026-02-15T10:00:30Z']]],
        ['id' => 'disc-3', 'notes' => [['resolved' => true, 'updated_at' => '2026-02-15T10:00:45Z']]],
    ];

    expect($service->detectBulkResolution($discussions))->toBeTrue();
});

it('does not flag bulk resolution when threads resolved over time', function () {
    $service = new AcceptanceTrackingService();

    $discussions = [
        ['id' => 'disc-1', 'notes' => [['resolved' => true, 'updated_at' => '2026-02-15T10:00:00Z']]],
        ['id' => 'disc-2', 'notes' => [['resolved' => true, 'updated_at' => '2026-02-15T10:30:00Z']]],
        ['id' => 'disc-3', 'notes' => [['resolved' => true, 'updated_at' => '2026-02-15T11:00:00Z']]],
    ];

    expect($service->detectBulkResolution($discussions))->toBeFalse();
});

it('does not flag bulk resolution with fewer than 3 threads', function () {
    $service = new AcceptanceTrackingService();

    $discussions = [
        ['id' => 'disc-1', 'notes' => [['resolved' => true, 'updated_at' => '2026-02-15T10:00:00Z']]],
        ['id' => 'disc-2', 'notes' => [['resolved' => true, 'updated_at' => '2026-02-15T10:00:05Z']]],
    ];

    expect($service->detectBulkResolution($discussions))->toBeFalse();
});

// ─── correlateCodeChange ─────────────────────────────────────────

it('detects code change correlation when push modifies finding region', function () {
    $service = new AcceptanceTrackingService();

    $finding = ['file' => 'src/auth.py', 'line' => 42, 'end_line' => 45];

    // GitLab compare API returns diffs with modified line ranges
    $diffs = [
        [
            'new_path' => 'src/auth.py',
            'diff' => "@@ -40,8 +40,10 @@ class Auth\n some context\n-bad line\n+good line\n more context",
        ],
    ];

    expect($service->correlateCodeChange($finding, $diffs))->toBeTrue();
});

it('does not correlate when push does not touch finding file', function () {
    $service = new AcceptanceTrackingService();

    $finding = ['file' => 'src/auth.py', 'line' => 42, 'end_line' => 45];

    $diffs = [
        [
            'new_path' => 'src/other.py',
            'diff' => "@@ -1,3 +1,5 @@ something\n some context\n-old\n+new\n more",
        ],
    ];

    expect($service->correlateCodeChange($finding, $diffs))->toBeFalse();
});

it('does not correlate when push modifies different region of same file', function () {
    $service = new AcceptanceTrackingService();

    $finding = ['file' => 'src/auth.py', 'line' => 42, 'end_line' => 45];

    $diffs = [
        [
            'new_path' => 'src/auth.py',
            'diff' => "@@ -100,3 +100,5 @@ class Auth\n far away context\n-old line\n+new line\n more",
        ],
    ];

    expect($service->correlateCodeChange($finding, $diffs))->toBeFalse();
});

// ─── isAiCreatedDiscussion ───────────────────────────────────────

it('identifies AI-created discussions by severity tag markers', function () {
    $service = new AcceptanceTrackingService();

    $aiDiscussion = [
        'notes' => [[
            'body' => "🔴 **Critical** | Security\n\n**SQL injection risk**",
        ]],
    ];

    $humanDiscussion = [
        'notes' => [[
            'body' => 'This looks wrong, can you fix it?',
        ]],
    ];

    expect($service->isAiCreatedDiscussion($aiDiscussion))->toBeTrue();
    expect($service->isAiCreatedDiscussion($humanDiscussion))->toBeFalse();
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AcceptanceTrackingServiceTest`
Expected: FAIL — class not found

**Step 3: Implement `AcceptanceTrackingService`**

```php
<?php

namespace App\Services;

use Carbon\Carbon;

class AcceptanceTrackingService
{
    /**
     * Minimum number of resolved threads to consider "bulk resolution."
     */
    private const BULK_RESOLUTION_MIN_THREADS = 3;

    /**
     * Maximum time span (seconds) between first and last resolution
     * to be flagged as bulk resolution (over-reliance signal D113).
     */
    private const BULK_RESOLUTION_WINDOW_SECONDS = 60;

    /**
     * Number of lines of padding around a finding's line range when
     * checking for code change correlation.
     */
    private const CORRELATION_LINE_PADDING = 5;

    /**
     * Classify a GitLab discussion thread as accepted or dismissed.
     *
     * Per §16.2:
     * - Resolved (by engineer) → accepted
     * - Resolved (auto — finding no longer present) → accepted_auto
     * - Unresolved at merge → dismissed
     */
    public function classifyThreadState(array $discussion): string
    {
        $notes = $discussion['notes'] ?? [];
        if (empty($notes)) {
            return 'dismissed';
        }

        // GitLab marks the first note's `resolved` field for the whole thread
        $firstNote = $notes[0];
        $resolved = $firstNote['resolved'] ?? false;

        return $resolved ? 'accepted' : 'dismissed';
    }

    /**
     * Detect bulk resolution pattern from timestamps.
     *
     * Per D113/§16.5: If 3+ threads are all resolved within 60 seconds,
     * flag as potential over-reliance (rubber-stamping).
     *
     * @param  array<int, array>  $discussions  Resolved AI discussions
     */
    public function detectBulkResolution(array $discussions): bool
    {
        $resolvedDiscussions = array_filter(
            $discussions,
            fn (array $d) => ($d['notes'][0]['resolved'] ?? false) === true,
        );

        if (count($resolvedDiscussions) < self::BULK_RESOLUTION_MIN_THREADS) {
            return false;
        }

        $timestamps = [];
        foreach ($resolvedDiscussions as $discussion) {
            $updatedAt = $discussion['notes'][0]['updated_at'] ?? null;
            if ($updatedAt !== null) {
                $timestamps[] = Carbon::parse($updatedAt);
            }
        }

        if (count($timestamps) < self::BULK_RESOLUTION_MIN_THREADS) {
            return false;
        }

        sort($timestamps);

        $firstResolved = $timestamps[0];
        $lastResolved = end($timestamps);

        return $firstResolved->diffInSeconds($lastResolved) <= self::BULK_RESOLUTION_WINDOW_SECONDS;
    }

    /**
     * Check if a code change (from push event diffs) correlates with a finding.
     *
     * Per §16.2: If a finding targets file:line and the next push modifies
     * that region → strong acceptance signal.
     *
     * @param  array{file: string, line: int, end_line: int}  $finding
     * @param  array<int, array{new_path: string, diff: string}>  $diffs
     */
    public function correlateCodeChange(array $finding, array $diffs): bool
    {
        $targetFile = $finding['file'];
        $targetStart = $finding['line'] - self::CORRELATION_LINE_PADDING;
        $targetEnd = ($finding['end_line'] ?? $finding['line']) + self::CORRELATION_LINE_PADDING;

        foreach ($diffs as $diff) {
            if (($diff['new_path'] ?? '') !== $targetFile) {
                continue;
            }

            $modifiedRanges = $this->parseHunkRanges($diff['diff'] ?? '');

            foreach ($modifiedRanges as [$hunkStart, $hunkEnd]) {
                if ($hunkStart <= $targetEnd && $hunkEnd >= $targetStart) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine if a GitLab discussion was created by the AI (Vunnix bot).
     *
     * AI-created discussions use the InlineThreadFormatter format with
     * severity tag markers (emoji + bold severity level).
     */
    public function isAiCreatedDiscussion(array $discussion): bool
    {
        $notes = $discussion['notes'] ?? [];
        if (empty($notes)) {
            return false;
        }

        $body = $notes[0]['body'] ?? '';

        // AI threads always start with a severity tag: 🔴 **Critical**, 🟡 **Major**, or 🟢 **Minor**
        return (bool) preg_match('/^[🔴🟡🟢]\s\*\*(?:Critical|Major|Minor)\*\*/', $body);
    }

    /**
     * Match a finding to its GitLab discussion by file path + title.
     *
     * Same matching logic as PostInlineThreads::hasExistingThread().
     *
     * @param  array  $finding  Finding from task result
     * @param  array<int, array>  $discussions  GitLab discussions
     * @return string|null  The discussion ID if found, null otherwise
     */
    public function matchFindingToDiscussion(array $finding, array $discussions): ?string
    {
        foreach ($discussions as $discussion) {
            $notes = $discussion['notes'] ?? [];
            if (empty($notes)) {
                continue;
            }

            $firstNote = $notes[0];
            $body = $firstNote['body'] ?? '';
            $position = $firstNote['position'] ?? [];

            $sameFile = ($position['new_path'] ?? '') === $finding['file'];
            $sameTitle = str_contains($body, $finding['title']);

            if ($sameFile && $sameTitle) {
                return $discussion['id'] ?? null;
            }
        }

        return null;
    }

    /**
     * Parse unified diff hunk headers to extract modified line ranges.
     *
     * @return array<int, array{0: int, 1: int}>  [[startLine, endLine], ...]
     */
    private function parseHunkRanges(string $diff): array
    {
        $ranges = [];

        // Match @@ -old,count +new,count @@ patterns
        preg_match_all('/@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $diff, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $start = (int) $match[1];
            $count = isset($match[2]) ? (int) $match[2] : 1;
            $end = $start + max($count - 1, 0);
            $ranges[] = [$start, $end];
        }

        return $ranges;
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=AcceptanceTrackingServiceTest`
Expected: All pass

**Step 5: Commit**

```bash
git add app/Services/AcceptanceTrackingService.php tests/Unit/Services/AcceptanceTrackingServiceTest.php
git commit --no-gpg-sign -m "T86.3: Add AcceptanceTrackingService with classifier, bulk detection, correlation"
```

---

### Task 4: Add GitLabClient methods for acceptance tracking

**Files:**
- Modify: `app/Services/GitLabClient.php` — add `compareBranches()` method
- Test: `tests/Feature/Services/GitLabClientTest.php` — add test

The `listMergeRequestDiscussions()` method already exists. We need `compareBranches()` to get diffs for code change correlation from push events.

**Step 1: Write the failing test**

Add to `tests/Feature/Services/GitLabClientTest.php`:

```php
it('compares two commits and returns diffs', function () {
    Http::fake([
        '*/api/v4/projects/1/repository/compare*' => Http::response([
            'diffs' => [
                ['new_path' => 'src/auth.py', 'diff' => '@@ -40,3 +40,5 @@...'],
            ],
        ], 200),
    ]);

    $client = app(GitLabClient::class);
    $result = $client->compareBranches(1, 'abc123', 'def456');

    expect($result)->toHaveKey('diffs');
    expect($result['diffs'])->toHaveCount(1);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/repository/compare')
            && $request['from'] === 'abc123'
            && $request['to'] === 'def456';
    });
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter="compares two commits"`
Expected: FAIL — method not found

**Step 3: Implement `compareBranches()` in GitLabClient**

Add to `app/Services/GitLabClient.php` after the existing `getMergeRequestChanges()` method:

```php
/**
 * Compare two commits/branches to get diffs.
 *
 * Used by acceptance tracking (T86) to correlate push event changes
 * with AI finding locations for code change correlation (§16.2).
 *
 * @return array{diffs: array<int, array{new_path: string, diff: string, ...}>, ...}
 */
public function compareBranches(int $projectId, string $from, string $to): array
{
    $response = $this->request()->get(
        $this->url("projects/{$projectId}/repository/compare"),
        ['from' => $from, 'to' => $to],
    );

    return $this->handleResponse($response, "compareBranches {$from}..{$to}")->json();
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter="compares two commits"`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Services/GitLabClient.php tests/Feature/Services/GitLabClientTest.php
git commit --no-gpg-sign -m "T86.4: Add GitLabClient::compareBranches() for code change correlation"
```

---

### Task 5: Create `ProcessAcceptanceTracking` job (MR merge handler)

**Files:**
- Create: `app/Jobs/ProcessAcceptanceTracking.php`
- Test: `tests/Feature/Jobs/ProcessAcceptanceTrackingTest.php`

This job handles the MR `merge` event — the primary acceptance tracking trigger. It:
1. Finds all completed code review tasks for the merged MR
2. Fetches all discussions from GitLab
3. Matches AI findings to discussions
4. Classifies each as accepted/dismissed
5. Detects bulk resolution
6. Stores `FindingAcceptance` records

**Step 1: Write the failing tests**

```php
<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\ProcessAcceptanceTracking;
use App\Models\FindingAcceptance;
use App\Models\Task;
use App\Services\GitLabClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function createReviewTask(int $mrIid = 42): Task
{
    return Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'started_at' => now()->subMinutes(10),
        'completed_at' => now()->subMinutes(5),
        'mr_iid' => $mrIid,
        'result' => [
            'version' => '1.0',
            'summary' => [
                'risk_level' => 'high',
                'total_findings' => 2,
                'findings_by_severity' => ['critical' => 1, 'major' => 1, 'minor' => 0],
                'walkthrough' => [],
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
                    'suggestion' => 'Use parameterized queries.',
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
                    'suggestion' => 'Add null check.',
                    'labels' => [],
                ],
            ],
            'labels' => ['ai::reviewed'],
            'commit_status' => 'failed',
        ],
    ]);
}

function fakeGitLabDiscussions(): array
{
    return [
        // AI thread — resolved (accepted)
        [
            'id' => 'disc-ai-1',
            'notes' => [[
                'body' => "🔴 **Critical** | Security\n\n**SQL injection risk**\n\nUser input in SQL query.",
                'resolved' => true,
                'updated_at' => '2026-02-15T10:00:00Z',
                'position' => ['new_path' => 'src/auth.py', 'new_line' => 42],
            ]],
        ],
        // AI thread — unresolved (dismissed)
        [
            'id' => 'disc-ai-2',
            'notes' => [[
                'body' => "🟡 **Major** | Bug\n\n**Null pointer dereference**\n\nUser may be null.",
                'resolved' => false,
                'updated_at' => '2026-02-15T10:05:00Z',
                'position' => ['new_path' => 'src/utils.py', 'new_line' => 18],
            ]],
        ],
        // Human thread — should be ignored
        [
            'id' => 'disc-human-1',
            'notes' => [[
                'body' => 'Nice work on the refactoring!',
                'resolved' => true,
                'updated_at' => '2026-02-15T09:00:00Z',
            ]],
        ],
    ];
}

// ─── MR merge final classification ───────────────────────────────

it('classifies AI findings as accepted or dismissed on MR merge', function () {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42/discussions*' => Http::response(fakeGitLabDiscussions(), 200),
    ]);

    $task = createReviewTask();

    $job = new ProcessAcceptanceTracking(
        projectId: $task->project_id,
        gitlabProjectId: $task->project->gitlab_project_id,
        mrIid: 42,
    );
    $job->handle(app(GitLabClient::class));

    // Should create 2 FindingAcceptance records (one per AI finding, not for human thread)
    expect(FindingAcceptance::count())->toBe(2);

    $accepted = FindingAcceptance::where('status', 'accepted')->first();
    expect($accepted->finding_id)->toBe('1');
    expect($accepted->file)->toBe('src/auth.py');
    expect($accepted->gitlab_discussion_id)->toBe('disc-ai-1');

    $dismissed = FindingAcceptance::where('status', 'dismissed')->first();
    expect($dismissed->finding_id)->toBe('2');
    expect($dismissed->file)->toBe('src/utils.py');
    expect($dismissed->gitlab_discussion_id)->toBe('disc-ai-2');
});

it('skips if no completed review tasks exist for the MR', function () {
    Http::fake();

    $job = new ProcessAcceptanceTracking(
        projectId: 1,
        gitlabProjectId: 100,
        mrIid: 999,
    );
    $job->handle(app(GitLabClient::class));

    expect(FindingAcceptance::count())->toBe(0);
    Http::assertNothingSent();
});

it('detects bulk resolution and flags acceptance records', function () {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42/discussions*' => Http::response([
            // 3 AI threads all resolved within 30 seconds
            [
                'id' => 'disc-1',
                'notes' => [[
                    'body' => "🔴 **Critical** | Security\n\n**SQL injection risk**",
                    'resolved' => true,
                    'updated_at' => '2026-02-15T10:00:00Z',
                    'position' => ['new_path' => 'src/auth.py', 'new_line' => 42],
                ]],
            ],
            [
                'id' => 'disc-2',
                'notes' => [[
                    'body' => "🟡 **Major** | Bug\n\n**Null pointer dereference**",
                    'resolved' => true,
                    'updated_at' => '2026-02-15T10:00:15Z',
                    'position' => ['new_path' => 'src/utils.py', 'new_line' => 18],
                ]],
            ],
            [
                'id' => 'disc-3',
                'notes' => [[
                    'body' => "🟡 **Major** | Performance\n\n**N+1 query detected**",
                    'resolved' => true,
                    'updated_at' => '2026-02-15T10:00:25Z',
                    'position' => ['new_path' => 'src/db.py', 'new_line' => 50],
                ]],
            ],
        ], 200),
    ]);

    // Need a task with 3 findings
    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'started_at' => now()->subMinutes(10),
        'completed_at' => now()->subMinutes(5),
        'mr_iid' => 42,
        'result' => [
            'version' => '1.0',
            'summary' => [
                'risk_level' => 'high',
                'total_findings' => 3,
                'findings_by_severity' => ['critical' => 1, 'major' => 2, 'minor' => 0],
                'walkthrough' => [],
            ],
            'findings' => [
                ['id' => 1, 'severity' => 'critical', 'category' => 'security', 'file' => 'src/auth.py', 'line' => 42, 'end_line' => 45, 'title' => 'SQL injection risk', 'description' => 'Desc', 'suggestion' => 'Fix', 'labels' => []],
                ['id' => 2, 'severity' => 'major', 'category' => 'bug', 'file' => 'src/utils.py', 'line' => 18, 'end_line' => 22, 'title' => 'Null pointer dereference', 'description' => 'Desc', 'suggestion' => 'Fix', 'labels' => []],
                ['id' => 3, 'severity' => 'major', 'category' => 'performance', 'file' => 'src/db.py', 'line' => 50, 'end_line' => 55, 'title' => 'N+1 query detected', 'description' => 'Desc', 'suggestion' => 'Fix', 'labels' => []],
            ],
            'labels' => ['ai::reviewed'],
            'commit_status' => 'failed',
        ],
    ]);

    $job = new ProcessAcceptanceTracking(
        projectId: $task->project_id,
        gitlabProjectId: $task->project->gitlab_project_id,
        mrIid: 42,
    );
    $job->handle(app(GitLabClient::class));

    expect(FindingAcceptance::count())->toBe(3);
    expect(FindingAcceptance::where('bulk_resolved', true)->count())->toBe(3);
});

// ─── Integration: acceptance rate calculation ────────────────────

it('produces correct acceptance rate from stored records', function () {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42/discussions*' => Http::response(fakeGitLabDiscussions(), 200),
    ]);

    $task = createReviewTask();

    $job = new ProcessAcceptanceTracking(
        projectId: $task->project_id,
        gitlabProjectId: $task->project->gitlab_project_id,
        mrIid: 42,
    );
    $job->handle(app(GitLabClient::class));

    $total = FindingAcceptance::where('project_id', $task->project_id)
        ->where('mr_iid', 42)
        ->count();
    $accepted = FindingAcceptance::where('project_id', $task->project_id)
        ->where('mr_iid', 42)
        ->whereIn('status', ['accepted', 'accepted_auto'])
        ->count();

    $rate = $total > 0 ? round(($accepted / $total) * 100, 1) : 0;

    // 1 accepted, 1 dismissed → 50%
    expect($rate)->toBe(50.0);
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ProcessAcceptanceTrackingTest`
Expected: FAIL — class not found

**Step 3: Implement `ProcessAcceptanceTracking` job**

```php
<?php

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\FindingAcceptance;
use App\Models\Task;
use App\Services\AcceptanceTrackingService;
use App\Services\GitLabClient;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Process acceptance tracking on MR merge.
 *
 * Final classification of all AI discussion threads: resolved → accepted,
 * unresolved → dismissed. Detects bulk resolution for over-reliance signal.
 *
 * @see §16.2 Acceptance Tracking
 */
class ProcessAcceptanceTracking implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $projectId,
        public readonly int $gitlabProjectId,
        public readonly int $mrIid,
    ) {
        $this->queue = QueueNames::SERVER;
    }

    public function handle(GitLabClient $gitLab): void
    {
        $service = new AcceptanceTrackingService();

        // Find all completed code review tasks for this MR
        $tasks = Task::where('project_id', $this->projectId)
            ->where('mr_iid', $this->mrIid)
            ->where('type', TaskType::CodeReview)
            ->where('status', TaskStatus::Completed)
            ->get();

        if ($tasks->isEmpty()) {
            Log::info('ProcessAcceptanceTracking: no completed review tasks for MR', [
                'project_id' => $this->projectId,
                'mr_iid' => $this->mrIid,
            ]);

            return;
        }

        // Fetch all discussions from GitLab
        try {
            $discussions = $gitLab->listMergeRequestDiscussions(
                $this->gitlabProjectId,
                $this->mrIid,
            );
        } catch (\Throwable $e) {
            Log::warning('ProcessAcceptanceTracking: failed to fetch discussions', [
                'project_id' => $this->projectId,
                'mr_iid' => $this->mrIid,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // Filter to AI-created discussions only
        $aiDiscussions = array_filter(
            $discussions,
            fn (array $d) => $service->isAiCreatedDiscussion($d),
        );

        // Detect bulk resolution across all AI threads
        $isBulkResolved = $service->detectBulkResolution($aiDiscussions);

        // Process each task's findings
        foreach ($tasks as $task) {
            $findings = $task->result['findings'] ?? [];

            foreach ($findings as $finding) {
                // Only track findings that had inline threads (critical/major)
                if (! in_array($finding['severity'], ['critical', 'major'], true)) {
                    continue;
                }

                $discussionId = $service->matchFindingToDiscussion($finding, $aiDiscussions);

                // Classify the thread state
                $status = 'dismissed'; // default if no matching discussion found
                if ($discussionId !== null) {
                    $matchedDiscussion = collect($aiDiscussions)
                        ->first(fn (array $d) => ($d['id'] ?? null) === $discussionId);

                    if ($matchedDiscussion !== null) {
                        $status = $service->classifyThreadState($matchedDiscussion);
                    }
                }

                FindingAcceptance::updateOrCreate(
                    [
                        'task_id' => $task->id,
                        'finding_id' => (string) $finding['id'],
                    ],
                    [
                        'project_id' => $this->projectId,
                        'mr_iid' => $this->mrIid,
                        'file' => $finding['file'],
                        'line' => $finding['line'],
                        'severity' => $finding['severity'],
                        'title' => $finding['title'],
                        'gitlab_discussion_id' => $discussionId,
                        'status' => $status,
                        'bulk_resolved' => $isBulkResolved,
                    ],
                );
            }
        }

        $totalRecords = FindingAcceptance::where('project_id', $this->projectId)
            ->where('mr_iid', $this->mrIid)
            ->count();

        Log::info('ProcessAcceptanceTracking: completed', [
            'project_id' => $this->projectId,
            'mr_iid' => $this->mrIid,
            'findings_tracked' => $totalRecords,
            'bulk_resolved' => $isBulkResolved,
        ]);
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=ProcessAcceptanceTrackingTest`
Expected: All pass

**Step 5: Commit**

```bash
git add app/Jobs/ProcessAcceptanceTracking.php tests/Feature/Jobs/ProcessAcceptanceTrackingTest.php
git commit --no-gpg-sign -m "T86.5: Add ProcessAcceptanceTracking job for MR merge classification"
```

---

### Task 6: Wire MR merge event to dispatch `ProcessAcceptanceTracking`

**Files:**
- Modify: `app/Http/Controllers/WebhookController.php` — dispatch job for `acceptance_tracking` intent
- Test: `tests/Feature/WebhookAcceptanceTrackingTest.php`

Currently, the WebhookController sends `acceptance_tracking` intent to `TaskDispatchService`, which returns `null` (non-dispatchable). We need to intercept this intent and dispatch `ProcessAcceptanceTracking` instead.

**Step 1: Write the failing test**

```php
<?php

use App\Jobs\ProcessAcceptanceTracking;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('dispatches ProcessAcceptanceTracking job on MR merge webhook', function () {
    Queue::fake([ProcessAcceptanceTracking::class]);

    $project = Project::factory()->create([
        'gitlab_project_id' => 100,
        'webhook_secret' => 'test-secret',
    ]);

    $payload = [
        'object_kind' => 'merge_request',
        'object_attributes' => [
            'iid' => 42,
            'action' => 'merge',
            'source_branch' => 'feature/test',
            'target_branch' => 'main',
            'author_id' => 1,
            'last_commit' => ['id' => 'abc123'],
        ],
    ];

    $response = $this->postJson('/api/webhook', $payload, [
        'X-Gitlab-Event' => 'Merge Request Hook',
        'X-Gitlab-Token' => 'test-secret',
        'X-Gitlab-Event-UUID' => 'uuid-merge-1',
    ]);

    $response->assertOk();
    $response->assertJson(['intent' => 'acceptance_tracking']);

    Queue::assertPushed(ProcessAcceptanceTracking::class, function ($job) use ($project) {
        return $job->projectId === $project->id
            && $job->gitlabProjectId === 100
            && $job->mrIid === 42;
    });
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter="dispatches ProcessAcceptanceTracking"`
Expected: FAIL — job not dispatched

**Step 3: Modify WebhookController to dispatch acceptance tracking**

In `app/Http/Controllers/WebhookController.php`, add to the imports:

```php
use App\Events\Webhook\MergeRequestMerged;
use App\Jobs\ProcessAcceptanceTracking;
```

Then modify the `__invoke` method. After the deduplication check and before the TaskDispatchService dispatch, add acceptance tracking handling:

```php
// T86: Dispatch acceptance tracking for MR merge events
if ($routingResult->intent === 'acceptance_tracking') {
    $this->dispatchAcceptanceTracking($routingResult, $project);

    return response()->json([
        'status' => 'accepted',
        'event_type' => $eventType,
        'project_id' => $project->id,
        'intent' => $routingResult->intent,
        'superseded_count' => $dedupResult->supersededCount,
    ]);
}
```

Add the private method:

```php
/**
 * Dispatch acceptance tracking job for MR merge events (T86, D149).
 */
private function dispatchAcceptanceTracking(RoutingResult $routingResult, Project $project): void
{
    $event = $routingResult->event;

    if (! $event instanceof MergeRequestMerged) {
        return;
    }

    ProcessAcceptanceTracking::dispatch(
        $project->id,
        $project->gitlab_project_id,
        $event->mergeRequestIid,
    );

    Log::info('WebhookController: dispatched acceptance tracking', [
        'project_id' => $project->id,
        'mr_iid' => $event->mergeRequestIid,
    ]);
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter="dispatches ProcessAcceptanceTracking"`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Http/Controllers/WebhookController.php tests/Feature/WebhookAcceptanceTrackingTest.php
git commit --no-gpg-sign -m "T86.6: Wire MR merge webhook to dispatch acceptance tracking job"
```

---

### Task 7: Handle MR update events for near-real-time thread resolution tracking

**Files:**
- Create: `app/Jobs/ProcessThreadResolution.php`
- Modify: `app/Http/Controllers/WebhookController.php` — detect thread resolution in MR update
- Modify: `app/Services/EventRouter.php` — route MR updates with thread changes differently
- Test: `tests/Feature/Jobs/ProcessThreadResolutionTest.php`

Per D149: MR `update` events include a `changes` field. When a discussion thread is resolved, GitLab fires an MR update webhook. We detect this and update the corresponding `FindingAcceptance` record.

**Step 1: Write the failing test**

```php
<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\ProcessThreadResolution;
use App\Models\FindingAcceptance;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('updates finding acceptance to accepted when thread is resolved', function () {
    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'mr_iid' => 42,
        'result' => [
            'findings' => [
                ['id' => 1, 'severity' => 'critical', 'file' => 'src/auth.py', 'line' => 42, 'end_line' => 45, 'title' => 'SQL injection risk', 'category' => 'security', 'description' => '', 'suggestion' => '', 'labels' => []],
            ],
        ],
    ]);

    // Pre-create a pending acceptance record (from a previous merge event, or for near-real-time)
    FindingAcceptance::create([
        'task_id' => $task->id,
        'project_id' => $task->project_id,
        'mr_iid' => 42,
        'finding_id' => '1',
        'file' => 'src/auth.py',
        'line' => 42,
        'severity' => 'critical',
        'title' => 'SQL injection risk',
        'gitlab_discussion_id' => 'disc-ai-1',
        'status' => 'pending',
    ]);

    $job = new ProcessThreadResolution(
        projectId: $task->project_id,
        mrIid: 42,
        discussionId: 'disc-ai-1',
        resolved: true,
    );
    $job->handle();

    $acceptance = FindingAcceptance::first();
    expect($acceptance->status)->toBe('accepted');
    expect($acceptance->resolved_at)->not->toBeNull();
});

it('does nothing when no matching acceptance record exists', function () {
    $job = new ProcessThreadResolution(
        projectId: 1,
        mrIid: 999,
        discussionId: 'disc-unknown',
        resolved: true,
    );
    $job->handle();

    expect(FindingAcceptance::count())->toBe(0);
});
```

**Step 2: Implement `ProcessThreadResolution` job**

```php
<?php

namespace App\Jobs;

use App\Models\FindingAcceptance;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Update acceptance tracking when a discussion thread is resolved/unresolved.
 *
 * Triggered by MR update webhooks that contain thread resolution changes (D149).
 * Provides near-real-time tracking before MR merge.
 */
class ProcessThreadResolution implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $projectId,
        public readonly int $mrIid,
        public readonly string $discussionId,
        public readonly bool $resolved,
    ) {
        $this->queue = QueueNames::SERVER;
    }

    public function handle(): void
    {
        $acceptance = FindingAcceptance::where('project_id', $this->projectId)
            ->where('mr_iid', $this->mrIid)
            ->where('gitlab_discussion_id', $this->discussionId)
            ->first();

        if ($acceptance === null) {
            Log::debug('ProcessThreadResolution: no matching acceptance record', [
                'project_id' => $this->projectId,
                'mr_iid' => $this->mrIid,
                'discussion_id' => $this->discussionId,
            ]);

            return;
        }

        $acceptance->update([
            'status' => $this->resolved ? 'accepted' : 'pending',
            'resolved_at' => $this->resolved ? now() : null,
        ]);

        Log::info('ProcessThreadResolution: updated acceptance', [
            'finding_acceptance_id' => $acceptance->id,
            'status' => $acceptance->status,
            'discussion_id' => $this->discussionId,
        ]);
    }
}
```

**Step 3: Run tests to verify they pass**

Run: `php artisan test --filter=ProcessThreadResolutionTest`
Expected: All pass

**Step 4: Commit**

```bash
git add app/Jobs/ProcessThreadResolution.php tests/Feature/Jobs/ProcessThreadResolutionTest.php
git commit --no-gpg-sign -m "T86.7: Add ProcessThreadResolution for near-real-time thread tracking"
```

---

### Task 8: Handle push events for code change correlation

**Files:**
- Create: `app/Jobs/ProcessCodeChangeCorrelation.php`
- Test: `tests/Feature/Jobs/ProcessCodeChangeCorrelationTest.php`

Push events trigger correlation of code changes with existing AI findings. If the push modifies the file:line region where a finding exists, it's a strong acceptance signal.

**Step 1: Write the failing test**

```php
<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\ProcessCodeChangeCorrelation;
use App\Models\FindingAcceptance;
use App\Models\Task;
use App\Services\GitLabClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('marks code_change_correlated when push modifies finding region', function () {
    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'mr_iid' => 42,
        'result' => [
            'findings' => [
                ['id' => 1, 'severity' => 'critical', 'file' => 'src/auth.py', 'line' => 42, 'end_line' => 45, 'title' => 'SQL injection', 'category' => 'security', 'description' => '', 'suggestion' => '', 'labels' => []],
            ],
        ],
    ]);

    FindingAcceptance::create([
        'task_id' => $task->id,
        'project_id' => $task->project_id,
        'mr_iid' => 42,
        'finding_id' => '1',
        'file' => 'src/auth.py',
        'line' => 42,
        'severity' => 'critical',
        'title' => 'SQL injection',
        'status' => 'pending',
    ]);

    Http::fake([
        '*/api/v4/projects/*/repository/compare*' => Http::response([
            'diffs' => [
                [
                    'new_path' => 'src/auth.py',
                    'diff' => "@@ -40,8 +40,10 @@ class Auth\n context\n-bad\n+good\n context",
                ],
            ],
        ], 200),
    ]);

    $job = new ProcessCodeChangeCorrelation(
        projectId: $task->project_id,
        gitlabProjectId: $task->project->gitlab_project_id,
        mrIid: 42,
        beforeSha: 'aaa111',
        afterSha: 'bbb222',
    );
    $job->handle(app(GitLabClient::class));

    $acceptance = FindingAcceptance::first();
    expect($acceptance->code_change_correlated)->toBeTrue();
    expect($acceptance->correlated_commit_sha)->toBe('bbb222');
});

it('does not correlate when push does not touch finding file', function () {
    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'mr_iid' => 42,
        'result' => [
            'findings' => [
                ['id' => 1, 'severity' => 'critical', 'file' => 'src/auth.py', 'line' => 42, 'end_line' => 45, 'title' => 'SQL injection', 'category' => 'security', 'description' => '', 'suggestion' => '', 'labels' => []],
            ],
        ],
    ]);

    FindingAcceptance::create([
        'task_id' => $task->id,
        'project_id' => $task->project_id,
        'mr_iid' => 42,
        'finding_id' => '1',
        'file' => 'src/auth.py',
        'line' => 42,
        'severity' => 'critical',
        'title' => 'SQL injection',
        'status' => 'pending',
    ]);

    Http::fake([
        '*/api/v4/projects/*/repository/compare*' => Http::response([
            'diffs' => [
                [
                    'new_path' => 'src/other.py',
                    'diff' => "@@ -1,3 +1,5 @@\n context\n-old\n+new\n context",
                ],
            ],
        ], 200),
    ]);

    $job = new ProcessCodeChangeCorrelation(
        projectId: $task->project_id,
        gitlabProjectId: $task->project->gitlab_project_id,
        mrIid: 42,
        beforeSha: 'aaa111',
        afterSha: 'bbb222',
    );
    $job->handle(app(GitLabClient::class));

    $acceptance = FindingAcceptance::first();
    expect($acceptance->code_change_correlated)->toBeFalse();
});
```

**Step 2: Implement `ProcessCodeChangeCorrelation` job**

```php
<?php

namespace App\Jobs;

use App\Models\FindingAcceptance;
use App\Services\AcceptanceTrackingService;
use App\Services\GitLabClient;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Correlate push event code changes with existing AI findings.
 *
 * Per §16.2: If a finding targets file:line and the next push modifies
 * that region → strong acceptance signal.
 */
class ProcessCodeChangeCorrelation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $projectId,
        public readonly int $gitlabProjectId,
        public readonly int $mrIid,
        public readonly string $beforeSha,
        public readonly string $afterSha,
    ) {
        $this->queue = QueueNames::SERVER;
    }

    public function handle(GitLabClient $gitLab): void
    {
        $acceptances = FindingAcceptance::where('project_id', $this->projectId)
            ->where('mr_iid', $this->mrIid)
            ->where('code_change_correlated', false)
            ->get();

        if ($acceptances->isEmpty()) {
            Log::debug('ProcessCodeChangeCorrelation: no pending acceptances for MR', [
                'project_id' => $this->projectId,
                'mr_iid' => $this->mrIid,
            ]);

            return;
        }

        // Fetch diffs between before and after push SHAs
        try {
            $compare = $gitLab->compareBranches(
                $this->gitlabProjectId,
                $this->beforeSha,
                $this->afterSha,
            );
        } catch (\Throwable $e) {
            Log::warning('ProcessCodeChangeCorrelation: failed to compare branches', [
                'project_id' => $this->projectId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $diffs = $compare['diffs'] ?? [];
        $service = new AcceptanceTrackingService();

        foreach ($acceptances as $acceptance) {
            $finding = [
                'file' => $acceptance->file,
                'line' => $acceptance->line,
                'end_line' => $acceptance->line, // best we have from stored data
            ];

            if ($service->correlateCodeChange($finding, $diffs)) {
                $acceptance->update([
                    'code_change_correlated' => true,
                    'correlated_commit_sha' => $this->afterSha,
                ]);

                Log::info('ProcessCodeChangeCorrelation: correlated code change', [
                    'finding_acceptance_id' => $acceptance->id,
                    'file' => $acceptance->file,
                    'line' => $acceptance->line,
                ]);
            }
        }
    }
}
```

**Step 3: Run tests to verify they pass**

Run: `php artisan test --filter=ProcessCodeChangeCorrelationTest`
Expected: All pass

**Step 4: Commit**

```bash
git add app/Jobs/ProcessCodeChangeCorrelation.php tests/Feature/Jobs/ProcessCodeChangeCorrelationTest.php
git commit --no-gpg-sign -m "T86.8: Add ProcessCodeChangeCorrelation for push event correlation"
```

---

### Task 9: Wire push events to dispatch code change correlation

**Files:**
- Modify: `app/Http/Controllers/WebhookController.php` — after dispatching incremental review, also dispatch correlation
- Modify: `app/Services/EventRouter.php` — no changes needed (push already routes to incremental_review)

Push events already route to `incremental_review`. We need to **additionally** dispatch `ProcessCodeChangeCorrelation` when a push event arrives and there are pending acceptances. This is done alongside the existing incremental review dispatch, not instead of it.

**Step 1: Modify WebhookController**

In `app/Http/Controllers/WebhookController.php`, after the task dispatch line (`$task = $taskDispatchService->dispatch($routingResult);`), add:

```php
// T86: Additionally dispatch code change correlation for push events
if ($routingResult->intent === 'incremental_review' && $routingResult->event instanceof PushToMRBranch) {
    $this->dispatchCodeChangeCorrelation($routingResult, $project);
}
```

Add the import:

```php
use App\Events\Webhook\PushToMRBranch;
use App\Jobs\ProcessCodeChangeCorrelation;
```

Add the private method:

```php
/**
 * Dispatch code change correlation for push events (T86, §16.2).
 */
private function dispatchCodeChangeCorrelation(RoutingResult $routingResult, Project $project): void
{
    $event = $routingResult->event;

    if (! $event instanceof PushToMRBranch) {
        return;
    }

    // Resolve MR IID for the pushed branch
    try {
        $gitLab = app(GitLabClient::class);
        $mr = $gitLab->findOpenMergeRequestForBranch(
            $project->gitlab_project_id,
            $event->branchName(),
        );

        if ($mr === null) {
            return;
        }

        ProcessCodeChangeCorrelation::dispatch(
            $project->id,
            $project->gitlab_project_id,
            (int) $mr['iid'],
            $event->beforeSha,
            $event->afterSha,
        );
    } catch (\Throwable $e) {
        Log::warning('WebhookController: failed to dispatch code change correlation', [
            'project_id' => $project->id,
            'error' => $e->getMessage(),
        ]);
    }
}
```

**Step 2: Write integration test**

Add to `tests/Feature/WebhookAcceptanceTrackingTest.php`:

```php
it('dispatches ProcessCodeChangeCorrelation on push webhook', function () {
    Queue::fake([ProcessCodeChangeCorrelation::class]);

    Http::fake([
        '*/api/v4/projects/*/merge_requests*' => Http::response([
            ['iid' => 42],
        ], 200),
    ]);

    $project = Project::factory()->create([
        'gitlab_project_id' => 100,
        'webhook_secret' => 'test-secret',
    ]);

    $payload = [
        'object_kind' => 'push',
        'ref' => 'refs/heads/feature/test',
        'before' => 'aaa111',
        'after' => 'bbb222',
        'user_id' => 1,
        'commits' => [],
        'total_commits_count' => 1,
    ];

    $response = $this->postJson('/api/webhook', $payload, [
        'X-Gitlab-Event' => 'Push Hook',
        'X-Gitlab-Token' => 'test-secret',
        'X-Gitlab-Event-UUID' => 'uuid-push-1',
    ]);

    $response->assertOk();

    Queue::assertPushed(ProcessCodeChangeCorrelation::class, function ($job) use ($project) {
        return $job->projectId === $project->id
            && $job->mrIid === 42
            && $job->beforeSha === 'aaa111'
            && $job->afterSha === 'bbb222';
    });
});
```

**Step 3: Run tests**

Run: `php artisan test --filter=WebhookAcceptanceTrackingTest`
Expected: All pass

**Step 4: Commit**

```bash
git add app/Http/Controllers/WebhookController.php tests/Feature/WebhookAcceptanceTrackingTest.php
git commit --no-gpg-sign -m "T86.9: Wire push events to dispatch code change correlation"
```

---

### Task 10: Add T86 structural checks to verify_m4.py

**Files:**
- Modify: `verify/verify_m4.py`

**Step 1: Add T86 section before the Summary section**

```python
# ============================================================
#  T86: Acceptance tracking (webhook-driven D149)
# ============================================================
section("T86: Acceptance Tracking")

# Model & migration
checker.check(
    "FindingAcceptance model exists",
    file_exists("app/Models/FindingAcceptance.php"),
)
checker.check(
    "FindingAcceptance migration exists",
    file_exists("database/migrations/2026_02_15_040000_create_finding_acceptances_table.php"),
)
checker.check(
    "FindingAcceptance has status field",
    file_contains("app/Models/FindingAcceptance.php", "'status'"),
)
checker.check(
    "FindingAcceptance has code_change_correlated field",
    file_contains("app/Models/FindingAcceptance.php", "code_change_correlated"),
)
checker.check(
    "FindingAcceptance has bulk_resolved field",
    file_contains("app/Models/FindingAcceptance.php", "bulk_resolved"),
)

# AcceptanceTrackingService
checker.check(
    "AcceptanceTrackingService exists",
    file_exists("app/Services/AcceptanceTrackingService.php"),
)
checker.check(
    "AcceptanceTrackingService classifies thread state",
    file_contains("app/Services/AcceptanceTrackingService.php", "classifyThreadState"),
)
checker.check(
    "AcceptanceTrackingService detects bulk resolution",
    file_contains("app/Services/AcceptanceTrackingService.php", "detectBulkResolution"),
)
checker.check(
    "AcceptanceTrackingService correlates code changes",
    file_contains("app/Services/AcceptanceTrackingService.php", "correlateCodeChange"),
)
checker.check(
    "AcceptanceTrackingService identifies AI discussions",
    file_contains("app/Services/AcceptanceTrackingService.php", "isAiCreatedDiscussion"),
)

# GitLabClient extension
checker.check(
    "GitLabClient has compareBranches method",
    file_contains("app/Services/GitLabClient.php", "compareBranches"),
)

# Jobs
checker.check(
    "ProcessAcceptanceTracking job exists",
    file_exists("app/Jobs/ProcessAcceptanceTracking.php"),
)
checker.check(
    "ProcessAcceptanceTracking handles MR merge",
    file_contains("app/Jobs/ProcessAcceptanceTracking.php", "listMergeRequestDiscussions"),
)
checker.check(
    "ProcessThreadResolution job exists",
    file_exists("app/Jobs/ProcessThreadResolution.php"),
)
checker.check(
    "ProcessCodeChangeCorrelation job exists",
    file_exists("app/Jobs/ProcessCodeChangeCorrelation.php"),
)
checker.check(
    "ProcessCodeChangeCorrelation uses compareBranches",
    file_contains("app/Jobs/ProcessCodeChangeCorrelation.php", "compareBranches"),
)

# WebhookController wiring
checker.check(
    "WebhookController dispatches acceptance tracking",
    file_contains("app/Http/Controllers/WebhookController.php", "ProcessAcceptanceTracking"),
)
checker.check(
    "WebhookController dispatches code change correlation",
    file_contains("app/Http/Controllers/WebhookController.php", "ProcessCodeChangeCorrelation"),
)

# Task model relationship
checker.check(
    "Task has findingAcceptances relationship",
    file_contains("app/Models/Task.php", "findingAcceptances"),
)

# Tests
checker.check(
    "AcceptanceTrackingService unit test exists",
    file_exists("tests/Unit/Services/AcceptanceTrackingServiceTest.php"),
)
checker.check(
    "ProcessAcceptanceTracking test exists",
    file_exists("tests/Feature/Jobs/ProcessAcceptanceTrackingTest.php"),
)
checker.check(
    "ProcessThreadResolution test exists",
    file_exists("tests/Feature/Jobs/ProcessThreadResolutionTest.php"),
)
checker.check(
    "ProcessCodeChangeCorrelation test exists",
    file_exists("tests/Feature/Jobs/ProcessCodeChangeCorrelationTest.php"),
)
checker.check(
    "Webhook acceptance tracking integration test exists",
    file_exists("tests/Feature/WebhookAcceptanceTrackingTest.php"),
)
```

**Step 2: Run verify**

Run: `python3 verify/verify_m4.py`
Expected: All T86 checks pass

**Step 3: Commit**

```bash
git add verify/verify_m4.py
git commit --no-gpg-sign -m "T86.10: Add T86 structural checks to M4 verification"
```

---

### Task 11: Run full verification and final commit

**Step 1: Run full test suite**

Run: `php artisan test --parallel`
Expected: All pass

**Step 2: Run M4 structural verification**

Run: `python3 verify/verify_m4.py`
Expected: All pass

**Step 3: Update progress.md**

Mark T86 as `[x]`, bold T87, update summary.

**Step 4: Clear handoff.md**

Reset to empty template.

**Step 5: Final commit**

```bash
git add progress.md handoff.md
git commit --no-gpg-sign -m "T86: Add acceptance tracking with webhook-driven classification and code correlation"
```

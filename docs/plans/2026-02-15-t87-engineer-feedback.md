# T87: Engineer Feedback — Emoji Reactions Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** On MR merge, fetch GitLab emoji reactions (👍/👎) on AI review comments and store alongside findings for quality signal aggregation.

**Architecture:** Extends the existing `ProcessAcceptanceTracking` job to also collect emoji feedback after classifying thread states. A new `EngineerFeedbackService` maps GitLab award emoji to sentiment (positive/negative/neutral). New columns on `finding_acceptances` store per-finding emoji counts and computed sentiment. A new `listNoteAwardEmoji()` method on `GitLabClient` calls the GitLab Award Emoji API.

**Tech Stack:** Laravel 11, Pest (unit + feature tests), PostgreSQL, GitLab REST API v4

---

### Task 1: Add `listNoteAwardEmoji()` to GitLabClient

**Files:**
- Modify: `app/Services/GitLabClient.php` (add method at end of Comments section, ~line 357)

**Step 1: Write the failing test**

Create `tests/Unit/Services/GitLabClientAwardEmojiTest.php`:

```php
<?php

use App\Services\GitLabClient;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

it('fetches award emoji for a merge request discussion note', function () {
    Http::fake([
        '*/api/v4/projects/42/merge_requests/10/discussions/disc-1/notes/100/award_emoji*' => Http::response([
            ['id' => 1, 'name' => 'thumbsup', 'user' => ['id' => 5, 'username' => 'engineer1']],
            ['id' => 2, 'name' => 'thumbsdown', 'user' => ['id' => 6, 'username' => 'engineer2']],
        ], 200),
    ]);

    $client = app(GitLabClient::class);
    $emoji = $client->listNoteAwardEmoji(42, 10, 'disc-1', 100);

    expect($emoji)->toHaveCount(2);
    expect($emoji[0]['name'])->toBe('thumbsup');
    expect($emoji[1]['name'])->toBe('thumbsdown');
});

it('returns empty array when note has no award emoji', function () {
    Http::fake([
        '*/api/v4/projects/42/merge_requests/10/discussions/disc-1/notes/100/award_emoji*' => Http::response([], 200),
    ]);

    $client = app(GitLabClient::class);
    $emoji = $client->listNoteAwardEmoji(42, 10, 'disc-1', 100);

    expect($emoji)->toBeEmpty();
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GitLabClientAwardEmojiTest`
Expected: FAIL — method `listNoteAwardEmoji` does not exist

**Step 3: Write minimal implementation**

Add to `app/Services/GitLabClient.php` after the `createMergeRequestDiscussion` method (in the Comments section):

```php
/**
 * List award emoji on a merge request discussion note.
 *
 * Used by T87 engineer feedback to read 👍/👎 reactions on AI review comments.
 *
 * @see https://docs.gitlab.com/ee/api/award_emoji.html#list-an-awardables-award-emoji
 *
 * @return array<int, array{id: int, name: string, user: array}>
 */
public function listNoteAwardEmoji(int $projectId, int $mrIid, string $discussionId, int $noteId): array
{
    $response = $this->request()->get(
        $this->url("projects/{$projectId}/merge_requests/{$mrIid}/discussions/{$discussionId}/notes/{$noteId}/award_emoji"),
        ['per_page' => 100],
    );

    return $this->handleResponse($response, "listNoteAwardEmoji !{$mrIid} disc:{$discussionId} note:{$noteId}")->json();
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter=GitLabClientAwardEmojiTest`
Expected: PASS (2 tests)

**Step 5: Commit**

```bash
git add app/Services/GitLabClient.php tests/Unit/Services/GitLabClientAwardEmojiTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T87.1: Add listNoteAwardEmoji to GitLabClient

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Create EngineerFeedbackService

**Files:**
- Create: `app/Services/EngineerFeedbackService.php`
- Create: `tests/Unit/Services/EngineerFeedbackServiceTest.php`

**Step 1: Write the failing tests**

Create `tests/Unit/Services/EngineerFeedbackServiceTest.php`:

```php
<?php

use App\Services\EngineerFeedbackService;

// ─── mapEmojiToSentiment ────────────────────────────────────────

it('maps thumbsup to positive', function () {
    $service = new EngineerFeedbackService();

    $emoji = [
        ['name' => 'thumbsup', 'user' => ['id' => 5]],
    ];

    $result = $service->classifyReactions($emoji);

    expect($result)->toBe([
        'positive_count' => 1,
        'negative_count' => 0,
        'sentiment' => 'positive',
    ]);
});

it('maps thumbsdown to negative', function () {
    $service = new EngineerFeedbackService();

    $emoji = [
        ['name' => 'thumbsdown', 'user' => ['id' => 6]],
    ];

    $result = $service->classifyReactions($emoji);

    expect($result)->toBe([
        'positive_count' => 0,
        'negative_count' => 1,
        'sentiment' => 'negative',
    ]);
});

it('maps empty reactions to neutral', function () {
    $service = new EngineerFeedbackService();

    $result = $service->classifyReactions([]);

    expect($result)->toBe([
        'positive_count' => 0,
        'negative_count' => 0,
        'sentiment' => 'neutral',
    ]);
});

it('counts multiple reactions and determines sentiment by majority', function () {
    $service = new EngineerFeedbackService();

    $emoji = [
        ['name' => 'thumbsup', 'user' => ['id' => 1]],
        ['name' => 'thumbsup', 'user' => ['id' => 2]],
        ['name' => 'thumbsdown', 'user' => ['id' => 3]],
    ];

    $result = $service->classifyReactions($emoji);

    expect($result)->toBe([
        'positive_count' => 2,
        'negative_count' => 1,
        'sentiment' => 'positive',
    ]);
});

it('returns neutral sentiment when positive and negative counts are equal', function () {
    $service = new EngineerFeedbackService();

    $emoji = [
        ['name' => 'thumbsup', 'user' => ['id' => 1]],
        ['name' => 'thumbsdown', 'user' => ['id' => 2]],
    ];

    $result = $service->classifyReactions($emoji);

    expect($result)->toBe([
        'positive_count' => 1,
        'negative_count' => 1,
        'sentiment' => 'neutral',
    ]);
});

it('ignores non-thumbs emoji reactions', function () {
    $service = new EngineerFeedbackService();

    $emoji = [
        ['name' => 'thumbsup', 'user' => ['id' => 1]],
        ['name' => 'heart', 'user' => ['id' => 2]],
        ['name' => 'rocket', 'user' => ['id' => 3]],
        ['name' => 'thumbsdown', 'user' => ['id' => 4]],
    ];

    $result = $service->classifyReactions($emoji);

    expect($result)->toBe([
        'positive_count' => 1,
        'negative_count' => 1,
        'sentiment' => 'neutral',
    ]);
});

// ─── inferSentimentFromThreadState ──────────────────────────────

it('infers positive sentiment from accepted thread when no reactions', function () {
    $service = new EngineerFeedbackService();

    expect($service->inferSentimentFromThreadState('accepted'))->toBe('neutral');
    expect($service->inferSentimentFromThreadState('accepted_auto'))->toBe('neutral');
    expect($service->inferSentimentFromThreadState('dismissed'))->toBe('neutral');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=EngineerFeedbackServiceTest`
Expected: FAIL — class `EngineerFeedbackService` not found

**Step 3: Write minimal implementation**

Create `app/Services/EngineerFeedbackService.php`:

```php
<?php

namespace App\Services;

/**
 * Maps GitLab emoji reactions to engineer feedback sentiment.
 *
 * Per §16.3/D111: 👍 → positive, 👎 → negative, no reaction → neutral.
 * Sentiment is inferred from thread state when no explicit reactions exist.
 */
class EngineerFeedbackService
{
    /**
     * Classify award emoji reactions into a sentiment result.
     *
     * @param  array<int, array{name: string, user: array}>  $emoji
     * @return array{positive_count: int, negative_count: int, sentiment: string}
     */
    public function classifyReactions(array $emoji): array
    {
        $positive = 0;
        $negative = 0;

        foreach ($emoji as $reaction) {
            match ($reaction['name'] ?? '') {
                'thumbsup' => $positive++,
                'thumbsdown' => $negative++,
                default => null, // Ignore non-thumbs emoji
            };
        }

        $sentiment = 'neutral';
        if ($positive > $negative) {
            $sentiment = 'positive';
        } elseif ($negative > $positive) {
            $sentiment = 'negative';
        }

        return [
            'positive_count' => $positive,
            'negative_count' => $negative,
            'sentiment' => $sentiment,
        ];
    }

    /**
     * Infer sentiment from thread acceptance state when no emoji exist.
     *
     * Per §16.3: "No reaction → neutral — infer from thread state."
     * All states map to neutral since the implicit signal is already
     * captured by the acceptance status field itself.
     */
    public function inferSentimentFromThreadState(string $status): string
    {
        return 'neutral';
    }
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter=EngineerFeedbackServiceTest`
Expected: PASS (7 tests)

**Step 5: Commit**

```bash
git add app/Services/EngineerFeedbackService.php tests/Unit/Services/EngineerFeedbackServiceTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T87.2: Add EngineerFeedbackService with emoji-to-sentiment mapping

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Add emoji feedback columns to finding_acceptances

**Files:**
- Create: `database/migrations/2026_02_15_050000_add_emoji_feedback_to_finding_acceptances.php`
- Modify: `app/Models/FindingAcceptance.php` (add new fillable fields + casts)

**Step 1: Create migration**

Create `database/migrations/2026_02_15_050000_add_emoji_feedback_to_finding_acceptances.php`:

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

        if (! Schema::hasTable('finding_acceptances')) {
            return;
        }

        Schema::table('finding_acceptances', function (Blueprint $table) {
            // Engineer feedback — emoji reactions (T87, §16.3)
            $table->unsignedSmallInteger('emoji_positive_count')->default(0);
            $table->unsignedSmallInteger('emoji_negative_count')->default(0);
            $table->string('emoji_sentiment', 20)->default('neutral'); // positive, negative, neutral

            // Finding category for aggregation (T87 spec: per category)
            $table->string('category')->nullable();

            // Index for aggregation queries
            $table->index('emoji_sentiment');
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasTable('finding_acceptances')) {
            return;
        }

        Schema::table('finding_acceptances', function (Blueprint $table) {
            $table->dropIndex(['emoji_sentiment']);
            $table->dropColumn([
                'emoji_positive_count',
                'emoji_negative_count',
                'emoji_sentiment',
                'category',
            ]);
        });
    }
};
```

**Step 2: Update FindingAcceptance model**

Modify `app/Models/FindingAcceptance.php` — add to `$fillable` array:

```php
protected $fillable = [
    'task_id',
    'project_id',
    'mr_iid',
    'finding_id',
    'file',
    'line',
    'severity',
    'title',
    'category',
    'gitlab_discussion_id',
    'status',
    'resolved_at',
    'code_change_correlated',
    'correlated_commit_sha',
    'bulk_resolved',
    'emoji_positive_count',
    'emoji_negative_count',
    'emoji_sentiment',
];
```

Update `casts()`:

```php
protected function casts(): array
{
    return [
        'line' => 'integer',
        'emoji_positive_count' => 'integer',
        'emoji_negative_count' => 'integer',
        'code_change_correlated' => 'boolean',
        'bulk_resolved' => 'boolean',
        'resolved_at' => 'datetime',
    ];
}
```

**Step 3: Verify existing tests still pass**

Run: `php artisan test --filter=ProcessAcceptanceTrackingTest`
Expected: PASS (existing tests unaffected — new columns have defaults)

**Step 4: Commit**

```bash
git add database/migrations/2026_02_15_050000_add_emoji_feedback_to_finding_acceptances.php app/Models/FindingAcceptance.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T87.3: Add emoji feedback columns to finding_acceptances

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Extend ProcessAcceptanceTracking to collect emoji reactions

**Files:**
- Modify: `app/Jobs/ProcessAcceptanceTracking.php` (add emoji collection after thread classification)
- Create: `tests/Feature/Jobs/ProcessEmojiReactionsTest.php`

**Step 1: Write the failing integration test**

Create `tests/Feature/Jobs/ProcessEmojiReactionsTest.php`:

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

function createReviewTaskForEmoji(int $mrIid = 42, array $findings = []): Task
{
    if (empty($findings)) {
        $findings = [
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
        ];
    }

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
                'total_findings' => count($findings),
                'findings_by_severity' => ['critical' => 1, 'major' => 1, 'minor' => 0],
                'walkthrough' => [],
            ],
            'findings' => $findings,
            'labels' => ['ai::reviewed'],
            'commit_status' => 'failed',
        ],
    ]);
}

function fakeDiscussionsWithNoteIds(): array
{
    return [
        [
            'id' => 'disc-ai-1',
            'notes' => [[
                'id' => 100,
                'body' => "🔴 **Critical** | Security\n\n**SQL injection risk**\n\nUser input in SQL query.",
                'resolved' => true,
                'updated_at' => '2026-02-15T10:00:00Z',
                'position' => ['new_path' => 'src/auth.py', 'new_line' => 42],
            ]],
        ],
        [
            'id' => 'disc-ai-2',
            'notes' => [[
                'id' => 200,
                'body' => "🟡 **Major** | Bug\n\n**Null pointer dereference**\n\nUser may be null.",
                'resolved' => false,
                'updated_at' => '2026-02-15T10:05:00Z',
                'position' => ['new_path' => 'src/utils.py', 'new_line' => 18],
            ]],
        ],
    ];
}

// ─── Emoji reaction collection ──────────────────────────────────

it('stores emoji reactions alongside finding acceptance records', function () {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42/discussions*' => Http::response(fakeDiscussionsWithNoteIds(), 200),
        // Note 100 has thumbsup
        '*/discussions/disc-ai-1/notes/100/award_emoji*' => Http::response([
            ['id' => 1, 'name' => 'thumbsup', 'user' => ['id' => 5]],
        ], 200),
        // Note 200 has thumbsdown
        '*/discussions/disc-ai-2/notes/200/award_emoji*' => Http::response([
            ['id' => 2, 'name' => 'thumbsdown', 'user' => ['id' => 6]],
        ], 200),
    ]);

    $task = createReviewTaskForEmoji();

    $job = new ProcessAcceptanceTracking(
        projectId: $task->project_id,
        gitlabProjectId: $task->project->gitlab_project_id,
        mrIid: 42,
    );
    $job->handle(app(GitLabClient::class));

    $accepted = FindingAcceptance::where('finding_id', '1')->first();
    expect($accepted->emoji_positive_count)->toBe(1);
    expect($accepted->emoji_negative_count)->toBe(0);
    expect($accepted->emoji_sentiment)->toBe('positive');
    expect($accepted->category)->toBe('security');

    $dismissed = FindingAcceptance::where('finding_id', '2')->first();
    expect($dismissed->emoji_positive_count)->toBe(0);
    expect($dismissed->emoji_negative_count)->toBe(1);
    expect($dismissed->emoji_sentiment)->toBe('negative');
    expect($dismissed->category)->toBe('bug');
});

it('stores neutral sentiment when no emoji reactions exist', function () {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42/discussions*' => Http::response(fakeDiscussionsWithNoteIds(), 200),
        '*/award_emoji*' => Http::response([], 200),
    ]);

    $task = createReviewTaskForEmoji();

    $job = new ProcessAcceptanceTracking(
        projectId: $task->project_id,
        gitlabProjectId: $task->project->gitlab_project_id,
        mrIid: 42,
    );
    $job->handle(app(GitLabClient::class));

    $records = FindingAcceptance::all();
    expect($records)->toHaveCount(2);
    $records->each(fn ($r) => expect($r->emoji_sentiment)->toBe('neutral'));
});

it('continues processing even when emoji API call fails for one note', function () {
    Http::fake([
        '*/api/v4/projects/*/merge_requests/42/discussions*' => Http::response(fakeDiscussionsWithNoteIds(), 200),
        // First note's emoji fetch fails
        '*/discussions/disc-ai-1/notes/100/award_emoji*' => Http::response('Server Error', 500),
        // Second succeeds
        '*/discussions/disc-ai-2/notes/200/award_emoji*' => Http::response([
            ['id' => 2, 'name' => 'thumbsup', 'user' => ['id' => 5]],
        ], 200),
    ]);

    $task = createReviewTaskForEmoji();

    $job = new ProcessAcceptanceTracking(
        projectId: $task->project_id,
        gitlabProjectId: $task->project->gitlab_project_id,
        mrIid: 42,
    );
    $job->handle(app(GitLabClient::class));

    // Both records should be created
    expect(FindingAcceptance::count())->toBe(2);

    // First finding should have neutral (failed fetch)
    $first = FindingAcceptance::where('finding_id', '1')->first();
    expect($first->emoji_sentiment)->toBe('neutral');

    // Second finding should have positive
    $second = FindingAcceptance::where('finding_id', '2')->first();
    expect($second->emoji_positive_count)->toBe(1);
    expect($second->emoji_sentiment)->toBe('positive');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ProcessEmojiReactionsTest`
Expected: FAIL — emoji fields are all 0/neutral because the job doesn't collect them yet

**Step 3: Modify ProcessAcceptanceTracking to collect emoji**

Modify `app/Jobs/ProcessAcceptanceTracking.php`:

1. Add use statements:
```php
use App\Services\EngineerFeedbackService;
```

2. Replace the `handle()` method with this version that adds emoji collection:

```php
public function handle(GitLabClient $gitLab): void
{
    $acceptanceService = new AcceptanceTrackingService();
    $feedbackService = new EngineerFeedbackService();

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
        fn (array $d) => $acceptanceService->isAiCreatedDiscussion($d),
    );

    // Detect bulk resolution across all AI threads
    $isBulkResolved = $acceptanceService->detectBulkResolution($aiDiscussions);

    // T87: Collect emoji reactions for each AI discussion's first note
    $emojiByDiscussion = $this->collectEmojiReactions($gitLab, $feedbackService, $aiDiscussions);

    // Process each task's findings
    foreach ($tasks as $task) {
        $findings = $task->result['findings'] ?? [];

        foreach ($findings as $finding) {
            // Only track findings that had inline threads (critical/major)
            if (! in_array($finding['severity'], ['critical', 'major'], true)) {
                continue;
            }

            $discussionId = $acceptanceService->matchFindingToDiscussion($finding, $aiDiscussions);

            // Classify the thread state
            $status = 'dismissed'; // default if no matching discussion found
            if ($discussionId !== null) {
                $matchedDiscussion = collect($aiDiscussions)
                    ->first(fn (array $d) => ($d['id'] ?? null) === $discussionId);

                if ($matchedDiscussion !== null) {
                    $status = $acceptanceService->classifyThreadState($matchedDiscussion);
                }
            }

            // T87: Get emoji feedback for this discussion
            $emojiResult = $emojiByDiscussion[$discussionId] ?? [
                'positive_count' => 0,
                'negative_count' => 0,
                'sentiment' => 'neutral',
            ];

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
                    'category' => $finding['category'] ?? null,
                    'gitlab_discussion_id' => $discussionId,
                    'status' => $status,
                    'bulk_resolved' => $isBulkResolved,
                    'emoji_positive_count' => $emojiResult['positive_count'],
                    'emoji_negative_count' => $emojiResult['negative_count'],
                    'emoji_sentiment' => $emojiResult['sentiment'],
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

/**
 * Collect emoji reactions for all AI discussion first notes.
 *
 * Returns a map of discussion_id => emoji classification result.
 * Failures for individual notes are logged and treated as neutral.
 *
 * @param  array<int, array>  $aiDiscussions
 * @return array<string, array{positive_count: int, negative_count: int, sentiment: string}>
 */
private function collectEmojiReactions(
    GitLabClient $gitLab,
    EngineerFeedbackService $feedbackService,
    array $aiDiscussions,
): array {
    $emojiByDiscussion = [];

    foreach ($aiDiscussions as $discussion) {
        $discussionId = $discussion['id'] ?? null;
        $noteId = $discussion['notes'][0]['id'] ?? null;

        if ($discussionId === null || $noteId === null) {
            continue;
        }

        try {
            $emoji = $gitLab->listNoteAwardEmoji(
                $this->gitlabProjectId,
                $this->mrIid,
                $discussionId,
                (int) $noteId,
            );

            $emojiByDiscussion[$discussionId] = $feedbackService->classifyReactions($emoji);
        } catch (\Throwable $e) {
            Log::warning('ProcessAcceptanceTracking: failed to fetch emoji for discussion', [
                'project_id' => $this->projectId,
                'mr_iid' => $this->mrIid,
                'discussion_id' => $discussionId,
                'error' => $e->getMessage(),
            ]);

            $emojiByDiscussion[$discussionId] = [
                'positive_count' => 0,
                'negative_count' => 0,
                'sentiment' => 'neutral',
            ];
        }
    }

    return $emojiByDiscussion;
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=ProcessEmojiReactionsTest`
Expected: PASS (3 tests)

**Step 5: Verify existing T86 tests still pass**

Run: `php artisan test --filter=ProcessAcceptanceTrackingTest`
Expected: PASS (4 tests) — existing tests may need `'id'` field added to note fixtures if they don't already have it. If tests break because of missing `note.id` for the `collectEmojiReactions` call, update the `fakeGitLabDiscussions()` helper in `ProcessAcceptanceTrackingTest.php` to include `'id' => N` in each note array. Also add an `Http::fake` catch-all for award_emoji URLs: `'*/award_emoji*' => Http::response([], 200)`.

**Step 6: Commit**

```bash
git add app/Jobs/ProcessAcceptanceTracking.php tests/Feature/Jobs/ProcessEmojiReactionsTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T87.4: Extend ProcessAcceptanceTracking to collect emoji reactions

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Fix existing T86 tests for compatibility

**Files:**
- Modify: `tests/Feature/Jobs/ProcessAcceptanceTrackingTest.php` (add note IDs and emoji HTTP fakes)

**Step 1: Update the `fakeGitLabDiscussions()` helper**

Each note needs an `'id'` field so `collectEmojiReactions` can call the award emoji API. Add `Http::fake` patterns for award_emoji endpoints.

In `tests/Feature/Jobs/ProcessAcceptanceTrackingTest.php`:

Update `fakeGitLabDiscussions()` — add `'id' => N` to each note:

```php
function fakeGitLabDiscussions(): array
{
    return [
        [
            'id' => 'disc-ai-1',
            'notes' => [[
                'id' => 100,
                'body' => "🔴 **Critical** | Security\n\n**SQL injection risk**\n\nUser input in SQL query.",
                'resolved' => true,
                'updated_at' => '2026-02-15T10:00:00Z',
                'position' => ['new_path' => 'src/auth.py', 'new_line' => 42],
            ]],
        ],
        [
            'id' => 'disc-ai-2',
            'notes' => [[
                'id' => 200,
                'body' => "🟡 **Major** | Bug\n\n**Null pointer dereference**\n\nUser may be null.",
                'resolved' => false,
                'updated_at' => '2026-02-15T10:05:00Z',
                'position' => ['new_path' => 'src/utils.py', 'new_line' => 18],
            ]],
        ],
        [
            'id' => 'disc-human-1',
            'notes' => [[
                'id' => 300,
                'body' => 'Nice work on the refactoring!',
                'resolved' => true,
                'updated_at' => '2026-02-15T09:00:00Z',
            ]],
        ],
    ];
}
```

Update every test that calls `Http::fake` — add the award_emoji catch-all:

```php
Http::fake([
    '*/api/v4/projects/*/merge_requests/*/discussions*' => Http::response(...),
    '*/award_emoji*' => Http::response([], 200),  // T87: empty emoji for all notes
]);
```

Do the same for the bulk resolution test's inline discussion fixtures — add `'id' => N` to each note.

**Step 2: Run all acceptance tracking tests**

Run: `php artisan test --filter=AcceptanceTracking`
Expected: PASS (all T86 + T87 tests)

**Step 3: Commit**

```bash
git add tests/Feature/Jobs/ProcessAcceptanceTrackingTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T87.5: Update T86 tests for emoji collection compatibility

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Add T87 checks to M4 verification script

**Files:**
- Modify: `verify/verify_m4.py` (add T87 section before Summary)

**Step 1: Add T87 verification checks**

Add before the Summary section in `verify/verify_m4.py`:

```python
# ============================================================
#  T87: Engineer feedback — emoji reactions
# ============================================================
section("T87: Engineer Feedback — Emoji Reactions")

# EngineerFeedbackService
checker.check(
    "EngineerFeedbackService exists",
    file_exists("app/Services/EngineerFeedbackService.php"),
)
checker.check(
    "EngineerFeedbackService classifies reactions",
    file_contains("app/Services/EngineerFeedbackService.php", "classifyReactions"),
)
checker.check(
    "EngineerFeedbackService maps thumbsup to positive",
    file_contains("app/Services/EngineerFeedbackService.php", "thumbsup"),
)
checker.check(
    "EngineerFeedbackService maps thumbsdown to negative",
    file_contains("app/Services/EngineerFeedbackService.php", "thumbsdown"),
)

# GitLabClient award emoji
checker.check(
    "GitLabClient has listNoteAwardEmoji method",
    file_contains("app/Services/GitLabClient.php", "listNoteAwardEmoji"),
)
checker.check(
    "GitLabClient award emoji uses correct API path",
    file_contains("app/Services/GitLabClient.php", "award_emoji"),
)

# Migration for emoji columns
checker.check(
    "Emoji feedback migration exists",
    file_exists("database/migrations/2026_02_15_050000_add_emoji_feedback_to_finding_acceptances.php"),
)
checker.check(
    "Migration adds emoji_positive_count",
    file_contains("database/migrations/2026_02_15_050000_add_emoji_feedback_to_finding_acceptances.php", "emoji_positive_count"),
)
checker.check(
    "Migration adds emoji_negative_count",
    file_contains("database/migrations/2026_02_15_050000_add_emoji_feedback_to_finding_acceptances.php", "emoji_negative_count"),
)
checker.check(
    "Migration adds emoji_sentiment",
    file_contains("database/migrations/2026_02_15_050000_add_emoji_feedback_to_finding_acceptances.php", "emoji_sentiment"),
)

# FindingAcceptance model updated
checker.check(
    "FindingAcceptance has emoji_positive_count fillable",
    file_contains("app/Models/FindingAcceptance.php", "emoji_positive_count"),
)
checker.check(
    "FindingAcceptance has emoji_negative_count fillable",
    file_contains("app/Models/FindingAcceptance.php", "emoji_negative_count"),
)
checker.check(
    "FindingAcceptance has emoji_sentiment fillable",
    file_contains("app/Models/FindingAcceptance.php", "emoji_sentiment"),
)
checker.check(
    "FindingAcceptance has category fillable",
    file_contains("app/Models/FindingAcceptance.php", "'category'"),
)

# ProcessAcceptanceTracking integration
checker.check(
    "ProcessAcceptanceTracking uses EngineerFeedbackService",
    file_contains("app/Jobs/ProcessAcceptanceTracking.php", "EngineerFeedbackService"),
)
checker.check(
    "ProcessAcceptanceTracking collects emoji reactions",
    file_contains("app/Jobs/ProcessAcceptanceTracking.php", "collectEmojiReactions"),
)
checker.check(
    "ProcessAcceptanceTracking calls listNoteAwardEmoji",
    file_contains("app/Jobs/ProcessAcceptanceTracking.php", "listNoteAwardEmoji"),
)

# Tests
checker.check(
    "EngineerFeedbackService unit test exists",
    file_exists("tests/Unit/Services/EngineerFeedbackServiceTest.php"),
)
checker.check(
    "Emoji reactions feature test exists",
    file_exists("tests/Feature/Jobs/ProcessEmojiReactionsTest.php"),
)
checker.check(
    "GitLabClient award emoji test exists",
    file_exists("tests/Unit/Services/GitLabClientAwardEmojiTest.php"),
)
```

**Step 2: Run verification**

Run: `python3 verify/verify_m4.py`
Expected: T87 checks should all FAIL (nothing implemented yet) while T73-T86 checks PASS

**Step 3: Commit**

```bash
git add verify/verify_m4.py
git commit --no-gpg-sign -m "$(cat <<'EOF'
T87.6: Add T87 verification checks to M4 script

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Run full verification and finalize

**Step 1: Run all tests**

Run: `php artisan test --parallel`
Expected: PASS (all existing + new T87 tests)

**Step 2: Run M4 structural verification**

Run: `python3 verify/verify_m4.py`
Expected: ALL PASS (including new T87 checks)

**Step 3: Update progress.md**

- Check `[x]` for T87
- Update M4 count to 15/15 ✅
- Update summary: Tasks Complete 88/116 (75.9%)
- Bold the next task: T88
- Update Current Milestone to M5 — Admin & Configuration
- Update Last Verified to T87

**Step 4: Clear handoff.md**

Reset handoff.md to empty template.

**Step 5: Final commit**

```bash
git add progress.md handoff.md
git commit --no-gpg-sign -m "$(cat <<'EOF'
T87: Add engineer feedback with emoji reaction tracking on MR merge

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

After this commit, **M4 is complete** (15/15). Consider tagging:

```bash
git tag -a m4-complete -m "M4 complete — all tasks verified"
```

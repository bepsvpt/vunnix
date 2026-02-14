# T40: Incremental Review Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** When a push to a branch with an open MR triggers an incremental review, update the original summary comment in-place with a timestamp, and avoid creating duplicate inline threads for existing unresolved findings.

**Architecture:** The incremental review reuses the existing code review pipeline (webhook → EventRouter → TaskDispatch → executor → result → 3-layer comments) with three targeted enhancements: (1) resolve branch→MR IID from push events so tasks get `mr_iid`, (2) find and reuse the previous review's `comment_id` so the summary updates in-place with a timestamp, (3) fetch existing MR discussions before posting threads to deduplicate findings.

**Tech Stack:** Laravel 11, Pest tests, GitLab REST API v4, PostgreSQL

---

### Task 1: Add `listMergeRequestDiscussions` to GitLabClient

**Files:**
- Modify: `app/Services/GitLabClient.php`
- Test: `tests/Unit/GitLabClientTest.php`

**Step 1: Write the failing test**

```php
it('lists merge request discussions', function () {
    Http::fake([
        '*/api/v4/projects/1/merge_requests/5/discussions*' => Http::response([
            ['id' => 'disc-1', 'notes' => [['body' => 'thread 1']]],
            ['id' => 'disc-2', 'notes' => [['body' => 'thread 2']]],
        ], 200),
    ]);

    $client = new \App\Services\GitLabClient();
    $discussions = $client->listMergeRequestDiscussions(1, 5);

    expect($discussions)->toHaveCount(2);
    expect($discussions[0]['id'])->toBe('disc-1');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter="lists merge request discussions"`
Expected: FAIL — method does not exist

**Step 3: Write minimal implementation**

Add to `GitLabClient.php` in the Comments section:

```php
/**
 * List all discussion threads on a merge request.
 *
 * Returns all discussions (both inline diff threads and general MR-level).
 * Used by incremental review (T40) to check for existing threads before
 * posting duplicates (D33).
 *
 * @return array<int, array>
 */
public function listMergeRequestDiscussions(int $projectId, int $mrIid, array $params = []): array
{
    $response = $this->request()->get(
        $this->url("projects/{$projectId}/merge_requests/{$mrIid}/discussions"),
        array_merge(['per_page' => 100], $params),
    );

    return $this->handleResponse($response, "listMRDiscussions !{$mrIid}")->json();
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter="lists merge request discussions"`
Expected: PASS

**Step 5: Add `findOpenMergeRequestForBranch` method**

Also add a method to find the open MR for a branch (needed for push→MR resolution):

```php
/**
 * Find an open merge request for a given source branch.
 *
 * Used by incremental review (T40) to resolve push events to their
 * associated MR. Returns null if no open MR exists for the branch.
 */
public function findOpenMergeRequestForBranch(int $projectId, string $sourceBranch): ?array
{
    $mrs = $this->listMergeRequests($projectId, [
        'source_branch' => $sourceBranch,
        'state' => 'opened',
        'per_page' => 1,
    ]);

    return $mrs[0] ?? null;
}
```

**Step 6: Commit**

```bash
git add app/Services/GitLabClient.php tests/Unit/GitLabClientTest.php
git commit --no-gpg-sign -m "T40.1: Add listMergeRequestDiscussions and findOpenMergeRequestForBranch to GitLabClient"
```

---

### Task 2: Resolve MR IID from push events in TaskDispatchService

**Files:**
- Modify: `app/Services/TaskDispatchService.php:116-125`
- Modify: `app/Services/EventDeduplicator.php:215-231`
- Test: `tests/Feature/TaskDispatchServiceTest.php`

**Step 1: Write the failing test**

```php
it('resolves mr_iid from push event via GitLab API', function () {
    Http::fake([
        '*/api/v4/projects/*/merge_requests*' => Http::response([
            ['iid' => 42, 'source_branch' => 'feature/login'],
        ], 200),
    ]);

    // ... create project, push event, routing result with incremental_review intent
    // Assert task->mr_iid === 42
});
```

**Step 2: Run to verify failure**

Run: `php artisan test --filter="resolves mr_iid from push event"`
Expected: FAIL — task->mr_iid is null

**Step 3: Implement MR resolution in TaskDispatchService**

Modify `extractMrIid()` in `TaskDispatchService.php`:

```php
private function extractMrIid(WebhookEvent $event): ?int
{
    return match (true) {
        $event instanceof MergeRequestOpened,
        $event instanceof MergeRequestUpdated,
        $event instanceof MergeRequestMerged => $event->mergeRequestIid,
        $event instanceof NoteOnMR => $event->mergeRequestIid,
        $event instanceof PushToMRBranch => $this->resolveMrIidFromPush($event),
        default => null,
    };
}

/**
 * Resolve the MR IID for a push event by querying GitLab for open MRs on the branch.
 *
 * Per §3.1: "The Event Router queries the GitLab API to check whether the
 * pushed branch has an associated open MR."
 */
private function resolveMrIidFromPush(PushToMRBranch $event): ?int
{
    try {
        $gitLab = app(GitLabClient::class);
        $mr = $gitLab->findOpenMergeRequestForBranch(
            $event->gitlabProjectId,
            $event->branchName(),
        );

        if ($mr === null) {
            Log::info('TaskDispatchService: no open MR for pushed branch, skipping', [
                'branch' => $event->branchName(),
                'project_id' => $event->projectId,
            ]);

            return null;
        }

        return (int) $mr['iid'];
    } catch (\Throwable $e) {
        Log::warning('TaskDispatchService: failed to resolve MR for push event', [
            'branch' => $event->branchName(),
            'error' => $e->getMessage(),
        ]);

        return null;
    }
}
```

**Step 4: Update EventDeduplicator to also resolve MR IID from push**

In `EventDeduplicator::extractMrIid()`, push events still return null — but that's OK for now. The deduplicator uses commit SHA dedup which works without MR IID. The superseding logic in the deduplicator already handles `PushToMRBranch` as a superseding event. With the MR IID now set on the task, future dedup checks will work correctly.

Actually, the deduplicator runs BEFORE TaskDispatchService, so it still won't have the MR IID for push events at dedup time. This is acceptable — the commit SHA dedup still prevents true duplicates, and the latest-wins superseding will work once the task is created with the correct `mr_iid`.

**Step 5: Handle null mr_iid (no open MR) — skip dispatch**

In `TaskDispatchService::dispatch()`, if `extractMrIid` returns null for an incremental_review intent, we should skip dispatching entirely (no point reviewing a push with no MR):

```php
$mrIid = $this->extractMrIid($event);

// Incremental reviews require an MR — skip if branch has no open MR
if ($routingResult->intent === 'incremental_review' && $mrIid === null) {
    Log::info('TaskDispatchService: incremental_review has no MR, skipping dispatch', [
        'project_id' => $event->projectId,
    ]);

    return null;
}
```

**Step 6: Run tests and commit**

Run: `php artisan test --filter="TaskDispatchService"`
Expected: PASS

```bash
git add app/Services/TaskDispatchService.php app/Services/EventDeduplicator.php tests/Feature/TaskDispatchServiceTest.php
git commit --no-gpg-sign -m "T40.2: Resolve MR IID from push events via GitLab API"
```

---

### Task 3: Find previous review's comment_id for incremental updates

**Files:**
- Modify: `app/Jobs/PostPlaceholderComment.php:42-91`
- Test: `tests/Feature/PostPlaceholderCommentTest.php`

**Step 1: Write the failing test**

```php
it('reuses previous review comment_id for incremental review on same MR', function () {
    // Create a completed task for the same project+MR with a comment_id
    $previousTask = Task::factory()->create([
        'project_id' => $project->id,
        'mr_iid' => 42,
        'comment_id' => 99001,
        'status' => TaskStatus::Completed,
        'type' => TaskType::CodeReview,
    ]);

    // Create new incremental review task (no comment_id yet)
    $newTask = Task::factory()->create([
        'project_id' => $project->id,
        'mr_iid' => 42,
        'comment_id' => null,
        'status' => TaskStatus::Running,
        'type' => TaskType::CodeReview,
    ]);

    // PostPlaceholderComment should find previous task's comment_id
    // and update the existing comment instead of creating a new one
    Http::fake([...]);

    PostPlaceholderComment::dispatchSync($newTask->id);

    $newTask->refresh();
    expect($newTask->comment_id)->toBe(99001);
    // Assert PUT to existing note, not POST to create new
});
```

**Step 2: Implement previous comment lookup in PostPlaceholderComment**

Modify `PostPlaceholderComment::handle()`:

```php
public function handle(GitLabClient $gitLab): void
{
    $task = Task::with('project')->find($this->taskId);

    if ($task === null || $task->mr_iid === null) {
        // ... existing early returns
        return;
    }

    // Don't overwrite an existing comment_id
    if ($task->comment_id !== null) {
        return;
    }

    // T40: Check for a previous completed review on the same MR
    // to reuse its summary comment (update in-place instead of creating new)
    $previousCommentId = $this->findPreviousCommentId($task);

    if ($previousCommentId !== null) {
        // Reuse the previous comment — update it with "re-reviewing" placeholder
        try {
            $gitLab->updateMergeRequestNote(
                $task->project->gitlab_project_id,
                $task->mr_iid,
                $previousCommentId,
                '🤖 AI Review in progress… (re-reviewing after new commits)',
            );

            $task->comment_id = $previousCommentId;
            $task->save();

            Log::info('PostPlaceholderComment: reusing previous review comment (T40)', [
                'task_id' => $this->taskId,
                'previous_comment_id' => $previousCommentId,
            ]);

            return;
        } catch (\Throwable $e) {
            Log::warning('PostPlaceholderComment: failed to update previous comment, creating new', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
            // Fall through to create a new comment
        }
    }

    // ... existing code to create new placeholder
}

/**
 * Find the comment_id from the most recent completed review on the same MR.
 */
private function findPreviousCommentId(Task $task): ?int
{
    return Task::where('project_id', $task->project_id)
        ->where('mr_iid', $task->mr_iid)
        ->where('type', TaskType::CodeReview)
        ->where('status', TaskStatus::Completed)
        ->where('id', '!=', $task->id)
        ->whereNotNull('comment_id')
        ->orderByDesc('completed_at')
        ->value('comment_id');
}
```

**Step 3: Run tests and commit**

```bash
git commit --no-gpg-sign -m "T40.3: Reuse previous review comment_id for incremental reviews"
```

---

### Task 4: Add timestamp to incremental review summary comments

**Files:**
- Modify: `app/Services/SummaryCommentFormatter.php`
- Test: `tests/Unit/SummaryCommentFormatterTest.php`

**Step 1: Write the failing test**

```php
it('appends updated timestamp for incremental reviews', function () {
    $formatter = new SummaryCommentFormatter();
    $result = validCodeReviewResult();

    $markdown = $formatter->format($result, new \DateTimeImmutable('2026-02-14 14:32'));

    expect($markdown)->toContain('📝 Updated: 2026-02-14 14:32');
    expect($markdown)->toContain('## 🤖 AI Code Review');
});

it('does not include timestamp for initial reviews', function () {
    $formatter = new SummaryCommentFormatter();
    $result = validCodeReviewResult();

    $markdown = $formatter->format($result);

    expect($markdown)->not->toContain('📝 Updated');
});
```

**Step 2: Implement timestamp parameter**

Modify `SummaryCommentFormatter::format()` to accept an optional timestamp:

```php
/**
 * Format a validated code review result as a markdown summary comment.
 *
 * @param  array  $result  A validated CodeReviewSchema array.
 * @param  \DateTimeInterface|null  $updatedAt  If set, adds an "Updated" timestamp (T40 incremental review).
 */
public function format(array $result, ?\DateTimeInterface $updatedAt = null): string
{
    // ... existing code for header line

    // After the header, before walkthrough, add timestamp if incremental
    if ($updatedAt !== null) {
        $lines[] = '📝 Updated: ' . $updatedAt->format('Y-m-d H:i') . ' — re-reviewed after new commits';
        $lines[] = '';
    }

    // ... rest of existing formatting
}
```

**Step 3: Run tests and commit**

```bash
git commit --no-gpg-sign -m "T40.4: Add optional timestamp to SummaryCommentFormatter for incremental reviews"
```

---

### Task 5: Pass timestamp to PostSummaryComment for incremental reviews

**Files:**
- Modify: `app/Jobs/PostSummaryComment.php`
- Test: `tests/Feature/PostSummaryCommentTest.php`

**Step 1: Write the failing test**

```php
it('includes updated timestamp when task reuses a previous comment_id', function () {
    // Create a completed previous task with comment_id
    $previousTask = Task::factory()->create([
        'project_id' => $project->id,
        'mr_iid' => 42,
        'comment_id' => 99001,
        'status' => TaskStatus::Completed,
        'type' => TaskType::CodeReview,
    ]);

    // New task reusing the same comment_id
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'mr_iid' => 42,
        'comment_id' => 99001,
        'status' => TaskStatus::Completed,
        'type' => TaskType::CodeReview,
        'result' => codeReviewResult(),
    ]);

    Http::fake([...]);
    PostSummaryComment::dispatchSync($task->id);

    // Assert the PUT body contains the timestamp
    Http::assertSent(function ($request) {
        return $request->method() === 'PUT'
            && str_contains($request->data()['body'] ?? '', '📝 Updated:');
    });
});
```

**Step 2: Implement the logic**

In `PostSummaryComment::handle()`, detect incremental review (task reuses a comment_id that was originally created by a different task) and pass the timestamp:

```php
public function handle(GitLabClient $gitLab): void
{
    // ... existing early returns

    $formatter = new SummaryCommentFormatter();

    // T40: Detect incremental review — if this task's comment_id was inherited
    // from a previous task, include an "Updated" timestamp in the summary.
    $updatedAt = $this->isIncrementalReview($task) ? now() : null;
    $markdown = $formatter->format($task->result, $updatedAt);

    // ... existing code to update/create note
}

/**
 * Check if this task is an incremental review (reusing a previous task's comment).
 *
 * An incremental review is detected when a completed CodeReview task for the
 * same MR exists with the same comment_id — meaning we inherited the comment.
 */
private function isIncrementalReview(Task $task): bool
{
    if ($task->comment_id === null) {
        return false;
    }

    return Task::where('project_id', $task->project_id)
        ->where('mr_iid', $task->mr_iid)
        ->where('type', TaskType::CodeReview)
        ->where('status', TaskStatus::Completed)
        ->where('id', '!=', $task->id)
        ->where('comment_id', $task->comment_id)
        ->exists();
}
```

**Step 3: Run tests and commit**

```bash
git commit --no-gpg-sign -m "T40.5: Include updated timestamp in incremental review summary comments"
```

---

### Task 6: Deduplicate inline threads (D33)

**Files:**
- Modify: `app/Jobs/PostInlineThreads.php:42-125`
- Test: `tests/Feature/PostInlineThreadsTest.php`

**Step 1: Write the failing test**

```php
it('does not create duplicate threads for findings that already have unresolved discussions', function () {
    // Set up a task with two critical findings
    // Mock GitLab to return existing discussions that match one of the findings
    // Assert only ONE new discussion is created (the new finding), not two

    Http::fake([
        // Existing discussions: one unresolved thread matching finding #1
        '*/discussions*' => Http::sequence()
            ->push([
                [
                    'id' => 'existing-disc-1',
                    'notes' => [[
                        'body' => "🔴 **Critical** | Security\n\n**SQL injection via raw query**",
                        'position' => [
                            'new_path' => 'app/Services/PaymentService.php',
                            'new_line' => 42,
                        ],
                    ]],
                ],
            ], 200)
            ->push(['id' => 'new-disc'], 201),  // POST creates new

        '*/merge_requests/42' => Http::response([
            'iid' => 42, 'sha' => 'abc',
            'diff_refs' => ['base_sha' => 'b', 'start_sha' => 's', 'head_sha' => 'h'],
        ], 200),
    ]);

    PostInlineThreads::dispatchSync($task->id);

    // Only 1 new discussion created (finding #2), not 2
    $postDiscussions = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), '/discussions') && $pair[0]->method() === 'POST');

    expect($postDiscussions)->toHaveCount(1);
});
```

**Step 2: Implement thread deduplication**

Modify `PostInlineThreads::handle()`:

```php
public function handle(GitLabClient $gitLab): void
{
    // ... existing early returns and filtering

    $projectId = $task->project->gitlab_project_id;

    $mr = $gitLab->getMergeRequest($projectId, $task->mr_iid);
    $diffRefs = $mr['diff_refs'] ?? [];

    // T40: Fetch existing discussions to avoid duplicating threads (D33)
    $existingDiscussions = $this->fetchExistingDiscussions($gitLab, $projectId, $task->mr_iid);

    foreach ($findings as $finding) {
        // T40: Skip if this finding already has an unresolved discussion thread
        if ($this->hasExistingThread($finding, $existingDiscussions)) {
            Log::info('PostInlineThreads: skipping duplicate finding (D33)', [
                'task_id' => $this->taskId,
                'finding_id' => $finding['id'],
                'file' => $finding['file'],
                'line' => $finding['line'],
            ]);
            continue;
        }

        // ... existing code to create discussion thread
    }
}

/**
 * Fetch existing discussion threads for an MR to check for duplicates.
 */
private function fetchExistingDiscussions(GitLabClient $gitLab, int $projectId, int $mrIid): array
{
    try {
        return $gitLab->listMergeRequestDiscussions($projectId, $mrIid);
    } catch (\Throwable $e) {
        Log::warning('PostInlineThreads: failed to fetch discussions for dedup, proceeding without', [
            'task_id' => $this->taskId,
            'error' => $e->getMessage(),
        ]);
        return [];
    }
}

/**
 * Check if a finding already has an unresolved discussion thread.
 *
 * Matches by file path and finding title in the discussion body.
 * Per D33: "Same issue = same discussion thread. No duplicate threads."
 */
private function hasExistingThread(array $finding, array $discussions): bool
{
    foreach ($discussions as $discussion) {
        $notes = $discussion['notes'] ?? [];

        if (empty($notes)) {
            continue;
        }

        $firstNote = $notes[0];
        $body = $firstNote['body'] ?? '';
        $position = $firstNote['position'] ?? [];

        // Match by file path + finding title
        $sameFile = ($position['new_path'] ?? '') === $finding['file'];
        $sameTitle = str_contains($body, $finding['title']);

        if ($sameFile && $sameTitle) {
            return true;
        }
    }

    return false;
}
```

**Step 3: Run tests and commit**

```bash
git commit --no-gpg-sign -m "T40.6: Deduplicate inline threads for incremental reviews (D33)"
```

---

### Task 7: Update labels to reflect latest review state (D56)

**Files:**
- Modify: `app/Jobs/PostLabelsAndStatus.php:44-123`
- Test: `tests/Feature/PostLabelsAndStatusTest.php`

**Step 1: Write the failing test**

```php
it('replaces old AI risk labels when review state changes', function () {
    // Previous review was high risk, new review is low risk
    // Assert that labels are SET (not just added) so old risk labels are removed
});
```

**Step 2: Implement label replacement for AI labels**

Currently `PostLabelsAndStatus` uses `addMergeRequestLabels` (additive). For incremental reviews where risk level can change (e.g., `ai::risk-high` → `ai::risk-low`), we need to remove old AI risk labels and apply new ones.

Modify `PostLabelsAndStatus::handle()`:

```php
// Determine AI labels to remove before adding new ones
// This handles D56: Labels reflect the latest review state
$aiRiskLabels = ['ai::risk-high', 'ai::risk-medium', 'ai::risk-low'];
$labelsToRemove = array_diff($aiRiskLabels, $labels);

if (!empty($labelsToRemove)) {
    try {
        $gitLab->removeMergeRequestLabels($projectId, $task->mr_iid, $labelsToRemove);
    } catch (\Throwable $e) {
        Log::warning('PostLabelsAndStatus: failed to remove old labels', [
            'task_id' => $this->taskId,
            'error' => $e->getMessage(),
        ]);
        // Continue — adding correct labels is more important
    }
}
```

Also add `removeMergeRequestLabels` to `GitLabClient`:

```php
public function removeMergeRequestLabels(int $projectId, int $mrIid, array $labels): array
{
    $response = $this->request()->put(
        $this->url("projects/{$projectId}/merge_requests/{$mrIid}"),
        ['remove_labels' => implode(',', $labels)],
    );

    return $this->handleResponse($response, "removeMRLabels !{$mrIid}")->json();
}
```

**Step 3: Run tests and commit**

```bash
git commit --no-gpg-sign -m "T40.7: Replace old AI risk labels on incremental review (D56)"
```

---

### Task 8: E2E incremental review integration test

**Files:**
- Create: `tests/Feature/IncrementalReviewEndToEndTest.php`

**Step 1: Write the full E2E test**

This test exercises the complete incremental review flow:

1. First review: MR open → full code review → summary + threads + labels
2. Push to same branch → incremental review → summary updated with timestamp, same finding NOT duplicated, new finding gets new thread, labels updated

```php
it('updates summary in-place with timestamp and deduplicates threads on incremental review', function () {
    // Phase 1: Initial code review (reuse pattern from CodeReviewEndToEndTest)
    // ... webhook MR open → full review → verify summary + threads + labels

    // Phase 2: Push to same branch → incremental review
    // ... webhook Push Hook → incremental_review intent → new task created
    // ... simulate runner result with one same finding + one new finding
    // ... verify:
    //   - Summary comment updated (same note ID, PUT not POST)
    //   - Summary includes "📝 Updated:" timestamp
    //   - Existing finding NOT duplicated (only 1 new discussion POST)
    //   - New finding gets new thread
    //   - Labels updated to reflect new risk level
});
```

**Step 2: Run test and iterate**

Run: `php artisan test --filter="IncrementalReviewEndToEndTest"`
Expected: PASS

**Step 3: Commit**

```bash
git commit --no-gpg-sign -m "T40.8: Add E2E integration test for incremental review flow"
```

---

### Task 9: Add T40 verification checks to verify_m2.py

**Files:**
- Modify: `verify/verify_m2.py`

Add structural checks for:
- `GitLabClient::listMergeRequestDiscussions()` exists
- `GitLabClient::findOpenMergeRequestForBranch()` exists
- `GitLabClient::removeMergeRequestLabels()` exists
- `TaskDispatchService::resolveMrIidFromPush()` exists
- `PostPlaceholderComment::findPreviousCommentId()` exists
- `PostInlineThreads::hasExistingThread()` exists
- `SummaryCommentFormatter::format()` accepts optional timestamp parameter
- `IncrementalReviewEndToEndTest.php` exists
- Test for incremental review summary timestamp exists
- Test for thread deduplication exists

**Step 1: Add checks and run**

Run: `python3 verify/verify_m2.py`
Expected: All checks pass

**Step 2: Commit**

```bash
git commit --no-gpg-sign -m "T40.9: Add T40 verification checks to verify_m2.py"
```

---

### Task 10: Final verification and progress update

**Step 1: Run full test suite**

```bash
php artisan test
python3 verify/verify_m2.py
```

Expected: All pass

**Step 2: Update progress.md**

- Check `[x] T40`
- Bold `T41`
- Update summary counts

**Step 3: Commit**

```bash
git commit --no-gpg-sign -m "T40: Mark complete, update progress"
```

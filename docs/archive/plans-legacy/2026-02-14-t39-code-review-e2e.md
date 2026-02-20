# T39: Code Review — End-to-End Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Wire up the complete Path A code review flow from webhook reception through to 3-layer GitLab comments, closing the gap between EventDeduplicator and TaskDispatchService.

**Architecture:** The WebhookController already handles event parsing, routing, and deduplication. The downstream flow (ProcessTask → TaskDispatcher → pipeline trigger → result API → ResultProcessor → 3-layer comments) is fully implemented. The missing link is calling `TaskDispatchService::dispatch()` from the WebhookController after successful deduplication. An E2E integration test validates the full chain with mocked GitLab API.

**Tech Stack:** Laravel 11, Pest, Http::fake(), Queue sync driver

---

### Task 1: Wire TaskDispatchService into WebhookController

**Files:**
- Modify: `app/Http/Controllers/WebhookController.php`

**Step 1: Add TaskDispatchService to WebhookController**

Inject `TaskDispatchService` into the `__invoke` method and call `dispatch()` after successful deduplication:

```php
public function __invoke(
    Request $request,
    EventRouter $eventRouter,
    EventDeduplicator $deduplicator,
    TaskDispatchService $taskDispatchService,
): JsonResponse {
```

After the dedup accepted block (after line 110 — `return new DeduplicationResult(self::ACCEPT, ...)`), add the dispatch call before the final return:

```php
// T39: Dispatch task for accepted, routable events
$task = $taskDispatchService->dispatch($routingResult);

return response()->json([
    'status' => 'accepted',
    'event_type' => $eventType,
    'project_id' => $project->id,
    'intent' => $routingResult->intent,
    'superseded_count' => $dedupResult->supersededCount,
    'task_id' => $task?->id,
]);
```

**Step 2: Run existing tests to verify no regressions**

Run: `php artisan test --filter=WebhookControllerTest`

Existing tests should still pass — the `task_id` field is additive and `TaskDispatchService::dispatch()` returns null for non-dispatchable intents (help_response, acceptance_tracking). For dispatchable intents, the test environment uses sync queue, so `ProcessTask` will run inline. We need `Queue::fake()` or `Http::fake()` in tests that trigger dispatchable events (MR open) to prevent ProcessTask from calling the real GitLab API.

**Step 3: Update existing WebhookControllerTest to handle task dispatch**

The existing MR open/update tests now trigger real task dispatch (since sync queue runs ProcessTask inline). Add `Http::fake()` for GitLab API endpoints in tests that POST MR open/update events, and optionally assert that `task_id` is present in the response.

**Step 4: Commit**

```bash
git add app/Http/Controllers/WebhookController.php tests/Feature/WebhookControllerTest.php
git commit --no-gpg-sign -m "T39.1: Wire TaskDispatchService into WebhookController"
```

### Task 2: Write E2E integration test — full code review flow

**Files:**
- Create: `tests/Feature/CodeReviewEndToEndTest.php`

**Step 1: Write the E2E test**

This test verifies the M2 E2E scenario from the spec:
> Push MR → webhook → placeholder → review posted → inline threads → labels → commit status

```php
<?php
// Full code review flow: webhook → event router → dedup → task dispatch →
// ProcessTask → TaskDispatcher → placeholder → (simulated runner result) →
// ProcessTaskResult → ResultProcessor → PostSummaryComment + PostInlineThreads + PostLabelsAndStatus

uses(RefreshDatabase::class);

it('completes full code review flow from webhook to 3-layer GitLab comments', function () {
    // 1. Set up project with webhook config + CI trigger token
    // 2. Http::fake() all GitLab API endpoints (MR changes, notes, discussions, statuses, labels, pipeline trigger)
    // 3. POST webhook with MR open payload
    // 4. Assert: task created in DB, status transitions logged
    // 5. Assert: placeholder comment posted
    // 6. Assert: pipeline triggered with VUNNIX_* variables
    // 7. Simulate runner result: POST /api/v1/tasks/{id}/result with valid code review JSON
    // 8. Assert: summary comment posted (update-in-place via placeholder note ID)
    // 9. Assert: inline discussion threads posted for critical/major findings
    // 10. Assert: labels applied (ai::reviewed, ai::risk-high)
    // 11. Assert: commit status set (failed for critical findings)
    // 12. Assert: task status = completed
});
```

**Step 2: Run the test**

Run: `php artisan test --filter=CodeReviewEndToEndTest`
Expected: PASS

**Step 3: Commit**

```bash
git add tests/Feature/CodeReviewEndToEndTest.php
git commit --no-gpg-sign -m "T39.2: Add E2E integration test for full code review flow"
```

### Task 3: Add T39 verification checks to verify_m2.py

**Files:**
- Modify: `verify/verify_m2.py`

**Step 1: Add T39 section before the runtime checks section**

Add verification checks for:
- WebhookController imports TaskDispatchService
- WebhookController calls `$taskDispatchService->dispatch()`
- WebhookController returns `task_id` in response
- CodeReviewEndToEndTest file exists
- Test covers full flow (webhook to comments)
- Test covers placeholder-then-update pattern
- Test covers all 3 layers (summary, threads, labels)

**Step 2: Run verification**

Run: `python3 verify/verify_m2.py`
Expected: All checks pass

**Step 3: Commit**

```bash
git add verify/verify_m2.py
git commit --no-gpg-sign -m "T39.3: Add T39 verification checks to verify_m2.py"
```

### Task 4: Final verification and mark complete

**Step 1: Run full test suite**

Run: `php artisan test`
Expected: All tests pass

**Step 2: Run M2 verification**

Run: `python3 verify/verify_m2.py`
Expected: All checks pass

**Step 3: Update progress.md**

Mark T39 as complete, bold T40, update counts.

**Step 4: Clear handoff.md**

**Step 5: Final commit**

```bash
git add progress.md handoff.md
git commit --no-gpg-sign -m "T39: Mark complete, update progress"
```

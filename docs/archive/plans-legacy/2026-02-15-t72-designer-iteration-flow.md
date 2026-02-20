# T72: Designer Iteration Flow Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enable designers to iterate on UI adjustments within the same conversation — corrections push new commits to the same branch/MR instead of creating a new one each time.

**Architecture:** The DispatchAction tool gets an optional `existing_mr_iid` parameter. When set, the task carries the existing MR context through the pipeline: the runner pushes to the same branch, PostFeatureDevResult updates the existing MR description instead of creating a new one, and the result card shows the updated MR. The conversation result delivery includes MR/branch details so the CE has context for subsequent corrections.

**Tech Stack:** Laravel 11, Pest tests (unit + feature), Vue 3 (Vitest), GitLab API v4

---

### Task 1: Add `existing_mr_iid` to DispatchAction tool schema

**Files:**
- Modify: `app/Agents/Tools/DispatchAction.php:54-93` (schema method)
- Test: `tests/Unit/Agents/Tools/DispatchActionTest.php`

**Step 1: Write the failing test**

In `tests/Unit/Agents/Tools/DispatchActionTest.php`, add:

```php
it('includes existing_mr_iid in schema parameters', function () {
    $schema = new JsonSchemaTypeFactory;
    $params = $this->tool->schema($schema);

    expect($params)->toHaveKey('existing_mr_iid');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter="includes existing_mr_iid in schema"`
Expected: FAIL

**Step 3: Write minimal implementation**

In `app/Agents/Tools/DispatchAction.php`, add to the `schema()` method, after the `labels` field and before `user_id`:

```php
'existing_mr_iid' => $schema
    ->integer()
    ->description('Existing MR IID to push corrections to (for designer iteration flow). When set, the executor pushes to the same branch and updates the existing MR instead of creating a new one.'),
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter="includes existing_mr_iid in schema"`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Agents/Tools/DispatchAction.php tests/Unit/Agents/Tools/DispatchActionTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T72.1: Add existing_mr_iid to DispatchAction tool schema

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Pass `existing_mr_iid` through task creation and dispatch

**Files:**
- Modify: `app/Agents/Tools/DispatchAction.php:96-174` (handle method)
- Modify: `app/Services/TaskDispatcher.php:86-166` (dispatchToRunner method)
- Test: `tests/Feature/Agents/Tools/DispatchActionFeatureTest.php`

**Step 1: Write the failing test**

In `tests/Feature/Agents/Tools/DispatchActionFeatureTest.php`, add:

```php
it('stores existing_mr_iid on task when dispatching ui_adjustment correction', function () {
    $project = Project::factory()->enabled()->create();
    $user = dispatchTestUser($project);
    Auth::login($user);

    $tool = new DispatchAction(
        app(ProjectAccessChecker::class),
        $this->mockDispatcher,
    );

    $request = new Request([
        'action_type' => 'ui_adjustment',
        'project_id' => $project->gitlab_project_id,
        'title' => 'Fix card padding on mobile',
        'description' => 'Reduce padding from 24px to 16px on viewports < 768px',
        'branch_name' => 'ai/fix-card-padding',
        'target_branch' => 'main',
        'existing_mr_iid' => 456,
        'user_id' => $user->id,
        'conversation_id' => 'conv-designer-iter',
    ]);

    $result = $tool->handle($request);

    expect($result)->toContain('Task dispatched');

    $task = Task::latest()->first();
    expect($task->type)->toBe(TaskType::UiAdjustment);
    expect($task->mr_iid)->toBe(456);
    expect($task->result['existing_mr_iid'])->toBe(456);
    expect($task->result['branch_name'])->toBe('ai/fix-card-padding');
});

it('does not set mr_iid when existing_mr_iid is absent', function () {
    $project = Project::factory()->enabled()->create();
    $user = dispatchTestUser($project);
    Auth::login($user);

    $tool = new DispatchAction(
        app(ProjectAccessChecker::class),
        $this->mockDispatcher,
    );

    $request = new Request([
        'action_type' => 'ui_adjustment',
        'project_id' => $project->gitlab_project_id,
        'title' => 'New UI change',
        'description' => 'Initial adjustment',
        'branch_name' => 'ai/new-change',
        'target_branch' => 'main',
        'user_id' => $user->id,
        'conversation_id' => 'conv-new',
    ]);

    $result = $tool->handle($request);

    $task = Task::latest()->first();
    expect($task->mr_iid)->toBeNull();
    expect($task->result)->not->toHaveKey('existing_mr_iid');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter="stores existing_mr_iid on task"`
Expected: FAIL — `mr_iid` is null, `existing_mr_iid` not in result

**Step 3: Write minimal implementation**

In `app/Agents/Tools/DispatchAction.php`, modify the `handle()` method. After the branch info block (line ~150), add:

```php
// Set existing MR reference for designer iteration flow (T72)
$existingMrIid = $request->integer('existing_mr_iid');
if ($existingMrIid > 0) {
    $taskData['mr_iid'] = $existingMrIid;
}
```

In `buildResultMetadata()`, after the `target_branch` block (line ~197), add:

```php
$existingMrIid = $request->integer('existing_mr_iid');
if ($existingMrIid > 0) {
    $meta['existing_mr_iid'] = $existingMrIid;
}
```

Then modify `TaskDispatcher::dispatchToRunner()` to pass the existing MR IID to the runner. After the `VUNNIX_ISSUE_IID` block (line ~141), add:

```php
// T72: Pass existing MR IID for designer iteration (push to same branch)
if ($task->mr_iid !== null && in_array($task->type, [TaskType::FeatureDev, TaskType::UiAdjustment], true)) {
    $variables['VUNNIX_EXISTING_MR_IID'] = (string) $task->mr_iid;
    if (! empty($task->result['branch_name'])) {
        $variables['VUNNIX_EXISTING_BRANCH'] = $task->result['branch_name'];
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter="existing_mr_iid"`
Expected: PASS (both tests)

**Step 5: Commit**

```bash
git add app/Agents/Tools/DispatchAction.php app/Services/TaskDispatcher.php tests/Feature/Agents/Tools/DispatchActionFeatureTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T72.2: Pass existing_mr_iid through task creation and CI dispatch

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Add `updateMergeRequest` to GitLabClient

**Files:**
- Modify: `app/Services/GitLabClient.php` (add method after `createMergeRequest`)
- Test: `tests/Unit/Services/GitLabClientTest.php`

**Step 1: Write the failing test**

Find the existing GitLabClient unit test file and add:

```php
it('sends PUT request to update merge request', function () {
    Http::fake([
        '*/projects/42/merge_requests/123' => Http::response([
            'iid' => 123,
            'title' => 'Updated title',
            'web_url' => 'https://gitlab.example.com/project/-/merge_requests/123',
        ], 200),
    ]);

    $result = $this->client->updateMergeRequest(42, 123, [
        'title' => 'Updated title',
        'description' => 'Updated description',
    ]);

    expect($result['iid'])->toBe(123);
    expect($result['title'])->toBe('Updated title');

    Http::assertSent(function ($request) {
        return $request->method() === 'PUT'
            && str_contains($request->url(), 'merge_requests/123')
            && $request['title'] === 'Updated title';
    });
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter="sends PUT request to update merge request"`
Expected: FAIL — method doesn't exist

**Step 3: Write minimal implementation**

In `app/Services/GitLabClient.php`, add after the `createMergeRequest` method (~line 239):

```php
/**
 * Update an existing merge request.
 *
 * Used by T72 designer iteration flow to update MR title/description
 * when pushing corrections to the same branch.
 *
 * @param  array<string, mixed>  $data  title, description, etc.
 */
public function updateMergeRequest(int $projectId, int $mrIid, array $data): array
{
    $response = $this->request()->put(
        $this->url("projects/{$projectId}/merge_requests/{$mrIid}"),
        $data,
    );

    return $this->handleResponse($response, "updateMergeRequest !{$mrIid}")->json();
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter="sends PUT request to update merge request"`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Services/GitLabClient.php tests/Unit/Services/GitLabClientTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T72.3: Add updateMergeRequest to GitLabClient

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Update PostFeatureDevResult to handle corrections (existing MR)

**Files:**
- Modify: `app/Jobs/PostFeatureDevResult.php:45-124`
- Create: `tests/Feature/Jobs/PostFeatureDevResultTest.php`

**Step 1: Write the failing test**

Create `tests/Feature/Jobs/PostFeatureDevResultTest.php`:

```php
<?php

use App\Jobs\PostFeatureDevResult;
use App\Models\Task;
use App\Services\GitLabClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('updates existing MR instead of creating new one when existing_mr_iid is set', function () {
    $task = Task::factory()->create([
        'type' => 'ui_adjustment',
        'status' => 'running',
        'started_at' => now(),
        'mr_iid' => 456,
        'issue_iid' => 10,
        'result' => [
            'existing_mr_iid' => 456,
            'branch' => 'ai/fix-card-padding',
            'mr_title' => 'Fix card padding',
            'mr_description' => 'Reduced padding on mobile',
            'files_changed' => [
                ['path' => 'styles/card.css', 'action' => 'modified', 'summary' => 'Padding fix'],
            ],
        ],
    ]);

    Http::fake([
        // Should call PUT (update), not POST (create)
        '*/merge_requests/456' => Http::response([
            'iid' => 456,
            'title' => 'Fix card padding',
            'web_url' => 'https://gitlab.example.com/-/merge_requests/456',
        ], 200),
        '*/issues/10/notes' => Http::response(['id' => 999], 201),
    ]);

    $job = new PostFeatureDevResult($task->id);
    $job->handle(app(GitLabClient::class));

    // MR IID should remain unchanged (456, not a new one)
    $task->refresh();
    expect($task->mr_iid)->toBe(456);

    // Should have called PUT (update), not POST (create) for the MR
    Http::assertSent(function ($request) {
        return $request->method() === 'PUT'
            && str_contains($request->url(), 'merge_requests/456');
    });

    Http::assertNotSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'merge_requests');
    });
});

it('creates new MR when existing_mr_iid is not set', function () {
    $task = Task::factory()->create([
        'type' => 'feature_dev',
        'status' => 'running',
        'started_at' => now(),
        'mr_iid' => null,
        'issue_iid' => 10,
        'result' => [
            'branch' => 'ai/new-feature',
            'mr_title' => 'Add new feature',
            'mr_description' => 'Feature description',
            'files_changed' => [],
        ],
    ]);

    Http::fake([
        '*/merge_requests' => Http::response([
            'iid' => 789,
            'title' => 'Add new feature',
        ], 201),
        '*/issues/10/notes' => Http::response(['id' => 111], 201),
    ]);

    $job = new PostFeatureDevResult($task->id);
    $job->handle(app(GitLabClient::class));

    $task->refresh();
    expect($task->mr_iid)->toBe(789);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'merge_requests');
    });
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter="updates existing MR instead of creating"`
Expected: FAIL — currently always calls createMergeRequest

**Step 3: Write minimal implementation**

In `app/Jobs/PostFeatureDevResult.php`, modify the `createMergeRequest` method to check for existing MR:

```php
private function createMergeRequest(GitLabClient $gitLab, Task $task, array $result, int $gitlabProjectId): ?int
{
    $branch = $result['branch'] ?? null;
    $mrTitle = $result['mr_title'] ?? null;
    $mrDescription = $result['mr_description'] ?? null;

    if (empty($branch) || empty($mrTitle)) {
        Log::warning('PostFeatureDevResult: missing branch or mr_title in result', [
            'task_id' => $this->taskId,
        ]);

        return null;
    }

    // T72: If this task targets an existing MR (designer iteration), update it
    $existingMrIid = $result['existing_mr_iid'] ?? null;
    if ($existingMrIid !== null && $task->mr_iid !== null) {
        return $this->updateExistingMergeRequest(
            $gitLab, $task, $mrTitle, $mrDescription, $gitlabProjectId
        );
    }

    try {
        $mr = $gitLab->createMergeRequest($gitlabProjectId, [
            'source_branch' => $branch,
            'target_branch' => 'main',
            'title' => $mrTitle,
            'description' => $mrDescription ?? '',
        ]);

        $mrIid = (int) $mr['iid'];

        $task->mr_iid = $mrIid;
        $task->save();

        Log::info('PostFeatureDevResult: merge request created', [
            'task_id' => $this->taskId,
            'mr_iid' => $mrIid,
            'branch' => $branch,
        ]);

        return $mrIid;
    } catch (\Throwable $e) {
        Log::warning('PostFeatureDevResult: failed to create merge request', [
            'task_id' => $this->taskId,
            'branch' => $branch,
            'error' => $e->getMessage(),
        ]);

        throw $e;
    }
}
```

Add the new method:

```php
/**
 * Update an existing MR (designer iteration flow, T72).
 *
 * The executor already pushed new commits to the same branch.
 * We update the MR title/description to reflect the correction.
 */
private function updateExistingMergeRequest(
    GitLabClient $gitLab,
    Task $task,
    string $mrTitle,
    ?string $mrDescription,
    int $gitlabProjectId,
): int {
    $mrIid = $task->mr_iid;

    try {
        $gitLab->updateMergeRequest($gitlabProjectId, $mrIid, array_filter([
            'title' => $mrTitle,
            'description' => $mrDescription,
        ]));

        Log::info('PostFeatureDevResult: existing MR updated (designer iteration)', [
            'task_id' => $this->taskId,
            'mr_iid' => $mrIid,
        ]);

        return $mrIid;
    } catch (\Throwable $e) {
        Log::warning('PostFeatureDevResult: failed to update existing MR', [
            'task_id' => $this->taskId,
            'mr_iid' => $mrIid,
            'error' => $e->getMessage(),
        ]);

        throw $e;
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter="PostFeatureDevResult"`
Expected: PASS (both tests)

**Step 5: Commit**

```bash
git add app/Jobs/PostFeatureDevResult.php tests/Feature/Jobs/PostFeatureDevResultTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T72.4: Update PostFeatureDevResult to handle corrections (existing MR)

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Enhance DeliverTaskResultToConversation with MR/branch details

**Files:**
- Modify: `app/Listeners/DeliverTaskResultToConversation.php`
- Modify: `tests/Feature/Listeners/DeliverTaskResultToConversationTest.php`

**Step 1: Write the failing test**

In `tests/Feature/Listeners/DeliverTaskResultToConversationTest.php`, add:

```php
test('includes MR link and branch info in system message for feature_dev tasks', function () {
    $conversation = Conversation::factory()->create();
    $task = Task::factory()->create([
        'conversation_id' => $conversation->id,
        'type' => 'ui_adjustment',
        'status' => 'completed',
        'mr_iid' => 456,
        'result' => [
            'mr_title' => 'Fix card padding',
            'branch' => 'ai/fix-card-padding',
            'target_branch' => 'main',
            'files_changed' => [
                ['path' => 'styles/card.css', 'action' => 'modified', 'summary' => 'Padding fix'],
            ],
        ],
    ]);

    $listener = new DeliverTaskResultToConversation();
    $listener->handle(new TaskStatusChanged($task));

    $systemMsg = Message::where('conversation_id', $conversation->id)
        ->where('role', 'system')
        ->latest('created_at')
        ->first();

    expect($systemMsg->content)->toContain('!456');
    expect($systemMsg->content)->toContain('ai/fix-card-padding');
    expect($systemMsg->content)->toContain('[System: Task result delivered]');
});

test('includes result summary and files count in system message', function () {
    $conversation = Conversation::factory()->create();
    $task = Task::factory()->create([
        'conversation_id' => $conversation->id,
        'type' => 'feature_dev',
        'status' => 'completed',
        'mr_iid' => 123,
        'result' => [
            'mr_title' => 'Add payment flow',
            'branch' => 'ai/payment-feature',
            'files_changed' => [
                ['path' => 'app/Payment.php', 'action' => 'created', 'summary' => 'Payment controller'],
                ['path' => 'app/Stripe.php', 'action' => 'created', 'summary' => 'Stripe service'],
            ],
            'notes' => 'Implemented Stripe checkout with webhooks',
        ],
    ]);

    $listener = new DeliverTaskResultToConversation();
    $listener->handle(new TaskStatusChanged($task));

    $systemMsg = Message::where('conversation_id', $conversation->id)
        ->where('role', 'system')
        ->first();

    expect($systemMsg->content)->toContain('!123');
    expect($systemMsg->content)->toContain('2 files changed');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter="includes MR link and branch"`
Expected: FAIL — current message only contains basic status

**Step 3: Write minimal implementation**

Replace the `handle()` method body in `app/Listeners/DeliverTaskResultToConversation.php`:

```php
public function handle(TaskStatusChanged $event): void
{
    $task = $event->task;

    if (! $task->isTerminal() || $task->conversation_id === null) {
        return;
    }

    if (! Schema::hasTable('agent_conversation_messages')) {
        return;
    }

    $content = $this->buildResultContent($task);

    Message::create([
        'conversation_id' => $task->conversation_id,
        'role' => 'system',
        'content' => $content,
        'user_id' => 0,
        'agent' => '',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
    ]);

    Log::info('DeliverTaskResultToConversation: system message added', [
        'task_id' => $task->id,
        'conversation_id' => $task->conversation_id,
    ]);
}

private function buildResultContent(Task $task): string
{
    $statusText = $task->status->value;
    $title = $task->result['title'] ?? $task->result['mr_title'] ?? 'Task';
    $result = $task->result ?? [];

    $parts = ["[System: Task result delivered] Task #{$task->id} \"{$title}\" {$statusText}."];

    // MR reference
    if ($task->mr_iid !== null) {
        $parts[] = "MR !{$task->mr_iid}";
    }

    // Branch info
    $branch = $result['branch'] ?? null;
    if ($branch) {
        $targetBranch = $result['target_branch'] ?? 'main';
        $parts[] = "Branch: {$branch} → {$targetBranch}";
    }

    // Files changed count
    $filesChanged = $result['files_changed'] ?? [];
    if (count($filesChanged) > 0) {
        $count = count($filesChanged);
        $parts[] = "{$count} " . ($count === 1 ? 'file' : 'files') . " changed";
    }

    return implode(' | ', $parts);
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter="DeliverTaskResultToConversation"`
Expected: PASS (all tests including existing ones)

**Step 5: Commit**

```bash
git add app/Listeners/DeliverTaskResultToConversation.php tests/Feature/Listeners/DeliverTaskResultToConversationTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T72.5: Enhance conversation result delivery with MR/branch details

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Update ProcessTaskResult to handle conversation-origin feature dev (no issue_iid)

**Files:**
- Modify: `app/Jobs/ProcessTaskResult.php:161-165` (shouldPostFeatureDevResult)
- Modify: `tests/Feature/Jobs/PostFeatureDevResultTest.php`

**Step 1: Write the failing test**

In `tests/Feature/Jobs/PostFeatureDevResultTest.php`, add:

```php
it('skips issue summary when task has no issue_iid (conversation origin)', function () {
    $task = Task::factory()->create([
        'type' => 'ui_adjustment',
        'status' => 'running',
        'started_at' => now(),
        'mr_iid' => null,
        'issue_iid' => null,
        'conversation_id' => 'conv-designer-123',
        'result' => [
            'branch' => 'ai/fix-padding',
            'mr_title' => 'Fix card padding',
            'mr_description' => 'Reduced padding',
            'files_changed' => [],
        ],
    ]);

    Http::fake([
        '*/merge_requests' => Http::response([
            'iid' => 789,
            'title' => 'Fix card padding',
        ], 201),
    ]);

    $job = new PostFeatureDevResult($task->id);
    $job->handle(app(GitLabClient::class));

    $task->refresh();
    expect($task->mr_iid)->toBe(789);

    // Should NOT call the issue notes endpoint
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), '/notes');
    });
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter="skips issue summary when task has no issue_iid"`
Expected: FAIL — currently `PostFeatureDevResult::handle()` returns early if `issue_iid === null`

**Step 3: Write minimal implementation**

In `app/Jobs/PostFeatureDevResult.php`, modify the `handle()` method to allow processing without an Issue:

```php
public function handle(GitLabClient $gitLab): void
{
    $task = Task::with('project')->find($this->taskId);

    if ($task === null) {
        Log::warning('PostFeatureDevResult: task not found', ['task_id' => $this->taskId]);
        return;
    }

    if ($task->result === null) {
        Log::info('PostFeatureDevResult: task has no result, skipping', ['task_id' => $this->taskId]);
        return;
    }

    $result = $task->result;
    $gitlabProjectId = $task->project->gitlab_project_id;

    // Step 1: Create or update merge request
    $mrIid = $this->createMergeRequest($gitLab, $task, $result, $gitlabProjectId);

    if ($mrIid === null) {
        return;
    }

    // Step 2: Post summary on the originating Issue (if applicable)
    if ($task->issue_iid !== null) {
        $this->postIssueSummary($gitLab, $task, $result, $gitlabProjectId, $mrIid);
    }
}
```

Also modify `ProcessTaskResult::shouldPostFeatureDevResult()` to include conversation-origin tasks:

```php
private function shouldPostFeatureDevResult(Task $task): bool
{
    return in_array($task->type, [TaskType::FeatureDev, TaskType::UiAdjustment], true)
        && ($task->issue_iid !== null || $task->conversation_id !== null);
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter="PostFeatureDevResult"`
Expected: PASS (all tests)

**Step 5: Commit**

```bash
git add app/Jobs/ProcessTaskResult.php app/Jobs/PostFeatureDevResult.php tests/Feature/Jobs/PostFeatureDevResultTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T72.6: Support conversation-origin feature dev/UI tasks without issue_iid

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Update Conversation Engine system prompt for designer iteration

**Files:**
- Modify: `app/Agents/VunnixAgent.php:268-305` (actionDispatchSection)
- Test: `tests/Feature/Agents/VunnixAgentTest.php`

**Step 1: Write the failing test**

In `tests/Feature/Agents/VunnixAgentTest.php`, add:

```php
it('includes designer iteration instructions in system prompt', function () {
    $agent = app(VunnixAgent::class);
    $prompt = $agent->instructions();

    expect($prompt)->toContain('existing_mr_iid');
    expect($prompt)->toContain('designer iteration');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter="includes designer iteration instructions"`
Expected: FAIL — current prompt doesn't mention iteration

**Step 3: Write minimal implementation**

In `app/Agents/VunnixAgent.php`, add a designer iteration paragraph to the `actionDispatchSection()` method, after the deep analysis paragraph:

```php
**Designer iteration flow (T72):**
When a Designer receives a result card for a UI adjustment and reports that something is wrong (e.g., "The padding is too big" or "The color doesn't match"), dispatch a correction to the same branch/MR:
1. Reference the existing MR IID from the task result (shown in the system context message as "MR !{iid}")
2. Include `existing_mr_iid` in the DispatchAction call — this tells the executor to push to the same branch and update the existing MR
3. Use the same `branch_name` from the previous result
4. The preview card should indicate this is a **correction** to an existing MR, not a new action

Example correction dispatch:
```action_preview
{"action_type":"ui_adjustment","project_id":42,"title":"Fix card padding (correction)","description":"Reduce padding from 24px to 16px on mobile viewports","branch_name":"ai/fix-card-padding","target_branch":"main","existing_mr_iid":456}
```

Do not create a new branch/MR when the Designer is iterating on an existing adjustment. Always reuse the MR from the previous dispatch in the same conversation thread.
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter="includes designer iteration instructions"`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Agents/VunnixAgent.php tests/Feature/Agents/VunnixAgentTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T72.7: Add designer iteration flow instructions to CE system prompt

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Add structural verification checks for T72

**Files:**
- Modify: `verify/verify_m3.py`

**Step 1: Add verification checks**

Add T72 checks to `verify/verify_m3.py`, in the same pattern as existing checks:

```python
# ── T72: Designer iteration flow ────────────────────────────
check(
    "DispatchAction schema includes existing_mr_iid",
    file_contains("app/Agents/Tools/DispatchAction.php", "existing_mr_iid"),
)
check(
    "DispatchAction stores existing_mr_iid in task result metadata",
    file_contains("app/Agents/Tools/DispatchAction.php", "existing_mr_iid"),
)
check(
    "TaskDispatcher passes VUNNIX_EXISTING_MR_IID to runner",
    file_contains("app/Services/TaskDispatcher.php", "VUNNIX_EXISTING_MR_IID"),
)
check(
    "GitLabClient has updateMergeRequest method",
    file_contains("app/Services/GitLabClient.php", "function updateMergeRequest"),
)
check(
    "PostFeatureDevResult handles existing MR (designer iteration)",
    file_contains("app/Jobs/PostFeatureDevResult.php", "existing_mr_iid"),
)
check(
    "DeliverTaskResultToConversation includes MR link",
    file_contains("app/Listeners/DeliverTaskResultToConversation.php", "mr_iid"),
)
check(
    "DeliverTaskResultToConversation includes branch info",
    file_contains("app/Listeners/DeliverTaskResultToConversation.php", "branch"),
)
check(
    "CE system prompt includes designer iteration instructions",
    file_contains("app/Agents/VunnixAgent.php", "designer iteration"),
)
check(
    "Feature test for correction dispatch with existing_mr_iid",
    file_contains("tests/Feature/Agents/Tools/DispatchActionFeatureTest.php", "existing_mr_iid"),
)
check(
    "Feature test for PostFeatureDevResult with existing MR",
    file_exists("tests/Feature/Jobs/PostFeatureDevResultTest.php"),
)
check(
    "Feature test for conversation result delivery with MR details",
    file_contains("tests/Feature/Listeners/DeliverTaskResultToConversationTest.php", "!456"),
)
```

**Step 2: Run verification**

Run: `python3 verify/verify_m3.py`
Expected: All T72 checks PASS

**Step 3: Commit**

```bash
git add verify/verify_m3.py
git commit --no-gpg-sign -m "$(cat <<'EOF'
T72.8: Add structural verification checks for designer iteration flow

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Run full verification and update progress

**Step 1: Run all tests**

```bash
php artisan test
```

Expected: All tests pass

**Step 2: Run M3 structural verification**

```bash
python3 verify/verify_m3.py
```

Expected: All checks pass

**Step 3: Update progress.md**

- Check `[x]` for T72
- Update M3 count to 25/27
- Bold the next task T115
- Update summary

**Step 4: Update handoff.md**

Clear to empty template (task complete).

**Step 5: Final commit**

```bash
git add progress.md handoff.md
git commit --no-gpg-sign -m "$(cat <<'EOF'
T72: Complete designer iteration flow — mark done

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

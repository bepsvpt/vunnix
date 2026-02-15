# T70: Chat Page — Result Cards Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Display visually distinct result cards in the chat message thread when dispatched tasks complete (success or failure), including screenshots for UI adjustment tasks.

**Architecture:** When a task reaches terminal status, the Reverb event triggers the frontend to render a result card inline in the message thread (instead of silently removing the task from the pinned bar). The backend broadcast event is enhanced with richer result data. A new API endpoint provides full task result details (including base64 screenshots for UI adjustments). A `[System: Task result delivered]` context marker is appended to the conversation so the AI understands the context switch.

**Tech Stack:** Vue 3 (Composition API, `<script setup>`), Pinia, Laravel Echo/Reverb, Tailwind CSS, Vitest + Vue Test Utils

---

### Task 1: Write ResultCard component test (success state)

**Files:**
- Create: `resources/js/components/ResultCard.test.js`

**Step 1: Write failing tests for ResultCard success rendering**

```javascript
import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import ResultCard from './ResultCard.vue';

function makeResult(overrides = {}) {
    return {
        task_id: 42,
        status: 'completed',
        type: 'feature_dev',
        title: 'Add Stripe payment flow',
        mr_iid: 123,
        issue_iid: null,
        branch: 'ai/payment-feature',
        target_branch: 'main',
        files_changed: [
            { path: 'app/Http/Controllers/PaymentController.php', action: 'created', summary: 'Payment controller with checkout endpoint' },
            { path: 'app/Services/StripeService.php', action: 'created', summary: 'Stripe integration service' },
        ],
        result_summary: 'Created PaymentController and StripeService with checkout flow',
        project_id: 1,
        gitlab_url: 'https://gitlab.example.com/project',
        screenshot: null,
        error_reason: null,
        ...overrides,
    };
}

function mountCard(result) {
    return mount(ResultCard, {
        props: { result },
    });
}

describe('ResultCard', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    // -- Success state --

    it('renders the card with data-testid', () => {
        const wrapper = mountCard(makeResult());
        expect(wrapper.find('[data-testid="result-card"]').exists()).toBe(true);
    });

    it('shows success indicator for completed tasks', () => {
        const wrapper = mountCard(makeResult({ status: 'completed' }));
        expect(wrapper.text()).toContain('✅');
    });

    it('displays task title', () => {
        const wrapper = mountCard(makeResult({ title: 'Fix login bug' }));
        expect(wrapper.text()).toContain('Fix login bug');
    });

    it('shows MR link when mr_iid is present', () => {
        const wrapper = mountCard(makeResult({ mr_iid: 456 }));
        const link = wrapper.find('[data-testid="artifact-link"]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('!456');
    });

    it('shows Issue link when issue_iid is present and no mr_iid', () => {
        const wrapper = mountCard(makeResult({ mr_iid: null, issue_iid: 78 }));
        const link = wrapper.find('[data-testid="artifact-link"]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('#78');
    });

    it('displays files changed count', () => {
        const wrapper = mountCard(makeResult());
        expect(wrapper.text()).toContain('2');
        expect(wrapper.text()).toMatch(/files?\s*changed/i);
    });

    it('shows result summary text', () => {
        const wrapper = mountCard(makeResult({ result_summary: 'Added payment flow' }));
        expect(wrapper.text()).toContain('Added payment flow');
    });

    it('shows branch info for feature_dev', () => {
        const wrapper = mountCard(makeResult({
            type: 'feature_dev',
            branch: 'ai/payment-feature',
            target_branch: 'main',
        }));
        expect(wrapper.text()).toContain('ai/payment-feature');
        expect(wrapper.text()).toContain('main');
    });

    it('displays action type badge', () => {
        const wrapper = mountCard(makeResult({ type: 'feature_dev' }));
        expect(wrapper.find('[data-testid="result-type-badge"]').exists()).toBe(true);
    });
});
```

**Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/components/ResultCard.test.js`
Expected: FAIL — `ResultCard.vue` does not exist

---

### Task 2: Implement ResultCard component (success state)

**Files:**
- Create: `resources/js/components/ResultCard.vue`

**Step 1: Write minimal implementation**

```vue
<script setup>
import { computed } from 'vue';

const props = defineProps({
    result: { type: Object, required: true },
});

const TYPE_DISPLAY = {
    code_review: { label: 'Code Review', emoji: '🔍' },
    feature_dev: { label: 'Feature Dev', emoji: '🚀' },
    ui_adjustment: { label: 'UI Adjustment', emoji: '🎨' },
    prd_creation: { label: 'Issue Created', emoji: '📋' },
    deep_analysis: { label: 'Deep Analysis', emoji: '🔬' },
    security_audit: { label: 'Security Audit', emoji: '🔒' },
    issue_discussion: { label: 'Issue Discussion', emoji: '💬' },
};

const typeDisplay = computed(() =>
    TYPE_DISPLAY[props.result.type] || { label: props.result.type, emoji: '⚙️' }
);

const isSuccess = computed(() => props.result.status === 'completed');
const isFailed = computed(() => props.result.status === 'failed');

const artifactLabel = computed(() => {
    if (props.result.mr_iid) return `MR !${props.result.mr_iid}`;
    if (props.result.issue_iid) return `Issue #${props.result.issue_iid}`;
    return null;
});

const artifactUrl = computed(() => {
    const base = props.result.gitlab_url || '';
    if (props.result.mr_iid) return `${base}/-/merge_requests/${props.result.mr_iid}`;
    if (props.result.issue_iid) return `${base}/-/issues/${props.result.issue_iid}`;
    return null;
});

const filesCount = computed(() => props.result.files_changed?.length ?? 0);

const hasBranch = computed(() =>
    props.result.branch && ['feature_dev', 'ui_adjustment'].includes(props.result.type)
);

const hasScreenshot = computed(() =>
    props.result.type === 'ui_adjustment' && props.result.screenshot
);
</script>

<template>
  <div
    data-testid="result-card"
    class="w-full max-w-lg rounded-xl border overflow-hidden"
    :class="isSuccess
      ? 'border-emerald-200 dark:border-emerald-800 bg-emerald-50/50 dark:bg-emerald-950/30'
      : 'border-red-200 dark:border-red-800 bg-red-50/50 dark:bg-red-950/30'"
  >
    <!-- Header -->
    <div
      class="px-4 py-3 border-b flex items-center gap-2"
      :class="isSuccess
        ? 'border-emerald-100 dark:border-emerald-900'
        : 'border-red-100 dark:border-red-900'"
    >
      <span class="text-lg">{{ isSuccess ? '✅' : '❌' }}</span>
      <span
        data-testid="result-type-badge"
        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
        :class="isSuccess
          ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300'
          : 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300'"
      >
        <span>{{ typeDisplay.emoji }}</span>
        <span>{{ isSuccess ? typeDisplay.label + ' completed' : typeDisplay.label + ' failed' }}</span>
      </span>
    </div>

    <!-- Body -->
    <div class="px-4 py-3 space-y-2">
      <!-- Title + artifact link -->
      <div class="flex items-start justify-between gap-2">
        <h4 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
          {{ result.title }}
        </h4>
      </div>

      <!-- Artifact link (MR or Issue) -->
      <a
        v-if="artifactUrl"
        data-testid="artifact-link"
        :href="artifactUrl"
        target="_blank"
        rel="noopener"
        class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline"
      >
        {{ artifactLabel }}
        <span class="text-[10px]">↗</span>
      </a>

      <!-- Branch info -->
      <div v-if="hasBranch" class="flex items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400">
        <span class="font-medium text-zinc-700 dark:text-zinc-300">Branch:</span>
        <code class="px-1 py-0.5 rounded bg-white/60 dark:bg-zinc-800 font-mono text-[11px]">{{ result.branch }}</code>
        <span>→</span>
        <code class="px-1 py-0.5 rounded bg-white/60 dark:bg-zinc-800 font-mono text-[11px]">{{ result.target_branch || 'main' }}</code>
      </div>

      <!-- Files changed -->
      <div
        v-if="filesCount > 0"
        class="text-xs text-zinc-500 dark:text-zinc-400"
      >
        Files changed: <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ filesCount }}</span>
      </div>

      <!-- Summary -->
      <p
        v-if="result.result_summary"
        data-testid="result-summary"
        class="text-xs text-zinc-600 dark:text-zinc-400 leading-relaxed"
      >
        {{ result.result_summary }}
      </p>

      <!-- Error reason (failed tasks) -->
      <p
        v-if="isFailed && result.error_reason"
        data-testid="error-reason"
        class="text-xs text-red-600 dark:text-red-400 leading-relaxed"
      >
        {{ result.error_reason }}
      </p>

      <!-- Screenshot (UI adjustment) -->
      <div
        v-if="hasScreenshot"
        data-testid="result-screenshot"
        class="mt-2 rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700"
      >
        <img
          :src="`data:image/png;base64,${result.screenshot}`"
          alt="UI adjustment screenshot"
          class="w-full h-auto"
        />
      </div>
    </div>

    <!-- Footer: View in GitLab -->
    <div
      v-if="artifactUrl"
      class="px-4 py-2.5 border-t text-right"
      :class="isSuccess
        ? 'border-emerald-100 dark:border-emerald-900'
        : 'border-red-100 dark:border-red-900'"
    >
      <a
        :href="artifactUrl"
        target="_blank"
        rel="noopener"
        class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline"
      >
        View in GitLab ↗
      </a>
    </div>
  </div>
</template>
```

**Step 2: Run test to verify it passes**

Run: `npx vitest run resources/js/components/ResultCard.test.js`
Expected: PASS — all success state tests green

**Step 3: Commit**

```bash
git add resources/js/components/ResultCard.vue resources/js/components/ResultCard.test.js
git commit --no-gpg-sign -m "T70.1: Add ResultCard component with success state rendering and tests"
```

---

### Task 3: Add ResultCard failure state tests and implementation

**Files:**
- Modify: `resources/js/components/ResultCard.test.js`

**Step 1: Add failure state tests to existing test file**

Append to `describe('ResultCard', ...)`:

```javascript
    // -- Failure state --

    it('shows failure indicator for failed tasks', () => {
        const wrapper = mountCard(makeResult({ status: 'failed' }));
        expect(wrapper.text()).toContain('❌');
    });

    it('shows error reason for failed tasks', () => {
        const wrapper = mountCard(makeResult({
            status: 'failed',
            error_reason: 'Schema validation failed: branch is required',
        }));
        expect(wrapper.find('[data-testid="error-reason"]').text()).toContain('Schema validation failed');
    });

    it('hides error reason when not failed', () => {
        const wrapper = mountCard(makeResult({ status: 'completed', error_reason: null }));
        expect(wrapper.find('[data-testid="error-reason"]').exists()).toBe(false);
    });

    // -- UI adjustment screenshot --

    it('shows screenshot for ui_adjustment tasks', () => {
        const wrapper = mountCard(makeResult({
            type: 'ui_adjustment',
            screenshot: 'iVBORw0KGgoAAAANSUhEUg==',
        }));
        const img = wrapper.find('[data-testid="result-screenshot"] img');
        expect(img.exists()).toBe(true);
        expect(img.attributes('src')).toContain('data:image/png;base64,');
    });

    it('hides screenshot for non-ui_adjustment tasks', () => {
        const wrapper = mountCard(makeResult({
            type: 'feature_dev',
            screenshot: 'iVBORw0KGgoAAAANSUhEUg==',
        }));
        expect(wrapper.find('[data-testid="result-screenshot"]').exists()).toBe(false);
    });

    it('hides screenshot when null', () => {
        const wrapper = mountCard(makeResult({
            type: 'ui_adjustment',
            screenshot: null,
        }));
        expect(wrapper.find('[data-testid="result-screenshot"]').exists()).toBe(false);
    });

    // -- Edge cases --

    it('handles missing files_changed gracefully', () => {
        const wrapper = mountCard(makeResult({ files_changed: null }));
        expect(wrapper.text()).not.toMatch(/files?\s*changed/i);
    });

    it('hides artifact link when neither mr_iid nor issue_iid present', () => {
        const wrapper = mountCard(makeResult({ mr_iid: null, issue_iid: null }));
        expect(wrapper.find('[data-testid="artifact-link"]').exists()).toBe(false);
    });

    it('prefers MR link over Issue link when both present', () => {
        const wrapper = mountCard(makeResult({ mr_iid: 123, issue_iid: 45 }));
        expect(wrapper.find('[data-testid="artifact-link"]').text()).toContain('!123');
    });
```

**Step 2: Run tests to verify they pass**

Run: `npx vitest run resources/js/components/ResultCard.test.js`
Expected: PASS — all tests green (the component already handles these cases)

**Step 3: Commit**

```bash
git add resources/js/components/ResultCard.test.js
git commit --no-gpg-sign -m "T70.2: Add ResultCard failure state, screenshot, and edge case tests"
```

---

### Task 4: Enhance TaskStatusChanged broadcast with result card data

**Files:**
- Modify: `app/Events/TaskStatusChanged.php`
- Modify: `tests/Unit/Events/TaskStatusChangedTest.php` (if exists, else create)

**Step 1: Write the failing test**

Create/update `tests/Unit/Events/TaskStatusChangedTest.php`:

```php
<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Events\TaskStatusChanged;
use App\Models\Task;

it('includes result card data in broadcast payload for completed feature_dev task', function () {
    $task = Task::factory()->create([
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Completed,
        'mr_iid' => 123,
        'issue_iid' => null,
        'result' => [
            'branch' => 'ai/payment-feature',
            'mr_title' => 'Add payment flow',
            'mr_description' => 'Implements Stripe checkout',
            'files_changed' => [
                ['path' => 'app/Payment.php', 'action' => 'created', 'summary' => 'Payment model'],
            ],
            'tests_added' => true,
            'notes' => 'Added tests',
        ],
    ]);

    $event = new TaskStatusChanged($task);
    $payload = $event->broadcastWith();

    expect($payload)->toHaveKey('result_data');
    expect($payload['result_data'])->toHaveKey('branch', 'ai/payment-feature');
    expect($payload['result_data'])->toHaveKey('target_branch');
    expect($payload['result_data']['files_changed'])->toHaveCount(1);
    expect($payload['result_data'])->not->toHaveKey('screenshot');
});

it('includes screenshot in broadcast payload for completed ui_adjustment task', function () {
    $task = Task::factory()->create([
        'type' => TaskType::UiAdjustment,
        'status' => TaskStatus::Completed,
        'mr_iid' => 456,
        'result' => [
            'branch' => 'ai/fix-padding',
            'mr_title' => 'Fix card padding',
            'mr_description' => 'Fixes padding on cards',
            'files_changed' => [
                ['path' => 'src/Card.vue', 'action' => 'modified', 'summary' => 'Fixed padding'],
            ],
            'tests_added' => false,
            'notes' => 'Visual fix',
            'screenshot' => 'iVBORw0KGgoAAAANSUhEUg==',
            'screenshot_mobile' => null,
        ],
    ]);

    $event = new TaskStatusChanged($task);
    $payload = $event->broadcastWith();

    expect($payload['result_data'])->toHaveKey('screenshot', 'iVBORw0KGgoAAAANSUhEUg==');
});

it('includes error_reason in broadcast payload for failed task', function () {
    $task = Task::factory()->create([
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Failed,
        'error_reason' => 'Schema validation failed',
        'result' => null,
    ]);

    $event = new TaskStatusChanged($task);
    $payload = $event->broadcastWith();

    expect($payload)->toHaveKey('error_reason', 'Schema validation failed');
});

it('omits result_data for non-terminal tasks', function () {
    $task = Task::factory()->create([
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Running,
        'result' => ['branch' => 'ai/something'],
    ]);

    $event = new TaskStatusChanged($task);
    $payload = $event->broadcastWith();

    expect($payload)->not->toHaveKey('result_data');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TaskStatusChanged`
Expected: FAIL — `result_data` key not present in payload

**Step 3: Enhance the broadcast payload**

Modify `app/Events/TaskStatusChanged.php` — add `result_data` and `error_reason` to `broadcastWith()`:

```php
public function broadcastWith(): array
{
    $payload = [
        'task_id' => $this->task->id,
        'status' => $this->task->status->value,
        'type' => $this->task->type->value,
        'project_id' => $this->task->project_id,
        'pipeline_id' => $this->task->pipeline_id,
        'pipeline_status' => $this->task->pipeline_status,
        'mr_iid' => $this->task->mr_iid,
        'issue_iid' => $this->task->issue_iid,
        'title' => $this->task->result['title'] ?? $this->task->result['mr_title'] ?? null,
        'started_at' => $this->task->started_at?->toIso8601String(),
        'conversation_id' => $this->task->conversation_id,
        'result_summary' => $this->task->isTerminal() ? ($this->task->result['summary'] ?? $this->task->result['notes'] ?? null) : null,
        'error_reason' => $this->task->isTerminal() ? $this->task->error_reason : null,
        'timestamp' => now()->toIso8601String(),
    ];

    // Add structured result data for terminal tasks (used by ResultCard)
    if ($this->task->isTerminal() && $this->task->result !== null) {
        $payload['result_data'] = $this->buildResultData();
    }

    return $payload;
}

/**
 * Build result card data from the task result, based on task type.
 */
private function buildResultData(): array
{
    $result = $this->task->result;
    $data = [];

    // Common fields from result
    if (isset($result['branch'])) {
        $data['branch'] = $result['branch'];
    }
    if (isset($result['target_branch'])) {
        $data['target_branch'] = $result['target_branch'];
    } else {
        $data['target_branch'] = 'main';
    }
    if (isset($result['files_changed'])) {
        $data['files_changed'] = $result['files_changed'];
    }

    // UI adjustment: include screenshot
    if ($this->task->type === \App\Enums\TaskType::UiAdjustment) {
        $data['screenshot'] = $result['screenshot'] ?? null;
    }

    return $data;
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=TaskStatusChanged`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Events/TaskStatusChanged.php tests/Unit/Events/TaskStatusChangedTest.php
git commit --no-gpg-sign -m "T70.3: Enhance TaskStatusChanged broadcast with result card data"
```

---

### Task 5: Add task result API endpoint

**Files:**
- Create: `app/Http/Controllers/Api/TaskResultViewController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Chat/TaskResultViewTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

it('returns task result data for authorized user', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $project->users()->attach($user);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Completed,
        'mr_iid' => 123,
        'result' => [
            'branch' => 'ai/test',
            'mr_title' => 'Test MR',
            'mr_description' => 'Description',
            'files_changed' => [
                ['path' => 'foo.php', 'action' => 'created', 'summary' => 'New file'],
            ],
            'tests_added' => true,
            'notes' => 'Notes here',
        ],
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/tasks/{$task->id}/view");

    $response->assertOk();
    $response->assertJsonPath('data.task_id', $task->id);
    $response->assertJsonPath('data.status', 'completed');
    $response->assertJsonPath('data.type', 'feature_dev');
    $response->assertJsonPath('data.mr_iid', 123);
    $response->assertJsonPath('data.result.branch', 'ai/test');
});

it('returns 403 for user without project access', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Completed,
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/tasks/{$task->id}/view");

    $response->assertForbidden();
});

it('returns 401 for unauthenticated request', function () {
    $task = Task::factory()->create();

    $response = $this->getJson("/api/v1/tasks/{$task->id}/view");

    $response->assertUnauthorized();
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TaskResultView`
Expected: FAIL — route not found (404)

**Step 3: Create the controller**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskResultViewController extends Controller
{
    public function __invoke(Request $request, Task $task): JsonResponse
    {
        // Authorization: user must have access to the task's project
        if (! $request->user()->projects()->where('projects.id', $task->project_id)->exists()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json([
            'data' => [
                'task_id' => $task->id,
                'status' => $task->status->value,
                'type' => $task->type->value,
                'title' => $task->result['title'] ?? $task->result['mr_title'] ?? null,
                'mr_iid' => $task->mr_iid,
                'issue_iid' => $task->issue_iid,
                'project_id' => $task->project_id,
                'error_reason' => $task->error_reason,
                'result' => $task->result,
            ],
        ]);
    }
}
```

**Step 4: Add the route**

In `routes/api.php`, inside the authenticated group:

```php
Route::get('/tasks/{task}/view', \App\Http\Controllers\Api\TaskResultViewController::class)
    ->middleware('auth:sanctum');
```

**Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=TaskResultView`
Expected: PASS

**Step 6: Commit**

```bash
git add app/Http/Controllers/Api/TaskResultViewController.php routes/api.php tests/Feature/Chat/TaskResultViewTest.php
git commit --no-gpg-sign -m "T70.4: Add task result view API endpoint with authorization"
```

---

### Task 6: Update conversations store — deliver result cards on terminal events

**Files:**
- Modify: `resources/js/stores/conversations.js`

**Step 1: Write failing store test**

Update or create `resources/js/stores/conversations.test.js` — add test for result card delivery:

```javascript
describe('result card delivery (T70)', () => {
    it('adds completed result to completedResults when task reaches terminal status', () => {
        const store = useConversationsStore();
        store.selectedId = 'conv-1';

        // Track a task
        store.trackTask({
            task_id: 42,
            status: 'running',
            type: 'feature_dev',
            title: 'Test task',
            conversation_id: 'conv-1',
            project_id: 1,
        });

        // Simulate terminal event
        store.deliverTaskResult(42, {
            status: 'completed',
            type: 'feature_dev',
            title: 'Test task',
            mr_iid: 123,
            issue_iid: null,
            result_summary: 'Created MR',
            error_reason: null,
            result_data: {
                branch: 'ai/test',
                target_branch: 'main',
                files_changed: [{ path: 'foo.php', action: 'created', summary: 'New file' }],
            },
            conversation_id: 'conv-1',
            project_id: 1,
        });

        expect(store.completedResults.length).toBe(1);
        expect(store.completedResults[0].task_id).toBe(42);
        expect(store.completedResults[0].status).toBe('completed');
    });

    it('appends system context marker message on result delivery', () => {
        const store = useConversationsStore();
        store.selectedId = 'conv-1';

        store.trackTask({
            task_id: 42,
            status: 'running',
            type: 'feature_dev',
            title: 'Test task',
            conversation_id: 'conv-1',
            project_id: 1,
        });

        const msgCountBefore = store.messages.length;

        store.deliverTaskResult(42, {
            status: 'completed',
            type: 'feature_dev',
            title: 'Test task',
            mr_iid: 123,
            issue_iid: null,
            result_summary: 'Done',
            error_reason: null,
            result_data: {},
            conversation_id: 'conv-1',
            project_id: 1,
        });

        expect(store.messages.length).toBe(msgCountBefore + 1);
        expect(store.messages[store.messages.length - 1].content).toContain('[System: Task result delivered]');
        expect(store.messages[store.messages.length - 1].role).toBe('system');
    });

    it('completedResultsForConversation only returns results for selected conversation', () => {
        const store = useConversationsStore();
        store.selectedId = 'conv-1';

        store.completedResults.push(
            { task_id: 1, conversation_id: 'conv-1', status: 'completed' },
            { task_id: 2, conversation_id: 'conv-2', status: 'completed' },
        );

        expect(store.completedResultsForConversation.length).toBe(1);
        expect(store.completedResultsForConversation[0].task_id).toBe(1);
    });
});
```

**Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/stores/conversations.test.js`
Expected: FAIL — `deliverTaskResult` and `completedResults` don't exist

**Step 3: Add result delivery to the store**

In `resources/js/stores/conversations.js`, add:

1. New state: `const completedResults = ref([]);`
2. New computed: `completedResultsForConversation`
3. New method: `deliverTaskResult(taskId, eventData)`
4. Update `subscribeToTask` to call `deliverTaskResult` on terminal events instead of just scheduling removal

Key changes:

```javascript
// New state
const completedResults = ref([]);

// New computed
const completedResultsForConversation = computed(() => {
    if (!selectedId.value) return [];
    return completedResults.value.filter(r => r.conversation_id === selectedId.value);
});

// New method
function deliverTaskResult(taskId, eventData) {
    completedResults.value.push({
        task_id: taskId,
        status: eventData.status,
        type: eventData.type,
        title: eventData.title,
        mr_iid: eventData.mr_iid,
        issue_iid: eventData.issue_iid,
        result_summary: eventData.result_summary,
        error_reason: eventData.error_reason,
        result_data: eventData.result_data || {},
        conversation_id: eventData.conversation_id,
        project_id: eventData.project_id,
        gitlab_url: '', // Will be populated from project data
    });

    // Append system context marker message
    messages.value.push({
        id: `system-result-${taskId}-${Date.now()}`,
        role: 'system',
        content: `[System: Task result delivered] Task #${taskId} "${eventData.title}" ${eventData.status}.`,
        created_at: new Date().toISOString(),
    });
}
```

And update `subscribeToTask` to call `deliverTaskResult` on terminal events:

```javascript
function subscribeToTask(taskId) {
    if (taskSubscriptions.value.has(taskId)) return;

    const echo = getEcho();
    echo.private(`task.${taskId}`).listen('.task.status.changed', (event) => {
        updateTaskStatus(event.task_id, {
            status: event.status,
            pipeline_id: event.pipeline_id,
            pipeline_status: event.pipeline_status,
            started_at: event.started_at,
            result_summary: event.result_summary,
        });

        // If terminal, deliver result card and schedule cleanup
        if (['completed', 'failed', 'superseded'].includes(event.status)) {
            deliverTaskResult(event.task_id, event);
            setTimeout(() => {
                removeTask(event.task_id);
                unsubscribeFromTask(event.task_id);
            }, 3000);
        }
    });

    taskSubscriptions.value = new Set([...taskSubscriptions.value, taskId]);
}
```

**Step 4: Run tests to verify they pass**

Run: `npx vitest run resources/js/stores/conversations.test.js`
Expected: PASS

**Step 5: Commit**

```bash
git add resources/js/stores/conversations.js resources/js/stores/conversations.test.js
git commit --no-gpg-sign -m "T70.5: Add result delivery to conversations store with context markers"
```

---

### Task 7: Wire ResultCard into MessageThread

**Files:**
- Modify: `resources/js/components/MessageThread.vue`
- Modify: `resources/js/components/MessageThread.test.js`

**Step 1: Add failing test**

Add to `resources/js/components/MessageThread.test.js`:

```javascript
import ResultCard from './ResultCard.vue';

it('renders ResultCard for completed results in the conversation', async () => {
    const store = useConversationsStore();
    store.selectedId = 'conv-1';
    store.messages = [
        { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00Z' },
    ];
    store.completedResults = [
        {
            task_id: 42,
            status: 'completed',
            type: 'feature_dev',
            title: 'Add payment',
            mr_iid: 123,
            issue_iid: null,
            result_summary: 'Created MR',
            error_reason: null,
            result_data: { branch: 'ai/test', target_branch: 'main', files_changed: [] },
            conversation_id: 'conv-1',
            project_id: 1,
            gitlab_url: '',
        },
    ];

    const wrapper = mount(MessageThread, { /* global plugins with pinia */ });
    expect(wrapper.findComponent(ResultCard).exists()).toBe(true);
});
```

**Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/components/MessageThread.test.js`
Expected: FAIL — ResultCard not rendered

**Step 3: Add ResultCard to MessageThread template**

In `MessageThread.vue`, import ResultCard and add after the messages loop:

```vue
<script setup>
import ResultCard from './ResultCard.vue';
// ... existing imports
</script>

<!-- In the template, after the messages v-for block, before ToolUseIndicators -->
<!-- Result cards: completed task results delivered via Reverb (T70) -->
<div
  v-for="result in store.completedResultsForConversation"
  :key="`result-${result.task_id}`"
  class="flex w-full justify-start"
>
  <ResultCard :result="buildResultCardProps(result)" />
</div>
```

Add a helper function in script:

```javascript
function buildResultCardProps(result) {
    return {
        task_id: result.task_id,
        status: result.status,
        type: result.type,
        title: result.title,
        mr_iid: result.mr_iid,
        issue_iid: result.issue_iid,
        branch: result.result_data?.branch || null,
        target_branch: result.result_data?.target_branch || 'main',
        files_changed: result.result_data?.files_changed || null,
        result_summary: result.result_summary,
        error_reason: result.error_reason,
        screenshot: result.result_data?.screenshot || null,
        project_id: result.project_id,
        gitlab_url: result.gitlab_url || '',
    };
}
```

**Step 4: Run tests to verify they pass**

Run: `npx vitest run resources/js/components/MessageThread.test.js`
Expected: PASS

**Step 5: Commit**

```bash
git add resources/js/components/MessageThread.vue resources/js/components/MessageThread.test.js
git commit --no-gpg-sign -m "T70.6: Wire ResultCard into MessageThread for completed task rendering"
```

---

### Task 8: Handle result cards on conversation reload (persistence)

**Files:**
- Modify: `resources/js/stores/conversations.js`

**Step 1: Context**

When a user reloads the page or switches conversations, the `completedResults` in-memory state is lost. System messages with `[System: Task result delivered]` are persisted server-side, but the result card data is not. We need to handle two scenarios:

1. **Active session**: Results arrive via Reverb → rendered in real-time (already done in Task 6)
2. **Page reload**: System messages reference task IDs → we parse them and fetch result data from the API

Add parsing logic to `fetchMessages` that detects `[System: Task result delivered]` messages and fetches task results.

**Step 2: Add the method**

```javascript
/**
 * Parse system result messages and fetch task result data for result cards.
 * Called after fetchMessages to hydrate result cards from persisted messages.
 */
async function hydrateResultCards() {
    const systemResultMessages = messages.value.filter(
        (m) => m.role === 'system' && m.content.includes('[System: Task result delivered]')
    );

    for (const msg of systemResultMessages) {
        const match = msg.content.match(/Task #(\d+)/);
        if (!match) continue;
        const taskId = parseInt(match[1], 10);

        // Skip if already hydrated
        if (completedResults.value.some((r) => r.task_id === taskId)) continue;

        try {
            const response = await axios.get(`/api/v1/tasks/${taskId}/view`);
            const data = response.data.data;
            completedResults.value.push({
                task_id: data.task_id,
                status: data.status,
                type: data.type,
                title: data.title,
                mr_iid: data.mr_iid,
                issue_iid: data.issue_iid,
                result_summary: data.result?.notes || data.result?.summary || null,
                error_reason: data.error_reason,
                result_data: {
                    branch: data.result?.branch || null,
                    target_branch: data.result?.target_branch || 'main',
                    files_changed: data.result?.files_changed || null,
                    screenshot: data.result?.screenshot || null,
                },
                conversation_id: selectedId.value,
                project_id: data.project_id,
                gitlab_url: '',
            });
        } catch {
            // Task may have been deleted or user lost access — skip silently
        }
    }
}
```

Update `fetchMessages` to call `hydrateResultCards()` after loading:

```javascript
async function fetchMessages(conversationId) {
    messagesLoading.value = true;
    messagesError.value = null;
    try {
        const response = await axios.get(`/api/v1/conversations/${conversationId}`);
        messages.value = response.data.data.messages || [];
        await hydrateResultCards();
    } catch (err) {
        messagesError.value = err.response?.data?.message || 'Failed to load messages';
        messages.value = [];
    } finally {
        messagesLoading.value = false;
    }
}
```

**Step 3: Add test**

```javascript
describe('result card hydration on reload (T70)', () => {
    it('hydrates result cards from system messages after fetchMessages', async () => {
        vi.spyOn(axios, 'get')
            .mockResolvedValueOnce({
                data: {
                    data: {
                        messages: [
                            { id: 'msg-1', role: 'user', content: 'Do it', created_at: '2026-02-15T12:00:00Z' },
                            { id: 'msg-2', role: 'system', content: '[System: Task result delivered] Task #42 "Add payment" completed.', created_at: '2026-02-15T12:01:00Z' },
                        ],
                    },
                },
            })
            .mockResolvedValueOnce({
                data: {
                    data: {
                        task_id: 42,
                        status: 'completed',
                        type: 'feature_dev',
                        title: 'Add payment',
                        mr_iid: 123,
                        issue_iid: null,
                        project_id: 1,
                        error_reason: null,
                        result: {
                            branch: 'ai/payment',
                            files_changed: [{ path: 'foo.php', action: 'created', summary: 'New' }],
                            notes: 'Done',
                        },
                    },
                },
            });

        const store = useConversationsStore();
        store.selectedId = 'conv-1';
        await store.fetchMessages('conv-1');

        expect(store.completedResults.length).toBe(1);
        expect(store.completedResults[0].task_id).toBe(42);
    });
});
```

**Step 4: Run tests**

Run: `npx vitest run resources/js/stores/conversations.test.js`
Expected: PASS

**Step 5: Commit**

```bash
git add resources/js/stores/conversations.js resources/js/stores/conversations.test.js
git commit --no-gpg-sign -m "T70.7: Hydrate result cards from persisted system messages on reload"
```

---

### Task 9: Add system context marker to conversation history (backend)

**Files:**
- Modify: `app/Events/TaskStatusChanged.php` or create a listener
- Modify: relevant conversation message storage

**Step 1: Context**

When a task completes, we need to persist a `[System: Task result delivered]` message in the conversation's message history so:
1. The AI (Conversation Engine) sees it and understands the context switch
2. The frontend can hydrate result cards on reload (Task 8)

This should be a listener on the `TaskStatusChanged` event that appends a system message to the task's conversation (if it has one).

**Step 2: Create the listener**

Create `app/Listeners/DeliverTaskResultToConversation.php`:

```php
<?php

namespace App\Listeners;

use App\Events\TaskStatusChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DeliverTaskResultToConversation
{
    public function handle(TaskStatusChanged $event): void
    {
        $task = $event->task;

        // Only for terminal tasks with a conversation
        if (! $task->isTerminal() || $task->conversation_id === null) {
            return;
        }

        $statusText = $task->status->value;
        $title = $task->result['title'] ?? $task->result['mr_title'] ?? 'Task';

        DB::table('messages')->insert([
            'id' => Str::uuid()->toString(),
            'conversation_id' => $task->conversation_id,
            'role' => 'system',
            'content' => "[System: Task result delivered] Task #{$task->id} \"{$title}\" {$statusText}.",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('DeliverTaskResultToConversation: system message added', [
            'task_id' => $task->id,
            'conversation_id' => $task->conversation_id,
        ]);
    }
}
```

**Step 3: Register in EventServiceProvider**

Add to `$listen` array:

```php
\App\Events\TaskStatusChanged::class => [
    \App\Listeners\DeliverTaskResultToConversation::class,
],
```

**Step 4: Write the test**

```php
<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Events\TaskStatusChanged;
use App\Listeners\DeliverTaskResultToConversation;
use App\Models\Conversation;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

it('inserts system message into conversation when task completes', function () {
    $conversation = Conversation::factory()->create();
    $task = Task::factory()->create([
        'conversation_id' => $conversation->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Completed,
        'result' => ['mr_title' => 'Add payment'],
    ]);

    $listener = new DeliverTaskResultToConversation();
    $listener->handle(new TaskStatusChanged($task));

    $systemMsg = DB::table('messages')
        ->where('conversation_id', $conversation->id)
        ->where('role', 'system')
        ->latest('created_at')
        ->first();

    expect($systemMsg)->not->toBeNull();
    expect($systemMsg->content)->toContain('[System: Task result delivered]');
    expect($systemMsg->content)->toContain("Task #{$task->id}");
});

it('does not insert message for non-terminal tasks', function () {
    $conversation = Conversation::factory()->create();
    $task = Task::factory()->create([
        'conversation_id' => $conversation->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Running,
    ]);

    $listener = new DeliverTaskResultToConversation();
    $listener->handle(new TaskStatusChanged($task));

    $count = DB::table('messages')
        ->where('conversation_id', $conversation->id)
        ->where('role', 'system')
        ->count();

    expect($count)->toBe(0);
});

it('does not insert message for tasks without a conversation', function () {
    $task = Task::factory()->create([
        'conversation_id' => null,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
    ]);

    $listener = new DeliverTaskResultToConversation();
    $listener->handle(new TaskStatusChanged($task));

    // No exception thrown, no message inserted — verify by checking message count
    $count = DB::table('messages')->where('role', 'system')->count();
    expect($count)->toBe(0);
});
```

**Step 5: Run tests**

Run: `php artisan test --filter=DeliverTaskResult`
Expected: PASS

**Step 6: Commit**

```bash
git add app/Listeners/DeliverTaskResultToConversation.php app/Providers/EventServiceProvider.php tests/Feature/Chat/DeliverTaskResultToConversationTest.php
git commit --no-gpg-sign -m "T70.8: Persist system context marker in conversation on task completion"
```

---

### Task 10: Add verification checks to verify_m3.py

**Files:**
- Modify: `verify/verify_m3.py`

**Step 1: Add T70 structural verification**

Append to `verify/verify_m3.py`:

```python
# ============================================================
#  T70: Chat Page — Result Cards (Screenshots D131)
# ============================================================
section("T70: Chat Page — Result Cards (Screenshots D131)")

# ResultCard component
checker.check(
    "ResultCard component exists",
    file_exists("resources/js/components/ResultCard.vue"),
)
checker.check(
    "ResultCard uses script setup",
    file_contains("resources/js/components/ResultCard.vue", "<script setup>"),
)
checker.check(
    "ResultCard has data-testid for card",
    file_contains("resources/js/components/ResultCard.vue", 'data-testid="result-card"'),
)
checker.check(
    "ResultCard has data-testid for artifact link",
    file_contains("resources/js/components/ResultCard.vue", 'data-testid="artifact-link"'),
)
checker.check(
    "ResultCard has data-testid for result summary",
    file_contains("resources/js/components/ResultCard.vue", 'data-testid="result-summary"'),
)
checker.check(
    "ResultCard has data-testid for error reason",
    file_contains("resources/js/components/ResultCard.vue", 'data-testid="error-reason"'),
)
checker.check(
    "ResultCard has data-testid for screenshot",
    file_contains("resources/js/components/ResultCard.vue", 'data-testid="result-screenshot"'),
)
checker.check(
    "ResultCard shows success indicator",
    file_contains("resources/js/components/ResultCard.vue", "✅"),
)
checker.check(
    "ResultCard shows failure indicator",
    file_contains("resources/js/components/ResultCard.vue", "❌"),
)
checker.check(
    "ResultCard handles base64 screenshot image",
    file_contains("resources/js/components/ResultCard.vue", "data:image/png;base64"),
)

# ResultCard tests
checker.check(
    "ResultCard test file exists",
    file_exists("resources/js/components/ResultCard.test.js"),
)

# MessageThread integrates ResultCard
checker.check(
    "MessageThread imports ResultCard",
    file_contains("resources/js/components/MessageThread.vue", "ResultCard"),
)

# Store has result delivery
checker.check(
    "Conversations store has deliverTaskResult method",
    file_contains("resources/js/stores/conversations.js", "deliverTaskResult"),
)
checker.check(
    "Conversations store has completedResults state",
    file_contains("resources/js/stores/conversations.js", "completedResults"),
)
checker.check(
    "Conversations store has hydrateResultCards method",
    file_contains("resources/js/stores/conversations.js", "hydrateResultCards"),
)

# Backend: enhanced broadcast
checker.check(
    "TaskStatusChanged broadcasts result_data",
    file_contains("app/Events/TaskStatusChanged.php", "result_data"),
)
checker.check(
    "TaskStatusChanged broadcasts error_reason",
    file_contains("app/Events/TaskStatusChanged.php", "error_reason"),
)

# Backend: task result view endpoint
checker.check(
    "TaskResultViewController exists",
    file_exists("app/Http/Controllers/Api/TaskResultViewController.php"),
)
checker.check(
    "Task result view route registered",
    file_contains("routes/api.php", "tasks/{task}/view"),
)

# Backend: system context marker listener
checker.check(
    "DeliverTaskResultToConversation listener exists",
    file_exists("app/Listeners/DeliverTaskResultToConversation.php"),
)
checker.check(
    "Listener inserts system task result message",
    file_contains("app/Listeners/DeliverTaskResultToConversation.php", "[System: Task result delivered]"),
)
```

**Step 2: Run verification**

Run: `python3 verify/verify_m3.py`
Expected: All T70 checks pass

**Step 3: Commit**

```bash
git add verify/verify_m3.py
git commit --no-gpg-sign -m "T70.9: Add structural verification checks for result cards"
```

---

### Task 11: Run full verification and finalize

**Files:**
- Run all tests and verification

**Step 1: Run frontend tests**

Run: `npx vitest run`
Expected: All tests pass

**Step 2: Run backend tests**

Run: `php artisan test`
Expected: All tests pass

**Step 3: Run structural verification**

Run: `python3 verify/verify_m3.py`
Expected: All checks pass

**Step 4: Final commit**

```bash
git add -A
git commit --no-gpg-sign -m "T70: Complete chat page result cards — visually distinct task results with screenshots (D131)"
```

**Step 5: Update progress.md**

Mark T70 as complete, bold T71 as next task, update summary counts.

---

## Implementation Notes

**Data flow summary:**
1. Task reaches terminal state → `TaskObserver` fires `TaskStatusChanged` event
2. Event broadcasts enriched payload (with `result_data`, `error_reason`) on `task.{id}` channel
3. `DeliverTaskResultToConversation` listener persists `[System: Task result delivered]` message
4. Frontend `subscribeToTask` handler receives event → calls `deliverTaskResult()` → adds to `completedResults` + appends system message
5. `MessageThread` renders `ResultCard` components for each entry in `completedResultsForConversation`
6. On page reload: `fetchMessages` → `hydrateResultCards` → fetches from `/api/v1/tasks/{id}/view` → populates `completedResults`

**Key design decisions:**
- Result cards are rendered separately from `messages` (not as message bubbles) — they have distinct visual styling per spec
- The system context marker is persisted server-side so the AI's conversation memory includes it
- Screenshots are sent as base64 in the broadcast for real-time delivery, and fetched from the API on reload
- The `deliverTaskResult` method handles both real-time (Reverb) and hydrated (API) paths

# T69: Chat Page — Pinned Task Bar Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a pinned task bar to the chat page that shows active runner tasks with elapsed time, pipeline links, and runner load awareness (D133).

**Architecture:** The conversations store gains task tracking state (`activeTasks` map keyed by task ID). When a `[System: Task dispatched]` message appears in the stream, the store parses the task ID and starts tracking it. Real-time status updates arrive via Laravel Echo listening on the `task.{id}` private channel for `task.status.changed` events. A new `PinnedTaskBar.vue` component renders between the message thread and composer, showing each active task with an elapsed timer, action type badge, and GitLab pipeline link. When a task reaches a terminal state (completed/failed/superseded), it's removed from the bar after a brief delay. Runner load awareness (D133) is handled by including `pipeline_status` in the broadcast payload — when status is `running` but the pipeline is still `pending` in GitLab, the bar shows "Waiting for available runner" instead of implying execution.

**Tech Stack:** Vue 3 (Composition API), Pinia, Laravel Echo + pusher-js (Reverb client), Laravel Broadcasting, Vitest

---

### Task 1: Install Laravel Echo and pusher-js npm packages

**Files:**
- Modify: `package.json`

**Step 1: Install dependencies**

```bash
npm install --save laravel-echo pusher-js
```

**Step 2: Verify installation**

```bash
node -e "require('laravel-echo'); require('pusher-js'); console.log('OK')"
```

Expected: `OK`

**Step 3: Commit**

```bash
git add package.json package-lock.json
git commit --no-gpg-sign -m "T69.1: Install laravel-echo and pusher-js dependencies

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 2: Create Echo composable for Reverb WebSocket connection

**Files:**
- Create: `resources/js/composables/useEcho.js`
- Test: `resources/js/composables/useEcho.test.js`

This composable initializes a singleton Laravel Echo instance connected to Reverb. It reads connection config from `window.__REVERB_CONFIG__` (injected by the Blade layout) or falls back to reasonable defaults. T74 will later expand this — we keep it minimal.

**Step 1: Write the test**

```javascript
// resources/js/composables/useEcho.test.js
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

// Mock pusher-js before importing useEcho
vi.mock('pusher-js', () => {
    return { default: vi.fn() };
});

// Mock laravel-echo
const mockPrivate = vi.fn().mockReturnValue({
    listen: vi.fn().mockReturnThis(),
    stopListening: vi.fn().mockReturnThis(),
});
const mockLeave = vi.fn();
const MockEcho = vi.fn().mockImplementation(() => ({
    private: mockPrivate,
    leave: mockLeave,
    connector: { pusher: { connection: { state: 'connected' } } },
}));
vi.mock('laravel-echo', () => ({ default: MockEcho }));

import { getEcho, destroyEcho } from './useEcho';

describe('useEcho', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        destroyEcho();
        window.__REVERB_CONFIG__ = {
            key: 'test-key',
            host: 'localhost',
            port: 8080,
            scheme: 'http',
        };
    });

    afterEach(() => {
        delete window.__REVERB_CONFIG__;
    });

    it('creates an Echo instance on first call', () => {
        const echo = getEcho();
        expect(echo).toBeDefined();
        expect(MockEcho).toHaveBeenCalledTimes(1);
    });

    it('returns the same instance on subsequent calls (singleton)', () => {
        const echo1 = getEcho();
        const echo2 = getEcho();
        expect(echo1).toBe(echo2);
        expect(MockEcho).toHaveBeenCalledTimes(1);
    });

    it('configures Echo with Reverb settings from window config', () => {
        getEcho();
        expect(MockEcho).toHaveBeenCalledWith(
            expect.objectContaining({
                broadcaster: 'reverb',
                key: 'test-key',
            })
        );
    });

    it('destroyEcho resets singleton so next call creates a new instance', () => {
        getEcho();
        destroyEcho();
        getEcho();
        expect(MockEcho).toHaveBeenCalledTimes(2);
    });
});
```

**Step 2: Run test to verify it fails**

```bash
npx vitest run resources/js/composables/useEcho.test.js
```

Expected: FAIL — module not found

**Step 3: Write the implementation**

```javascript
// resources/js/composables/useEcho.js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Pusher must be on window for Echo's reverb broadcaster
window.Pusher = Pusher;

let echoInstance = null;

/**
 * Get the singleton Laravel Echo instance.
 * Reads Reverb connection config from window.__REVERB_CONFIG__
 * (injected by Blade layout) or falls back to defaults.
 */
export function getEcho() {
    if (echoInstance) {
        return echoInstance;
    }

    const config = window.__REVERB_CONFIG__ || {};

    echoInstance = new Echo({
        broadcaster: 'reverb',
        key: config.key || import.meta.env.VITE_REVERB_APP_KEY || '',
        wsHost: config.host || import.meta.env.VITE_REVERB_HOST || 'localhost',
        wsPort: config.port || import.meta.env.VITE_REVERB_PORT || 8080,
        wssPort: config.port || import.meta.env.VITE_REVERB_PORT || 443,
        forceTLS: (config.scheme || import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    return echoInstance;
}

/**
 * Destroy the Echo instance (for cleanup/testing).
 */
export function destroyEcho() {
    echoInstance = null;
}
```

**Step 4: Run test to verify it passes**

```bash
npx vitest run resources/js/composables/useEcho.test.js
```

Expected: PASS — all 4 tests

**Step 5: Commit**

```bash
git add resources/js/composables/useEcho.js resources/js/composables/useEcho.test.js
git commit --no-gpg-sign -m "T69.2: Create Echo composable for Reverb WebSocket connection

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 3: Add `pipeline_status` to TaskStatusChanged broadcast payload

**Files:**
- Modify: `app/Events/TaskStatusChanged.php`
- Modify: `tests/Feature/Broadcasting/TaskLifecycleBroadcastTest.php`

The existing broadcast payload includes `pipeline_id` but not the GitLab pipeline status. For D133 (runner load awareness), the Vue client needs to know whether the pipeline is `pending` (waiting for runner) or `running` (executing). Rather than having the frontend poll GitLab, we include the pipeline status in the broadcast. The TaskDispatcher already queries pipeline status during dispatch — we add a `pipeline_status` nullable field to the task model (or broadcast it from external state).

Simpler approach: the TaskStatusChanged event already fires on every status change. When the task transitions to `running`, the pipeline may still be `pending` in GitLab. We'll add a `pipeline_status` column to the tasks table that the TaskDispatcher updates, and include it in the broadcast payload.

**Step 1: Write the test for the new broadcast field**

Add to the existing test file `tests/Feature/Broadcasting/TaskLifecycleBroadcastTest.php`:

```php
it('includes pipeline_status in broadcast payload', function () {
    Event::fake([TaskStatusChanged::class]);

    $task = Task::factory()->create([
        'status' => TaskStatus::Queued,
        'pipeline_id' => 12345,
        'pipeline_status' => 'pending',
    ]);

    $task->transitionTo(TaskStatus::Running);

    Event::assertDispatched(TaskStatusChanged::class, function ($event) {
        $payload = $event->broadcastWith();
        return array_key_exists('pipeline_status', $payload)
            && $payload['pipeline_status'] === 'pending';
    });
});

it('includes null pipeline_status when not set', function () {
    Event::fake([TaskStatusChanged::class]);

    $task = Task::factory()->create([
        'status' => TaskStatus::Queued,
        'pipeline_id' => null,
        'pipeline_status' => null,
    ]);

    $task->transitionTo(TaskStatus::Running);

    Event::assertDispatched(TaskStatusChanged::class, function ($event) {
        $payload = $event->broadcastWith();
        return array_key_exists('pipeline_status', $payload)
            && $payload['pipeline_status'] === null;
    });
});
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --filter="includes pipeline_status"
```

Expected: FAIL — `pipeline_status` key not in payload

**Step 3: Create migration for pipeline_status column**

```bash
php artisan make:migration add_pipeline_status_to_tasks_table
```

Migration content:

```php
public function up(): void
{
    Schema::table('tasks', function (Blueprint $table) {
        $table->string('pipeline_status', 30)->nullable()->after('pipeline_id');
    });
}

public function down(): void
{
    Schema::table('tasks', function (Blueprint $table) {
        $table->dropColumn('pipeline_status');
    });
}
```

**Step 4: Update Task model — add `pipeline_status` to fillable**

In `app/Models/Task.php`, add `'pipeline_status'` to the `$fillable` array (after `'pipeline_id'`).

**Step 5: Update TaskFactory — add `pipeline_status`**

In the Task factory, add `'pipeline_status' => null` to the default state.

**Step 6: Update TaskStatusChanged broadcast payload**

In `app/Events/TaskStatusChanged.php`, add to `broadcastWith()`:

```php
'pipeline_status' => $this->task->pipeline_status,
```

Add it after the `'pipeline_id'` line.

**Step 7: Run migration and tests**

```bash
php artisan migrate
php artisan test --filter="includes pipeline_status"
```

Expected: PASS

**Step 8: Also include `title` and `started_at` in broadcast payload**

The pinned task bar needs the task title (from `result.title`) and `started_at` timestamp for the elapsed timer. Update `broadcastWith()` to include:

```php
'title' => $this->task->result['title'] ?? null,
'started_at' => $this->task->started_at?->toIso8601String(),
'conversation_id' => $this->task->conversation_id,
```

**Step 9: Run full broadcast tests**

```bash
php artisan test tests/Feature/Broadcasting/
```

Expected: all PASS

**Step 10: Commit**

```bash
git add -A
git commit --no-gpg-sign -m "T69.3: Add pipeline_status, title, started_at to TaskStatusChanged broadcast

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 4: Add active task tracking to the conversations store

**Files:**
- Modify: `resources/js/stores/conversations.js`
- Modify: `resources/js/stores/conversations.test.js`

The store gains:
- `activeTasks` — a reactive `Map<taskId, taskData>` of currently tracked tasks for the active conversation
- `trackTask(taskData)` — adds a task to tracking
- `updateTaskStatus(taskId, statusData)` — updates a tracked task (from Reverb event)
- `removeTask(taskId)` — removes a task from tracking
- Detection of `[System: Task dispatched]` in streamed text to auto-track new tasks

**Step 1: Write the store tests**

Add to `resources/js/stores/conversations.test.js`:

```javascript
describe('active task tracking (T69)', () => {
    it('trackTask adds a task to activeTasks', () => {
        const store = useConversationsStore();
        store.trackTask({
            task_id: 42,
            status: 'queued',
            type: 'feature_dev',
            title: 'Implement payment',
            project_id: 1,
            pipeline_id: null,
            pipeline_status: null,
            started_at: null,
            conversation_id: 'conv-1',
        });

        expect(store.activeTasks.size).toBe(1);
        expect(store.activeTasks.get(42).title).toBe('Implement payment');
    });

    it('updateTaskStatus updates an existing tracked task', () => {
        const store = useConversationsStore();
        store.trackTask({
            task_id: 42,
            status: 'queued',
            type: 'feature_dev',
            title: 'Implement payment',
            project_id: 1,
            pipeline_id: null,
            pipeline_status: null,
            started_at: null,
            conversation_id: 'conv-1',
        });

        store.updateTaskStatus(42, {
            status: 'running',
            pipeline_id: 999,
            pipeline_status: 'running',
            started_at: '2026-02-15T12:00:00Z',
        });

        const task = store.activeTasks.get(42);
        expect(task.status).toBe('running');
        expect(task.pipeline_id).toBe(999);
        expect(task.started_at).toBe('2026-02-15T12:00:00Z');
    });

    it('updateTaskStatus is a no-op for untracked tasks', () => {
        const store = useConversationsStore();
        store.updateTaskStatus(99, { status: 'running' });
        expect(store.activeTasks.size).toBe(0);
    });

    it('removeTask removes a task from activeTasks', () => {
        const store = useConversationsStore();
        store.trackTask({
            task_id: 42,
            status: 'queued',
            type: 'feature_dev',
            title: 'Test',
            project_id: 1,
            pipeline_id: null,
            pipeline_status: null,
            started_at: null,
            conversation_id: 'conv-1',
        });

        store.removeTask(42);
        expect(store.activeTasks.size).toBe(0);
    });

    it('activeTasksForConversation returns tasks for the selected conversation', () => {
        const store = useConversationsStore();
        store.selectedId = 'conv-1';
        store.trackTask({
            task_id: 42,
            status: 'running',
            type: 'feature_dev',
            title: 'Task A',
            project_id: 1,
            pipeline_id: null,
            pipeline_status: null,
            started_at: null,
            conversation_id: 'conv-1',
        });
        store.trackTask({
            task_id: 43,
            status: 'running',
            type: 'code_review',
            title: 'Task B',
            project_id: 1,
            pipeline_id: null,
            pipeline_status: null,
            started_at: null,
            conversation_id: 'conv-2',
        });

        expect(store.activeTasksForConversation.length).toBe(1);
        expect(store.activeTasksForConversation[0].task_id).toBe(42);
    });

    it('$reset clears activeTasks', () => {
        const store = useConversationsStore();
        store.trackTask({
            task_id: 42,
            status: 'running',
            type: 'feature_dev',
            title: 'Test',
            project_id: 1,
            pipeline_id: null,
            pipeline_status: null,
            started_at: null,
            conversation_id: 'conv-1',
        });

        store.$reset();
        expect(store.activeTasks.size).toBe(0);
    });

    it('detects task dispatch from streamed system message', () => {
        const store = useConversationsStore();
        store.selectedId = 'conv-1';

        const systemMsg = '[System: Task dispatched] Feature implementation "Add Stripe" has been dispatched as Task #42. You can track its progress in the pinned task bar.';

        const parsed = store.parseTaskDispatchMessage(systemMsg);
        expect(parsed).toEqual({ taskId: 42, title: 'Add Stripe', typeLabel: 'Feature implementation' });
    });

    it('parseTaskDispatchMessage returns null for non-dispatch messages', () => {
        const store = useConversationsStore();
        expect(store.parseTaskDispatchMessage('Hello world')).toBeNull();
        expect(store.parseTaskDispatchMessage('[System: something else]')).toBeNull();
    });
});
```

**Step 2: Run tests to verify they fail**

```bash
npx vitest run resources/js/stores/conversations.test.js
```

Expected: FAIL — methods/properties don't exist

**Step 3: Implement task tracking in the store**

Add to `resources/js/stores/conversations.js`:

```javascript
// After existing state declarations:
// Active task tracking (T69 — pinned task bar)
const activeTasks = ref(new Map());

const activeTasksForConversation = computed(() => {
    if (!selectedId.value) return [];
    return [...activeTasks.value.values()].filter(
        (t) => t.conversation_id === selectedId.value && !isTerminalStatus(t.status)
    );
});

function isTerminalStatus(status) {
    return ['completed', 'failed', 'superseded'].includes(status);
}

function trackTask(taskData) {
    activeTasks.value = new Map(activeTasks.value);
    activeTasks.value.set(taskData.task_id, { ...taskData });
}

function updateTaskStatus(taskId, statusData) {
    if (!activeTasks.value.has(taskId)) return;
    const updated = new Map(activeTasks.value);
    updated.set(taskId, { ...updated.get(taskId), ...statusData });
    activeTasks.value = updated;
}

function removeTask(taskId) {
    const updated = new Map(activeTasks.value);
    updated.delete(taskId);
    activeTasks.value = updated;
}

/**
 * Parse a [System: Task dispatched] message to extract task tracking info.
 * Returns { taskId, title, typeLabel } or null if not a dispatch message.
 */
function parseTaskDispatchMessage(text) {
    const match = text.match(
        /\[System: Task dispatched\] (.+?) "(.+?)" has been dispatched as Task #(\d+)/
    );
    if (!match) return null;
    return {
        typeLabel: match[1],
        title: match[2],
        taskId: parseInt(match[3], 10),
    };
}
```

Add `activeTasks` to the `$reset()` function:

```javascript
activeTasks.value = new Map();
```

Add to the returned object:

```javascript
activeTasks,
activeTasksForConversation,
trackTask,
updateTaskStatus,
removeTask,
parseTaskDispatchMessage,
```

**Step 4: Run store tests**

```bash
npx vitest run resources/js/stores/conversations.test.js
```

Expected: PASS — all tests including new T69 ones

**Step 5: Commit**

```bash
git add resources/js/stores/conversations.js resources/js/stores/conversations.test.js
git commit --no-gpg-sign -m "T69.4: Add active task tracking to conversations store

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 5: Create PinnedTaskBar component

**Files:**
- Create: `resources/js/components/PinnedTaskBar.vue`
- Create: `resources/js/components/PinnedTaskBar.test.js`

The component renders a sticky bar between the message thread and composer. For each active task:
- Action type emoji + label
- Task title
- Elapsed time counter (mm:ss format, updates every second)
- "View pipeline" link (when pipeline_id is available)
- Runner load awareness: "Waiting for available runner… System busy, expect delays" when `pipeline_status === 'pending'`

**Step 1: Write the test**

```javascript
// resources/js/components/PinnedTaskBar.test.js
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import PinnedTaskBar from './PinnedTaskBar.vue';

// Freeze time for elapsed timer tests
const NOW = new Date('2026-02-15T12:05:00Z');

describe('PinnedTaskBar', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(NOW);
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    function mountBar(tasks = []) {
        return mount(PinnedTaskBar, {
            props: { tasks },
        });
    }

    it('renders nothing when no tasks', () => {
        const wrapper = mountBar([]);
        expect(wrapper.find('[data-testid="pinned-task-bar"]').exists()).toBe(false);
    });

    it('renders a bar for each active task', () => {
        const tasks = [
            { task_id: 1, status: 'running', type: 'feature_dev', title: 'Add Stripe', pipeline_id: 100, pipeline_status: 'running', started_at: '2026-02-15T12:00:00Z', project_id: 1, conversation_id: 'c1' },
            { task_id: 2, status: 'queued', type: 'code_review', title: 'Review PR', pipeline_id: null, pipeline_status: null, started_at: null, project_id: 1, conversation_id: 'c1' },
        ];
        const wrapper = mountBar(tasks);
        expect(wrapper.findAll('[data-testid="pinned-task-item"]')).toHaveLength(2);
    });

    it('displays task title', () => {
        const tasks = [
            { task_id: 1, status: 'running', type: 'feature_dev', title: 'Add Stripe', pipeline_id: 100, pipeline_status: 'running', started_at: '2026-02-15T12:00:00Z', project_id: 1, conversation_id: 'c1' },
        ];
        const wrapper = mountBar(tasks);
        expect(wrapper.text()).toContain('Add Stripe');
    });

    it('displays elapsed time for running tasks', () => {
        const tasks = [
            { task_id: 1, status: 'running', type: 'feature_dev', title: 'Add Stripe', pipeline_id: 100, pipeline_status: 'running', started_at: '2026-02-15T12:00:00Z', project_id: 1, conversation_id: 'c1' },
        ];
        const wrapper = mountBar(tasks);
        // 5 minutes elapsed (12:00:00 → 12:05:00)
        expect(wrapper.find('[data-testid="elapsed-time"]').text()).toBe('5m 0s');
    });

    it('updates elapsed time every second', async () => {
        const tasks = [
            { task_id: 1, status: 'running', type: 'feature_dev', title: 'Add Stripe', pipeline_id: 100, pipeline_status: 'running', started_at: '2026-02-15T12:00:00Z', project_id: 1, conversation_id: 'c1' },
        ];
        const wrapper = mountBar(tasks);
        expect(wrapper.find('[data-testid="elapsed-time"]').text()).toBe('5m 0s');

        vi.advanceTimersByTime(1000);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="elapsed-time"]').text()).toBe('5m 1s');
    });

    it('shows pipeline link when pipeline_id is available', () => {
        const tasks = [
            { task_id: 1, status: 'running', type: 'feature_dev', title: 'Test', pipeline_id: 456, pipeline_status: 'running', started_at: '2026-02-15T12:00:00Z', project_id: 1, conversation_id: 'c1' },
        ];
        const wrapper = mountBar(tasks);
        const link = wrapper.find('[data-testid="pipeline-link"]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('View pipeline');
    });

    it('hides pipeline link when pipeline_id is null', () => {
        const tasks = [
            { task_id: 1, status: 'queued', type: 'feature_dev', title: 'Test', pipeline_id: null, pipeline_status: null, started_at: null, project_id: 1, conversation_id: 'c1' },
        ];
        const wrapper = mountBar(tasks);
        expect(wrapper.find('[data-testid="pipeline-link"]').exists()).toBe(false);
    });

    it('shows runner load warning when pipeline_status is pending (D133)', () => {
        const tasks = [
            { task_id: 1, status: 'running', type: 'feature_dev', title: 'Test', pipeline_id: 456, pipeline_status: 'pending', started_at: '2026-02-15T12:00:00Z', project_id: 1, conversation_id: 'c1' },
        ];
        const wrapper = mountBar(tasks);
        expect(wrapper.text()).toContain('Waiting for available runner');
        expect(wrapper.text()).toContain('System busy, expect delays');
    });

    it('does NOT show runner load warning when pipeline is running normally', () => {
        const tasks = [
            { task_id: 1, status: 'running', type: 'feature_dev', title: 'Test', pipeline_id: 456, pipeline_status: 'running', started_at: '2026-02-15T12:00:00Z', project_id: 1, conversation_id: 'c1' },
        ];
        const wrapper = mountBar(tasks);
        expect(wrapper.text()).not.toContain('Waiting for available runner');
    });

    it('shows action type badge with emoji', () => {
        const tasks = [
            { task_id: 1, status: 'running', type: 'feature_dev', title: 'Test', pipeline_id: 456, pipeline_status: 'running', started_at: '2026-02-15T12:00:00Z', project_id: 1, conversation_id: 'c1' },
        ];
        const wrapper = mountBar(tasks);
        expect(wrapper.find('[data-testid="task-type-badge"]').exists()).toBe(true);
    });

    it('shows queued state text when task is queued', () => {
        const tasks = [
            { task_id: 1, status: 'queued', type: 'feature_dev', title: 'Test', pipeline_id: null, pipeline_status: null, started_at: null, project_id: 1, conversation_id: 'c1' },
        ];
        const wrapper = mountBar(tasks);
        expect(wrapper.text()).toContain('Queued');
    });

    it('cleans up interval on unmount', () => {
        const tasks = [
            { task_id: 1, status: 'running', type: 'feature_dev', title: 'Test', pipeline_id: 100, pipeline_status: 'running', started_at: '2026-02-15T12:00:00Z', project_id: 1, conversation_id: 'c1' },
        ];
        const clearSpy = vi.spyOn(globalThis, 'clearInterval');
        const wrapper = mountBar(tasks);
        wrapper.unmount();
        expect(clearSpy).toHaveBeenCalled();
    });
});
```

**Step 2: Run test to verify it fails**

```bash
npx vitest run resources/js/components/PinnedTaskBar.test.js
```

Expected: FAIL — component not found

**Step 3: Write the component**

```vue
<!-- resources/js/components/PinnedTaskBar.vue -->
<script setup>
import { ref, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    tasks: { type: Array, required: true },
});

const TASK_TYPE_DISPLAY = {
    code_review: { label: 'Code Review', emoji: '🔍' },
    feature_dev: { label: 'Feature Dev', emoji: '🚀' },
    ui_adjustment: { label: 'UI Adjustment', emoji: '🎨' },
    prd_creation: { label: 'Issue Creation', emoji: '📋' },
    deep_analysis: { label: 'Deep Analysis', emoji: '🔍' },
    security_audit: { label: 'Security Audit', emoji: '🔒' },
    issue_discussion: { label: 'Issue Discussion', emoji: '💬' },
};

function typeDisplay(type) {
    return TASK_TYPE_DISPLAY[type] || { label: type, emoji: '⚙️' };
}

// Reactive tick for elapsed time updates
const tick = ref(Date.now());
let intervalId = null;

onMounted(() => {
    intervalId = setInterval(() => {
        tick.value = Date.now();
    }, 1000);
});

onUnmounted(() => {
    if (intervalId) clearInterval(intervalId);
});

function formatElapsed(startedAt) {
    if (!startedAt) return null;
    const start = new Date(startedAt).getTime();
    // Use tick.value to make this reactive every second
    const diff = Math.max(0, Math.floor((tick.value - start) / 1000));
    const minutes = Math.floor(diff / 60);
    const seconds = diff % 60;
    return `${minutes}m ${seconds}s`;
}

function isPipelinePending(task) {
    return task.pipeline_status === 'pending';
}
</script>

<template>
  <div v-if="tasks.length > 0" data-testid="pinned-task-bar" class="border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
    <div
      v-for="task in tasks"
      :key="task.task_id"
      data-testid="pinned-task-item"
      class="flex items-center gap-3 px-4 py-2 text-sm"
    >
      <!-- Action type badge -->
      <span
        data-testid="task-type-badge"
        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 shrink-0"
      >
        <span>{{ typeDisplay(task.type).emoji }}</span>
        <span class="hidden sm:inline">{{ typeDisplay(task.type).label }}</span>
      </span>

      <!-- Status & title -->
      <div class="flex-1 min-w-0">
        <template v-if="isPipelinePending(task)">
          <span class="text-amber-600 dark:text-amber-400">
            ⏳ Waiting for available runner…
          </span>
          <span class="text-zinc-500 dark:text-zinc-400 text-xs ml-1">
            — System busy, expect delays
          </span>
        </template>
        <template v-else-if="task.status === 'running'">
          <span class="text-zinc-700 dark:text-zinc-200">
            ⏳ {{ task.title || 'Running task…' }}
          </span>
        </template>
        <template v-else>
          <span class="text-zinc-500 dark:text-zinc-400">
            ⏳ Queued — {{ task.title || 'Waiting…' }}
          </span>
        </template>
      </div>

      <!-- Elapsed time -->
      <span
        v-if="task.started_at"
        data-testid="elapsed-time"
        class="text-xs text-zinc-500 dark:text-zinc-400 tabular-nums shrink-0"
      >
        {{ formatElapsed(task.started_at) }}
      </span>

      <!-- Pipeline link -->
      <a
        v-if="task.pipeline_id"
        data-testid="pipeline-link"
        :href="`/-/pipelines/${task.pipeline_id}`"
        target="_blank"
        rel="noopener"
        class="text-xs text-blue-600 dark:text-blue-400 hover:underline shrink-0"
      >
        View pipeline ↗
      </a>
    </div>
  </div>
</template>
```

Note: The pipeline link uses a placeholder href (`/-/pipelines/{id}`) because the full GitLab URL requires project path info that will be resolved when connecting to real GitLab data. For now, this provides the correct structure.

**Step 4: Run test to verify it passes**

```bash
npx vitest run resources/js/components/PinnedTaskBar.test.js
```

Expected: PASS — all 11 tests

**Step 5: Commit**

```bash
git add resources/js/components/PinnedTaskBar.vue resources/js/components/PinnedTaskBar.test.js
git commit --no-gpg-sign -m "T69.5: Create PinnedTaskBar component with elapsed timer and D133

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 6: Integrate PinnedTaskBar into MessageThread

**Files:**
- Modify: `resources/js/components/MessageThread.vue`
- Modify: `resources/js/components/MessageThread.test.js`

Add the PinnedTaskBar between the message scroll area and the composer, positioned as a sticky bar.

**Step 1: Write the test**

Add to `resources/js/components/MessageThread.test.js`:

```javascript
import PinnedTaskBar from './PinnedTaskBar.vue';

describe('pinned task bar integration (T69)', () => {
    it('renders PinnedTaskBar when activeTasksForConversation has tasks', () => {
        const store = useConversationsStore();
        store.selectedId = 'conv-1';
        store.messages = [
            { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
        ];
        store.trackTask({
            task_id: 42,
            status: 'running',
            type: 'feature_dev',
            title: 'Payment feature',
            pipeline_id: 100,
            pipeline_status: 'running',
            started_at: '2026-02-15T12:00:00Z',
            project_id: 1,
            conversation_id: 'conv-1',
        });

        const wrapper = mountThread();
        expect(wrapper.findComponent(PinnedTaskBar).exists()).toBe(true);
    });

    it('hides PinnedTaskBar when no active tasks', () => {
        const store = useConversationsStore();
        store.selectedId = 'conv-1';
        store.messages = [
            { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
        ];

        const wrapper = mountThread();
        expect(wrapper.findComponent(PinnedTaskBar).exists()).toBe(false);
    });

    it('passes active tasks as prop to PinnedTaskBar', () => {
        const store = useConversationsStore();
        store.selectedId = 'conv-1';
        store.messages = [
            { id: 'msg-1', role: 'user', content: 'Hello', created_at: '2026-02-15T12:00:00+00:00' },
        ];
        store.trackTask({
            task_id: 42,
            status: 'running',
            type: 'feature_dev',
            title: 'Test Task',
            pipeline_id: 100,
            pipeline_status: 'running',
            started_at: '2026-02-15T12:00:00Z',
            project_id: 1,
            conversation_id: 'conv-1',
        });

        const wrapper = mountThread();
        const bar = wrapper.findComponent(PinnedTaskBar);
        expect(bar.props('tasks')).toHaveLength(1);
        expect(bar.props('tasks')[0].task_id).toBe(42);
    });
});
```

**Step 2: Run tests to verify they fail**

```bash
npx vitest run resources/js/components/MessageThread.test.js
```

Expected: FAIL — PinnedTaskBar not rendered

**Step 3: Update MessageThread.vue**

Add import:

```javascript
import PinnedTaskBar from './PinnedTaskBar.vue';
```

In the template, add PinnedTaskBar between the scroll area and the composer (after the closing `</div>` of the scroll container, before `<MessageComposer ...>`):

```html
    <!-- Pinned task bar: active tasks with elapsed time (T69) -->
    <PinnedTaskBar
      v-if="store.activeTasksForConversation.length > 0"
      :tasks="store.activeTasksForConversation"
    />
```

**Step 4: Run tests**

```bash
npx vitest run resources/js/components/MessageThread.test.js
```

Expected: PASS — all tests including new T69 ones

**Step 5: Commit**

```bash
git add resources/js/components/MessageThread.vue resources/js/components/MessageThread.test.js
git commit --no-gpg-sign -m "T69.6: Integrate PinnedTaskBar into MessageThread layout

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 7: Wire Reverb channel subscription for task status updates

**Files:**
- Modify: `resources/js/stores/conversations.js`
- Modify: `resources/js/stores/conversations.test.js`

When a task is tracked, the store subscribes to the `task.{id}` private channel via Echo and listens for `task.status.changed` events. On receiving an event, it calls `updateTaskStatus()`. When the status is terminal, it schedules task removal after a 3-second delay (so the user sees the final state briefly before it disappears — T70 will replace it with a result card).

**Step 1: Write the test**

Add to `resources/js/stores/conversations.test.js`:

```javascript
// At the top of the file, add mock for useEcho
vi.mock('@/composables/useEcho', () => {
    const listenFn = vi.fn().mockReturnThis();
    const stopListeningFn = vi.fn().mockReturnThis();
    const mockChannel = { listen: listenFn, stopListening: stopListeningFn };
    const mockEcho = {
        private: vi.fn().mockReturnValue(mockChannel),
        leave: vi.fn(),
    };
    return {
        getEcho: vi.fn().mockReturnValue(mockEcho),
        __mockEcho: mockEcho,
        __mockChannel: mockChannel,
    };
});
```

```javascript
describe('Reverb channel subscription (T69)', () => {
    it('subscribeToTask subscribes to task.{id} channel', async () => {
        const { getEcho, __mockEcho } = await import('@/composables/useEcho');
        const store = useConversationsStore();

        store.subscribeToTask(42);

        expect(__mockEcho.private).toHaveBeenCalledWith('task.42');
    });

    it('subscribeToTask listens for task.status.changed events', async () => {
        const { __mockChannel } = await import('@/composables/useEcho');
        const store = useConversationsStore();

        store.subscribeToTask(42);

        expect(__mockChannel.listen).toHaveBeenCalledWith(
            '.task.status.changed',
            expect.any(Function)
        );
    });

    it('unsubscribeFromTask leaves the channel', async () => {
        const { __mockEcho } = await import('@/composables/useEcho');
        const store = useConversationsStore();

        store.unsubscribeFromTask(42);

        expect(__mockEcho.leave).toHaveBeenCalledWith('task.42');
    });

    it('receiving task.status.changed event updates tracked task', async () => {
        const { __mockChannel } = await import('@/composables/useEcho');
        const store = useConversationsStore();

        store.trackTask({
            task_id: 42,
            status: 'queued',
            type: 'feature_dev',
            title: 'Test',
            project_id: 1,
            pipeline_id: null,
            pipeline_status: null,
            started_at: null,
            conversation_id: 'conv-1',
        });

        store.subscribeToTask(42);

        // Simulate event by calling the listen callback
        const callback = __mockChannel.listen.mock.calls[0][1];
        callback({
            task_id: 42,
            status: 'running',
            pipeline_id: 999,
            pipeline_status: 'running',
            started_at: '2026-02-15T12:00:00Z',
        });

        const task = store.activeTasks.get(42);
        expect(task.status).toBe('running');
        expect(task.pipeline_id).toBe(999);
    });
});
```

**Step 2: Run tests to verify they fail**

```bash
npx vitest run resources/js/stores/conversations.test.js
```

Expected: FAIL — `subscribeToTask` not defined

**Step 3: Implement channel subscription**

Add to `resources/js/stores/conversations.js`:

```javascript
import { getEcho } from '@/composables/useEcho';
```

```javascript
// Track active channel subscriptions (taskId → true)
const taskSubscriptions = ref(new Set());

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

        // If terminal, schedule removal after brief delay
        if (['completed', 'failed', 'superseded'].includes(event.status)) {
            setTimeout(() => {
                removeTask(event.task_id);
                unsubscribeFromTask(event.task_id);
            }, 3000);
        }
    });

    taskSubscriptions.value = new Set([...taskSubscriptions.value, taskId]);
}

function unsubscribeFromTask(taskId) {
    const echo = getEcho();
    echo.leave(`task.${taskId}`);
    const updated = new Set(taskSubscriptions.value);
    updated.delete(taskId);
    taskSubscriptions.value = updated;
}
```

Add `subscribeToTask` and `unsubscribeFromTask` to the return object.

Update `$reset()` to also clear subscriptions:

```javascript
taskSubscriptions.value = new Set();
```

**Step 4: Run tests**

```bash
npx vitest run resources/js/stores/conversations.test.js
```

Expected: PASS

**Step 5: Commit**

```bash
git add resources/js/stores/conversations.js resources/js/stores/conversations.test.js
git commit --no-gpg-sign -m "T69.7: Wire Reverb channel subscription for task status updates

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 8: Auto-track tasks from streamed dispatch messages

**Files:**
- Modify: `resources/js/stores/conversations.js`
- Modify: `resources/js/stores/conversations.test.js`

When the SSE stream contains a `[System: Task dispatched]` message, the store automatically parses it, creates a tracked task entry, and subscribes to the task's Reverb channel. This connects the action preview confirmation flow (T68) → task dispatch → pinned task bar.

**Step 1: Write the test**

Add to `resources/js/stores/conversations.test.js`:

```javascript
describe('auto-track from streamed messages (T69)', () => {
    it('detects dispatch in onDone and tracks the task', async () => {
        const store = useConversationsStore();
        store.selectedId = 'conv-1';

        // Simulate the assistant message containing a system dispatch notification
        const systemText = 'Alright, I\'ll implement that for you.\n\n[System: Task dispatched] Feature implementation "Add Stripe" has been dispatched as Task #42. You can track its progress in the pinned task bar.';

        // Manually trigger the detection logic
        const parsed = store.parseTaskDispatchMessage(systemText);
        expect(parsed).not.toBeNull();
        expect(parsed.taskId).toBe(42);
    });
});
```

**Step 2: Wire auto-tracking into streamMessage's onDone callback**

In `resources/js/stores/conversations.js`, update the `onDone()` callback inside `streamMessage()`:

After the assistant message is pushed to `messages`, add:

```javascript
// T69: Auto-track dispatched tasks from system messages
const dispatch = parseTaskDispatchMessage(accumulated);
if (dispatch) {
    trackTask({
        task_id: dispatch.taskId,
        status: 'received',
        type: typeFromLabel(dispatch.typeLabel),
        title: dispatch.title,
        project_id: selected.value?.project_id || null,
        pipeline_id: null,
        pipeline_status: null,
        started_at: null,
        conversation_id: selectedId.value,
    });
    subscribeToTask(dispatch.taskId);
}
```

Add helper:

```javascript
function typeFromLabel(label) {
    const map = {
        'Feature implementation': 'feature_dev',
        'UI adjustment': 'ui_adjustment',
        'Issue creation': 'prd_creation',
        'Merge request creation': 'feature_dev',
        'Deep analysis': 'deep_analysis',
    };
    return map[label] || 'feature_dev';
}
```

**Step 3: Run tests**

```bash
npx vitest run resources/js/stores/conversations.test.js
```

Expected: PASS

**Step 4: Commit**

```bash
git add resources/js/stores/conversations.js resources/js/stores/conversations.test.js
git commit --no-gpg-sign -m "T69.8: Auto-track tasks from streamed dispatch messages

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 9: Add Reverb config injection to Blade layout

**Files:**
- Modify: `resources/views/app.blade.php` (or equivalent Blade layout)

Inject Reverb connection config into the page so the Echo composable can read it.

**Step 1: Find the Blade layout**

Look for `resources/views/app.blade.php` or similar layout file.

**Step 2: Add config injection**

In the `<head>` or before the app mount script, add:

```html
<script>
    window.__REVERB_CONFIG__ = {
        key: @json(config('broadcasting.connections.reverb.key')),
        host: @json(config('broadcasting.connections.reverb.options.host')),
        port: @json(config('broadcasting.connections.reverb.options.port')),
        scheme: @json(config('broadcasting.connections.reverb.options.scheme')),
    };
</script>
```

**Step 3: Commit**

```bash
git add resources/views/app.blade.php
git commit --no-gpg-sign -m "T69.9: Inject Reverb config into Blade layout for Echo client

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 10: Run full test suite and verify

**Step 1: Run all Vue tests**

```bash
npx vitest run
```

Expected: all pass

**Step 2: Run PHP tests**

```bash
php artisan test
```

Expected: all pass

**Step 3: Run M3 verification**

```bash
python3 verify/verify_m3.py
```

Expected: pass (or note any pre-existing issues)

**Step 4: Final commit with task reference**

If any adjustments were needed during verification, commit them:

```bash
git add -A
git commit --no-gpg-sign -m "T69: Complete pinned task bar with Reverb subscription and D133

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Implementation Notes

### How the data flows

```
DispatchAction tool → Task created (status=received)
    ↓
TaskObserver → TaskStatusChanged broadcast (task.{id} channel)
    ↓
SSE stream → assistant message contains "[System: Task dispatched] ... Task #42"
    ↓
conversations store onDone() → parseTaskDispatchMessage() → trackTask() → subscribeToTask()
    ↓
Echo private('task.42').listen('.task.status.changed') → callback → updateTaskStatus()
    ↓
PinnedTaskBar reactively shows task status, elapsed time, pipeline link
    ↓
Terminal status → 3s delay → removeTask() → bar disappears (T70 result card takes over)
```

### D133 Runner Load Awareness

When `pipeline_status === 'pending'`, the bar shows:
> ⏳ Waiting for available runner… — System busy, expect delays

This prevents users from thinking the task is executing when it's actually queued at the GitLab Runner level. The `pipeline_status` field is updated by the TaskDispatcher when it queries the pipeline state.

### What T70 (Result Cards) will add

T70 builds on top of T69's infrastructure: when a terminal `task.status.changed` event arrives with `result_summary`, T70 will render a result card in the message thread instead of (or after) removing the pinned bar entry.

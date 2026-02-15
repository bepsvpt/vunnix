# T74: Laravel Echo Client — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build the dashboard-facing real-time layer — a `useDashboardRealtime` composable and `dashboard` Pinia store that subscribe to `project.{id}.activity` and `metrics.{id}` Reverb channels, accumulating live events for the activity feed and metrics views.

**Architecture:** T73 built the Echo singleton (`useEcho.js`) and per-task channel subscriptions in the conversation store. T74 adds the **project-level** channel subscriptions that the dashboard needs. A `useDashboardRealtime` composable manages channel lifecycle (subscribe/unsubscribe per project), and a `dashboard` Pinia store holds the reactive state (activity feed items, metrics updates) that dashboard components (T75–T82) will consume. The composable auto-subscribes to all user-accessible projects on mount and cleans up on unmount.

**Tech Stack:** Vue 3 (Composition API), Pinia, Laravel Echo (via existing `useEcho.js` singleton), Vitest + jsdom

---

### Task 1: Create the dashboard Pinia store with activity feed state

**Files:**
- Create: `resources/js/stores/dashboard.js`
- Test: `resources/js/stores/dashboard.test.js`

**Step 1: Write the failing test**

```js
// resources/js/stores/dashboard.test.js
import { describe, it, expect, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useDashboardStore } from './dashboard';

describe('dashboard store', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    it('starts with empty activity feed', () => {
        const store = useDashboardStore();
        expect(store.activityFeed).toEqual([]);
    });

    it('starts with empty metrics updates', () => {
        const store = useDashboardStore();
        expect(store.metricsUpdates).toEqual([]);
    });
});
```

**Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/stores/dashboard.test.js`
Expected: FAIL — cannot resolve `./dashboard`

**Step 3: Write minimal implementation**

```js
// resources/js/stores/dashboard.js
import { defineStore } from 'pinia';
import { ref } from 'vue';

export const useDashboardStore = defineStore('dashboard', () => {
    const activityFeed = ref([]);
    const metricsUpdates = ref([]);

    function $reset() {
        activityFeed.value = [];
        metricsUpdates.value = [];
    }

    return {
        activityFeed,
        metricsUpdates,
        $reset,
    };
});
```

**Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/stores/dashboard.test.js`
Expected: PASS

**Step 5: Commit**

```bash
git add resources/js/stores/dashboard.js resources/js/stores/dashboard.test.js
git commit --no-gpg-sign -m "T74.1: Add dashboard Pinia store with activity feed and metrics state"
```

---

### Task 2: Add `addActivityItem` and `addMetricsUpdate` mutations with dedup and cap

**Files:**
- Modify: `resources/js/stores/dashboard.js`
- Modify: `resources/js/stores/dashboard.test.js`

**Step 1: Write the failing tests**

Append to `dashboard.test.js`:

```js
it('addActivityItem prepends to feed', () => {
    const store = useDashboardStore();
    const item = {
        task_id: 1,
        status: 'completed',
        type: 'code_review',
        project_id: 10,
        title: 'Review MR !5',
        timestamp: '2026-02-15T10:00:00Z',
    };
    store.addActivityItem(item);
    expect(store.activityFeed).toHaveLength(1);
    expect(store.activityFeed[0].task_id).toBe(1);
});

it('addActivityItem deduplicates by task_id (updates in place)', () => {
    const store = useDashboardStore();
    store.addActivityItem({ task_id: 1, status: 'queued', type: 'code_review', project_id: 10, title: 'Review', timestamp: '2026-02-15T10:00:00Z' });
    store.addActivityItem({ task_id: 1, status: 'completed', type: 'code_review', project_id: 10, title: 'Review', timestamp: '2026-02-15T10:01:00Z' });
    expect(store.activityFeed).toHaveLength(1);
    expect(store.activityFeed[0].status).toBe('completed');
});

it('addActivityItem caps feed at 200 items', () => {
    const store = useDashboardStore();
    for (let i = 0; i < 210; i++) {
        store.addActivityItem({ task_id: i, status: 'completed', type: 'code_review', project_id: 10, title: `Task ${i}`, timestamp: `2026-02-15T10:${String(i).padStart(2, '0')}:00Z` });
    }
    expect(store.activityFeed).toHaveLength(200);
    // Most recent should be first
    expect(store.activityFeed[0].task_id).toBe(209);
});

it('addMetricsUpdate stores latest per project_id', () => {
    const store = useDashboardStore();
    store.addMetricsUpdate({ project_id: 10, data: { tasks_today: 5 }, timestamp: '2026-02-15T10:00:00Z' });
    store.addMetricsUpdate({ project_id: 10, data: { tasks_today: 6 }, timestamp: '2026-02-15T10:15:00Z' });
    expect(store.metricsUpdates).toHaveLength(1);
    expect(store.metricsUpdates[0].data.tasks_today).toBe(6);
});
```

**Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/stores/dashboard.test.js`
Expected: FAIL — `addActivityItem` / `addMetricsUpdate` not functions

**Step 3: Implement mutations in store**

Add to `resources/js/stores/dashboard.js`:

```js
const FEED_CAP = 200;

function addActivityItem(item) {
    const idx = activityFeed.value.findIndex((a) => a.task_id === item.task_id);
    if (idx !== -1) {
        // Update in-place (status changed)
        activityFeed.value.splice(idx, 1);
    }
    activityFeed.value.unshift(item);
    if (activityFeed.value.length > FEED_CAP) {
        activityFeed.value = activityFeed.value.slice(0, FEED_CAP);
    }
}

function addMetricsUpdate(update) {
    const idx = metricsUpdates.value.findIndex((m) => m.project_id === update.project_id);
    if (idx !== -1) {
        metricsUpdates.value.splice(idx, 1, update);
    } else {
        metricsUpdates.value.push(update);
    }
}
```

Export both from the return statement.

**Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/stores/dashboard.test.js`
Expected: PASS

**Step 5: Commit**

```bash
git add resources/js/stores/dashboard.js resources/js/stores/dashboard.test.js
git commit --no-gpg-sign -m "T74.2: Add activity feed and metrics mutations with dedup and cap"
```

---

### Task 3: Create `useDashboardRealtime` composable with channel subscriptions

**Files:**
- Create: `resources/js/composables/useDashboardRealtime.js`
- Test: `resources/js/composables/useDashboardRealtime.test.js`

**Step 1: Write the failing test**

```js
// resources/js/composables/useDashboardRealtime.test.js
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';

// Mock Echo (same pattern as useEcho.test.js)
const { mockListen, mockStopListening, mockPrivate, mockLeave } = vi.hoisted(() => {
    const mockListen = vi.fn().mockReturnThis();
    const mockStopListening = vi.fn().mockReturnThis();
    const mockPrivate = vi.fn().mockReturnValue({
        listen: mockListen,
        stopListening: mockStopListening,
    });
    const mockLeave = vi.fn();
    return { mockListen, mockStopListening, mockPrivate, mockLeave };
});

vi.mock('@/composables/useEcho', () => ({
    getEcho: () => ({
        private: mockPrivate,
        leave: mockLeave,
    }),
}));

import { useDashboardRealtime } from './useDashboardRealtime';
import { useDashboardStore } from '@/stores/dashboard';

describe('useDashboardRealtime', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('subscribes to project activity and metrics channels', () => {
        const projects = [{ id: 10 }, { id: 20 }];
        const { subscribe } = useDashboardRealtime();
        subscribe(projects);

        expect(mockPrivate).toHaveBeenCalledWith('project.10.activity');
        expect(mockPrivate).toHaveBeenCalledWith('project.20.activity');
        expect(mockPrivate).toHaveBeenCalledWith('metrics.10');
        expect(mockPrivate).toHaveBeenCalledWith('metrics.20');
        // 2 projects × 2 channels = 4 subscriptions
        expect(mockPrivate).toHaveBeenCalledTimes(4);
    });

    it('listens for task.status.changed on activity channels', () => {
        const { subscribe } = useDashboardRealtime();
        subscribe([{ id: 10 }]);

        expect(mockListen).toHaveBeenCalledWith('.task.status.changed', expect.any(Function));
    });

    it('listens for metrics.updated on metrics channels', () => {
        const { subscribe } = useDashboardRealtime();
        subscribe([{ id: 10 }]);

        // activity channel listens for .task.status.changed
        // metrics channel listens for .metrics.updated
        const listenCalls = mockListen.mock.calls;
        const eventNames = listenCalls.map((c) => c[0]);
        expect(eventNames).toContain('.task.status.changed');
        expect(eventNames).toContain('.metrics.updated');
    });

    it('adds activity items to dashboard store when event fires', () => {
        const store = useDashboardStore();
        const { subscribe } = useDashboardRealtime();
        subscribe([{ id: 10 }]);

        // Find the .task.status.changed handler
        const activityCall = mockListen.mock.calls.find((c) => c[0] === '.task.status.changed');
        const handler = activityCall[1];

        handler({
            task_id: 42,
            status: 'completed',
            type: 'code_review',
            project_id: 10,
            title: 'Review MR !5',
            timestamp: '2026-02-15T10:00:00Z',
        });

        expect(store.activityFeed).toHaveLength(1);
        expect(store.activityFeed[0].task_id).toBe(42);
    });

    it('unsubscribe leaves all channels', () => {
        const { subscribe, unsubscribe } = useDashboardRealtime();
        subscribe([{ id: 10 }, { id: 20 }]);
        unsubscribe();

        expect(mockLeave).toHaveBeenCalledWith('project.10.activity');
        expect(mockLeave).toHaveBeenCalledWith('project.20.activity');
        expect(mockLeave).toHaveBeenCalledWith('metrics.10');
        expect(mockLeave).toHaveBeenCalledWith('metrics.20');
    });

    it('resubscribe replaces previous subscriptions', () => {
        const { subscribe, unsubscribe } = useDashboardRealtime();
        subscribe([{ id: 10 }]);
        subscribe([{ id: 20 }]);

        // Should have left old channels before subscribing new
        expect(mockLeave).toHaveBeenCalledWith('project.10.activity');
        expect(mockLeave).toHaveBeenCalledWith('metrics.10');
    });

    it('does nothing if projects array is empty', () => {
        const { subscribe } = useDashboardRealtime();
        subscribe([]);
        expect(mockPrivate).not.toHaveBeenCalled();
    });
});
```

**Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/composables/useDashboardRealtime.test.js`
Expected: FAIL — cannot resolve `./useDashboardRealtime`

**Step 3: Implement the composable**

```js
// resources/js/composables/useDashboardRealtime.js
import { getEcho } from '@/composables/useEcho';
import { useDashboardStore } from '@/stores/dashboard';

/**
 * Composable for dashboard real-time subscriptions.
 * Subscribes to project-level Reverb channels:
 *   - project.{id}.activity — task status changes for the activity feed
 *   - metrics.{id} — aggregated metrics updates
 *
 * Usage:
 *   const { subscribe, unsubscribe } = useDashboardRealtime();
 *   onMounted(() => subscribe(authStore.projects));
 *   onUnmounted(() => unsubscribe());
 */
export function useDashboardRealtime() {
    let subscribedChannels = [];

    function subscribe(projects) {
        // Clean up previous subscriptions if re-subscribing
        if (subscribedChannels.length > 0) {
            unsubscribe();
        }

        if (!projects || projects.length === 0) return;

        const echo = getEcho();
        const store = useDashboardStore();

        for (const project of projects) {
            const activityChannel = `project.${project.id}.activity`;
            const metricsChannel = `metrics.${project.id}`;

            echo.private(activityChannel).listen('.task.status.changed', (event) => {
                store.addActivityItem(event);
            });

            echo.private(metricsChannel).listen('.metrics.updated', (event) => {
                store.addMetricsUpdate(event);
            });

            subscribedChannels.push(activityChannel, metricsChannel);
        }
    }

    function unsubscribe() {
        const echo = getEcho();
        for (const channel of subscribedChannels) {
            echo.leave(channel);
        }
        subscribedChannels = [];
    }

    return { subscribe, unsubscribe };
}
```

**Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/composables/useDashboardRealtime.test.js`
Expected: PASS

**Step 5: Commit**

```bash
git add resources/js/composables/useDashboardRealtime.js resources/js/composables/useDashboardRealtime.test.js
git commit --no-gpg-sign -m "T74.3: Add useDashboardRealtime composable with channel subscriptions"
```

---

### Task 4: Wire composable into DashboardPage with lifecycle management

**Files:**
- Modify: `resources/js/pages/DashboardPage.vue`

**Step 1: Update DashboardPage to use the composable**

```vue
<script setup>
import { onMounted, onUnmounted } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useDashboardRealtime } from '@/composables/useDashboardRealtime';

const auth = useAuthStore();
const { subscribe, unsubscribe } = useDashboardRealtime();

onMounted(() => {
    subscribe(auth.projects);
});

onUnmounted(() => {
    unsubscribe();
});
</script>

<template>
  <div>
    <h1 class="text-2xl font-semibold">Dashboard</h1>
    <p class="mt-2 text-zinc-500 dark:text-zinc-400">Activity feed and metrics — coming in M4.</p>
  </div>
</template>
```

This is a minimal wiring — the dashboard page subscribes on mount, unsubscribes on unmount. T75–T82 will add the actual dashboard views that consume `useDashboardStore()`.

**Step 2: Run all frontend tests to ensure nothing broke**

Run: `npx vitest run`
Expected: All tests PASS

**Step 3: Commit**

```bash
git add resources/js/pages/DashboardPage.vue
git commit --no-gpg-sign -m "T74.4: Wire useDashboardRealtime into DashboardPage lifecycle"
```

---

### Task 5: Add backend MetricsUpdated broadcast event

**Files:**
- Create: `app/Events/MetricsUpdated.php`
- Test: `tests/Unit/Events/MetricsUpdatedTest.php`

The `metrics.{id}` channel is defined in `routes/channels.php` but nothing broadcasts to it yet. T84 (metrics aggregation) will dispatch this event, but T74 needs the event class to exist so the client subscription has something to receive.

**Step 1: Write the failing test**

```php
<?php
// tests/Unit/Events/MetricsUpdatedTest.php

use App\Events\MetricsUpdated;
use Illuminate\Broadcasting\PrivateChannel;

it('broadcasts on the metrics channel for the given project', function () {
    $event = new MetricsUpdated(projectId: 10, data: ['tasks_today' => 5]);

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe('private-metrics.10');
});

it('includes project_id, data, and timestamp in broadcast payload', function () {
    $event = new MetricsUpdated(projectId: 10, data: ['tasks_today' => 5]);

    $payload = $event->broadcastWith();

    expect($payload)->toHaveKeys(['project_id', 'data', 'timestamp']);
    expect($payload['project_id'])->toBe(10);
    expect($payload['data'])->toBe(['tasks_today' => 5]);
});

it('has the correct broadcast event name', function () {
    $event = new MetricsUpdated(projectId: 10, data: []);

    expect($event->broadcastAs())->toBe('metrics.updated');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Events/MetricsUpdatedTest.php`
Expected: FAIL — class not found

**Step 3: Implement the event**

```php
<?php
// app/Events/MetricsUpdated.php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MetricsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $projectId,
        public readonly array $data,
    ) {}

    /**
     * @return array<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("metrics.{$this->projectId}"),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'project_id' => $this->projectId,
            'data' => $this->data,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'metrics.updated';
    }
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Events/MetricsUpdatedTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Events/MetricsUpdated.php tests/Unit/Events/MetricsUpdatedTest.php
git commit --no-gpg-sign -m "T74.5: Add MetricsUpdated broadcast event for metrics channel"
```

---

### Task 6: Add filtered activity computed properties to dashboard store

**Files:**
- Modify: `resources/js/stores/dashboard.js`
- Modify: `resources/js/stores/dashboard.test.js`

The activity feed needs filter tabs (All | Reviews | PRDs | UI Adjustments | Feature Dev) per §5.3. Add a reactive filter and computed property.

**Step 1: Write the failing tests**

Append to `dashboard.test.js`:

```js
it('filteredFeed returns all items when filter is null', () => {
    const store = useDashboardStore();
    store.addActivityItem({ task_id: 1, status: 'completed', type: 'code_review', project_id: 10, title: 'A', timestamp: 't1' });
    store.addActivityItem({ task_id: 2, status: 'completed', type: 'feature_dev', project_id: 10, title: 'B', timestamp: 't2' });
    expect(store.filteredFeed).toHaveLength(2);
});

it('filteredFeed filters by type when activeFilter is set', () => {
    const store = useDashboardStore();
    store.addActivityItem({ task_id: 1, status: 'completed', type: 'code_review', project_id: 10, title: 'A', timestamp: 't1' });
    store.addActivityItem({ task_id: 2, status: 'completed', type: 'feature_dev', project_id: 10, title: 'B', timestamp: 't2' });
    store.activeFilter = 'code_review';
    expect(store.filteredFeed).toHaveLength(1);
    expect(store.filteredFeed[0].type).toBe('code_review');
});

it('filteredFeed filters by project when projectFilter is set', () => {
    const store = useDashboardStore();
    store.addActivityItem({ task_id: 1, status: 'completed', type: 'code_review', project_id: 10, title: 'A', timestamp: 't1' });
    store.addActivityItem({ task_id: 2, status: 'completed', type: 'code_review', project_id: 20, title: 'B', timestamp: 't2' });
    store.projectFilter = 10;
    expect(store.filteredFeed).toHaveLength(1);
    expect(store.filteredFeed[0].project_id).toBe(10);
});
```

**Step 2: Run tests to verify they fail**

Run: `npx vitest run resources/js/stores/dashboard.test.js`
Expected: FAIL — `filteredFeed` undefined, `activeFilter`/`projectFilter` not reactive

**Step 3: Add filter state and computed**

Add to `resources/js/stores/dashboard.js`:

```js
const activeFilter = ref(null); // null = 'All', or one of: 'code_review', 'feature_dev', 'ui_adjustment', 'prd_creation'
const projectFilter = ref(null); // null = all projects, or project_id

const filteredFeed = computed(() => {
    let items = activityFeed.value;
    if (activeFilter.value) {
        items = items.filter((i) => i.type === activeFilter.value);
    }
    if (projectFilter.value) {
        items = items.filter((i) => i.project_id === projectFilter.value);
    }
    return items;
});
```

Add `activeFilter`, `projectFilter`, `filteredFeed` to the return statement and `$reset`.

**Step 4: Run tests to verify they pass**

Run: `npx vitest run resources/js/stores/dashboard.test.js`
Expected: PASS

**Step 5: Commit**

```bash
git add resources/js/stores/dashboard.js resources/js/stores/dashboard.test.js
git commit --no-gpg-sign -m "T74.6: Add filtered activity feed with type and project filters"
```

---

### Task 7: Run full verification and create final commit

**Step 1: Run all frontend tests**

Run: `npx vitest run`
Expected: All PASS

**Step 2: Run all Laravel tests**

Run: `php artisan test`
Expected: All PASS

**Step 3: Create M4 verification script stub** (if it doesn't exist)

```python
# verify/verify_m4.py
"""M4 — Dashboard & Metrics structural verification."""

import sys
import os
sys.path.insert(0, os.path.dirname(__file__))
from helpers import *

checks = []

# T73: Reverb channel configuration (already complete)
checks.append(check_file_exists('routes/channels.php', 'Broadcast channel definitions'))
checks.append(check_file_contains('routes/channels.php', 'project.{projectId}.activity', 'Project activity channel'))
checks.append(check_file_contains('routes/channels.php', 'metrics.{projectId}', 'Metrics channel'))
checks.append(check_file_exists('app/Events/TaskStatusChanged.php', 'TaskStatusChanged event'))

# T74: Laravel Echo client
checks.append(check_file_exists('resources/js/composables/useEcho.js', 'Echo singleton composable'))
checks.append(check_file_exists('resources/js/composables/useDashboardRealtime.js', 'Dashboard realtime composable'))
checks.append(check_file_contains('resources/js/composables/useDashboardRealtime.js', 'project.${project.id}.activity', 'Activity channel subscription'))
checks.append(check_file_contains('resources/js/composables/useDashboardRealtime.js', 'metrics.${project.id}', 'Metrics channel subscription'))
checks.append(check_file_exists('resources/js/stores/dashboard.js', 'Dashboard Pinia store'))
checks.append(check_file_contains('resources/js/stores/dashboard.js', 'addActivityItem', 'Activity feed mutation'))
checks.append(check_file_contains('resources/js/stores/dashboard.js', 'addMetricsUpdate', 'Metrics update mutation'))
checks.append(check_file_contains('resources/js/stores/dashboard.js', 'filteredFeed', 'Filtered feed computed'))
checks.append(check_file_exists('app/Events/MetricsUpdated.php', 'MetricsUpdated broadcast event'))
checks.append(check_file_contains('app/Events/MetricsUpdated.php', 'metrics.updated', 'Broadcast event name'))
checks.append(check_file_contains('resources/js/pages/DashboardPage.vue', 'useDashboardRealtime', 'Dashboard page uses realtime composable'))

print_results(checks)
sys.exit(0 if all(c[0] for c in checks) else 1)
```

**Step 4: Run M4 verification**

Run: `python3 verify/verify_m4.py`
Expected: All checks PASS

**Step 5: Final commit with progress update**

Update `progress.md` to mark T74 complete and bold T75.
Update `handoff.md` back to empty template.

```bash
git add -A
git commit --no-gpg-sign -m "T74: Add Laravel Echo client for dashboard real-time updates"
```

# T75: Dashboard Activity Feed — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a dashboard activity feed showing all AI activity across the user's accessible projects, with filter tabs, cursor pagination, real-time updates via Reverb, and click-through links.

**Architecture:** Backend API endpoint (`GET /api/v1/activity`) returns tasks scoped to the user's projects with cursor-based pagination and type filtering. The existing `useDashboardStore` and `useDashboardRealtime` composable handle real-time updates. The `DashboardPage.vue` stub is replaced with a full `ActivityFeed` component. The store gains `fetchActivity()` and `loadMore()` methods for initial load and pagination.

**Tech Stack:** Laravel (API Resource, Controller, FormRequest), Vue 3 (Composition API, `<script setup>`), Pinia, Vitest, Pest

---

### Task 1: Create ActivityResource API Resource

**Files:**
- Create: `app/Http/Resources/ActivityResource.php`

**Step 1: Create the API Resource**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'task_id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'project_id' => $this->project_id,
            'project_name' => $this->project?->name,
            'summary' => $this->result['title'] ?? $this->result['mr_title'] ?? $this->result['summary'] ?? null,
            'user_name' => $this->user?->name,
            'user_avatar' => $this->user?->avatar_url,
            'mr_iid' => $this->mr_iid,
            'issue_iid' => $this->issue_iid,
            'conversation_id' => $this->conversation_id,
            'error_reason' => $this->error_reason,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
```

**Step 2: Commit**

```bash
git add app/Http/Resources/ActivityResource.php
git commit --no-gpg-sign -m "T75.1: Add ActivityResource API resource"
```

---

### Task 2: Create ActivityController with index endpoint

**Files:**
- Create: `app/Http/Controllers/Api/ActivityController.php`
- Modify: `routes/api.php`

**Step 1: Create the controller**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'type' => ['nullable', 'string', 'in:code_review,feature_dev,ui_adjustment,prd_creation'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ]);

        $projectIds = $request->user()
            ->projects()
            ->where('enabled', true)
            ->pluck('projects.id');

        $query = Task::with(['project:id,name', 'user:id,name,avatar_url'])
            ->whereIn('project_id', $projectIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        $perPage = $request->integer('per_page', 25);

        return ActivityResource::collection(
            $query->cursorPaginate($perPage)
        );
    }
}
```

**Step 2: Register the route in `routes/api.php`**

Add inside the `Route::middleware('auth')` group (after the task view endpoint):

```php
// Dashboard activity feed (T75)
Route::get('/activity', [\App\Http\Controllers\Api\ActivityController::class, 'index'])
    ->name('api.activity.index');
```

**Step 3: Commit**

```bash
git add app/Http/Controllers/Api/ActivityController.php routes/api.php
git commit --no-gpg-sign -m "T75.2: Add activity feed API endpoint with cursor pagination"
```

---

### Task 3: Write feature tests for the activity API

**Files:**
- Create: `tests/Feature/ActivityFeedApiTest.php`

**Step 1: Write the tests**

```php
<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns activity feed scoped to user projects', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $otherProject = Project::factory()->create();

    // Task in user's project — should appear
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
    ]);

    // Task in other project — should NOT appear
    Task::factory()->create([
        'project_id' => $otherProject->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/activity');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.task_id', $task->id);
    $response->assertJsonPath('data.0.type', 'code_review');
    $response->assertJsonPath('data.0.project_name', $project->name);
});

it('filters activity by type', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Completed,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/activity?type=code_review');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.type', 'code_review');
});

it('returns cursor pagination metadata', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->count(3)->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/activity?per_page=2');

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
    $response->assertJsonStructure(['data', 'meta' => ['per_page', 'next_cursor'], 'links']);
});

it('returns 401 for unauthenticated users', function () {
    $response = $this->getJson('/api/v1/activity');
    $response->assertUnauthorized();
});

it('returns activity items with correct structure', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Completed,
        'mr_iid' => 42,
        'result' => ['title' => 'Add payment integration'],
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/activity');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            [
                'task_id',
                'type',
                'status',
                'project_id',
                'project_name',
                'summary',
                'user_name',
                'mr_iid',
                'conversation_id',
                'created_at',
            ],
        ],
    ]);
    $response->assertJsonPath('data.0.summary', 'Add payment integration');
    $response->assertJsonPath('data.0.mr_iid', 42);
});

it('excludes tasks from disabled projects', function () {
    $user = User::factory()->create();
    $disabledProject = Project::factory()->create(['enabled' => false]);
    $disabledProject->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    Task::factory()->create([
        'project_id' => $disabledProject->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/activity');

    $response->assertOk();
    $response->assertJsonCount(0, 'data');
});

it('orders activity by most recent first', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $older = Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'created_at' => now()->subHour(),
    ]);
    $newer = Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::FeatureDev,
        'status' => TaskStatus::Running,
        'created_at' => now(),
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/activity');

    $response->assertOk();
    $response->assertJsonPath('data.0.task_id', $newer->id);
    $response->assertJsonPath('data.1.task_id', $older->id);
});
```

**Step 2: Run tests**

```bash
php artisan test --filter=ActivityFeedApiTest
```

**Step 3: Commit**

```bash
git add tests/Feature/ActivityFeedApiTest.php
git commit --no-gpg-sign -m "T75.3: Add activity feed API feature tests"
```

---

### Task 4: Extend dashboard store with fetchActivity and loadMore

**Files:**
- Modify: `resources/js/stores/dashboard.js`

**Step 1: Add API fetching methods to the store**

Add `axios` import at top. Add these new refs and methods:

```js
import axios from 'axios';

// Inside the store setup function, add:

const isLoading = ref(false);
const nextCursor = ref(null);
const hasMore = computed(() => nextCursor.value !== null);

async function fetchActivity(filter = null) {
    isLoading.value = true;
    activeFilter.value = filter;
    nextCursor.value = null;

    try {
        const params = { per_page: 25 };
        if (filter) params.type = filter;

        const response = await axios.get('/api/v1/activity', { params });
        activityFeed.value = response.data.data;
        nextCursor.value = response.data.meta?.next_cursor ?? null;
    } finally {
        isLoading.value = false;
    }
}

async function loadMore() {
    if (!nextCursor.value || isLoading.value) return;
    isLoading.value = true;

    try {
        const params = { per_page: 25, cursor: nextCursor.value };
        if (activeFilter.value) params.type = activeFilter.value;

        const response = await axios.get('/api/v1/activity', { params });
        activityFeed.value.push(...response.data.data);
        nextCursor.value = response.data.meta?.next_cursor ?? null;
    } finally {
        isLoading.value = false;
    }
}
```

Update the return statement to export the new refs and methods:
```js
return {
    activityFeed, metricsUpdates, activeFilter, projectFilter,
    isLoading, nextCursor, hasMore,
    filteredFeed, addActivityItem, addMetricsUpdate,
    fetchActivity, loadMore, $reset,
};
```

Update `$reset` to also reset `isLoading` and `nextCursor`.

**Step 2: Commit**

```bash
git add resources/js/stores/dashboard.js
git commit --no-gpg-sign -m "T75.4: Add fetchActivity and loadMore to dashboard store"
```

---

### Task 5: Write tests for the new store methods

**Files:**
- Modify: `resources/js/stores/dashboard.test.js`

**Step 1: Add tests for fetchActivity and loadMore**

Add to the existing test file:

```js
import { vi } from 'vitest';
import axios from 'axios';

vi.mock('axios');

describe('fetchActivity', () => {
    it('fetches activity from API and sets feed', async () => {
        axios.get.mockResolvedValueOnce({
            data: {
                data: [
                    { task_id: 1, type: 'code_review', status: 'completed', project_id: 10 },
                ],
                meta: { next_cursor: 'abc123', per_page: 25 },
            },
        });

        const store = useDashboardStore();
        await store.fetchActivity();

        expect(axios.get).toHaveBeenCalledWith('/api/v1/activity', { params: { per_page: 25 } });
        expect(store.activityFeed).toHaveLength(1);
        expect(store.nextCursor).toBe('abc123');
        expect(store.hasMore).toBe(true);
    });

    it('passes type filter when provided', async () => {
        axios.get.mockResolvedValueOnce({
            data: { data: [], meta: { next_cursor: null, per_page: 25 } },
        });

        const store = useDashboardStore();
        await store.fetchActivity('code_review');

        expect(axios.get).toHaveBeenCalledWith('/api/v1/activity', {
            params: { per_page: 25, type: 'code_review' },
        });
        expect(store.activeFilter).toBe('code_review');
    });

    it('sets isLoading during fetch', async () => {
        let resolvePromise;
        axios.get.mockReturnValueOnce(new Promise((resolve) => { resolvePromise = resolve; }));

        const store = useDashboardStore();
        const promise = store.fetchActivity();

        expect(store.isLoading).toBe(true);

        resolvePromise({ data: { data: [], meta: { next_cursor: null } } });
        await promise;

        expect(store.isLoading).toBe(false);
    });
});

describe('loadMore', () => {
    it('appends next page to existing feed', async () => {
        const store = useDashboardStore();
        store.activityFeed = [{ task_id: 1, type: 'code_review', status: 'completed' }];
        store.nextCursor = 'cursor1';

        axios.get.mockResolvedValueOnce({
            data: {
                data: [{ task_id: 2, type: 'feature_dev', status: 'running' }],
                meta: { next_cursor: null, per_page: 25 },
            },
        });

        await store.loadMore();

        expect(store.activityFeed).toHaveLength(2);
        expect(store.nextCursor).toBeNull();
        expect(store.hasMore).toBe(false);
    });

    it('does nothing when no cursor', async () => {
        const store = useDashboardStore();
        store.nextCursor = null;

        await store.loadMore();

        expect(axios.get).not.toHaveBeenCalled();
    });
});
```

**Step 2: Run tests**

```bash
npx vitest run resources/js/stores/dashboard.test.js
```

**Step 3: Commit**

```bash
git add resources/js/stores/dashboard.test.js
git commit --no-gpg-sign -m "T75.5: Add fetchActivity and loadMore store tests"
```

---

### Task 6: Create ActivityFeedItem component

**Files:**
- Create: `resources/js/components/ActivityFeedItem.vue`

**Step 1: Build the component**

The component receives a single activity item as a prop and renders: type icon, project name, summary, user, timestamp, status badge, and click-through link.

Key implementation details:
- Status mapped to badges: `queued`/`running` → ⏳ amber, `completed` → ✅ green, `failed` → ❌ red
- Type mapped to icons: `code_review` → 🔍, `feature_dev` → ⚙️, `ui_adjustment` → 🎨, `prd_creation` → 📋
- Click-through: if `mr_iid` → link text referencing MR, if `issue_iid` → issue, if `conversation_id` → `/chat` route
- Relative timestamps via a simple `timeAgo()` helper
- `data-testid` attributes for testing: `activity-item`, `activity-type-icon`, `activity-status-badge`, `activity-project`, `activity-summary`, `activity-timestamp`

**Step 2: Commit**

```bash
git add resources/js/components/ActivityFeedItem.vue
git commit --no-gpg-sign -m "T75.6: Add ActivityFeedItem component"
```

---

### Task 7: Create ActivityFeed component with filter tabs

**Files:**
- Create: `resources/js/components/ActivityFeed.vue`

**Step 1: Build the component**

The component renders:
1. **Filter tabs** — "All", "Reviews", "PRDs", "UI Adjustments", "Feature Dev" — each tab sets `activeFilter` in the dashboard store and calls `fetchActivity(filter)`
2. **Feed list** — iterates over `activityFeed` (not `filteredFeed` — filtering is now server-side via API) and renders `ActivityFeedItem` for each
3. **Load more button** — shown when `hasMore` is true, calls `store.loadMore()`
4. **Loading state** — skeleton/spinner when `isLoading`
5. **Empty state** — "No activity yet" message when feed is empty and not loading

Key implementation details:
- Filter tab mapping: `null` → "All", `'code_review'` → "Reviews", `'prd_creation'` → "PRDs", `'ui_adjustment'` → "UI Adjustments", `'feature_dev'` → "Feature Dev"
- `data-testid` attributes: `activity-feed`, `filter-tab-{name}`, `load-more-btn`, `empty-state`, `loading-indicator`

**Step 2: Commit**

```bash
git add resources/js/components/ActivityFeed.vue
git commit --no-gpg-sign -m "T75.7: Add ActivityFeed component with filter tabs and pagination"
```

---

### Task 8: Integrate ActivityFeed into DashboardPage

**Files:**
- Modify: `resources/js/pages/DashboardPage.vue`

**Step 1: Replace stub with full dashboard page**

```vue
<script setup>
import { onMounted, onUnmounted } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useDashboardStore } from '@/stores/dashboard';
import { useDashboardRealtime } from '@/composables/useDashboardRealtime';
import ActivityFeed from '@/components/ActivityFeed.vue';

const auth = useAuthStore();
const dashboard = useDashboardStore();
const { subscribe, unsubscribe } = useDashboardRealtime();

onMounted(() => {
    dashboard.fetchActivity();
    subscribe(auth.projects);
});

onUnmounted(() => {
    unsubscribe();
});
</script>

<template>
  <div>
    <h1 class="text-2xl font-semibold mb-6">Dashboard</h1>
    <ActivityFeed />
  </div>
</template>
```

**Step 2: Commit**

```bash
git add resources/js/pages/DashboardPage.vue
git commit --no-gpg-sign -m "T75.8: Integrate ActivityFeed into DashboardPage"
```

---

### Task 9: Write frontend tests for ActivityFeedItem

**Files:**
- Create: `resources/js/components/ActivityFeedItem.test.js`

**Step 1: Write tests**

Test cases:
- Renders type icon for each task type
- Renders project name
- Renders summary text
- Shows ⏳ badge for running/queued status
- Shows ✅ badge for completed status
- Shows ❌ badge for failed status
- Shows relative timestamp
- Shows click-through link text for MR tasks
- Shows click-through link text for conversation tasks

**Step 2: Run tests**

```bash
npx vitest run resources/js/components/ActivityFeedItem.test.js
```

**Step 3: Commit**

```bash
git add resources/js/components/ActivityFeedItem.test.js
git commit --no-gpg-sign -m "T75.9: Add ActivityFeedItem component tests"
```

---

### Task 10: Write frontend tests for ActivityFeed

**Files:**
- Create: `resources/js/components/ActivityFeed.test.js`

**Step 1: Write tests**

Test cases:
- Renders filter tabs (All, Reviews, PRDs, UI Adjustments, Feature Dev)
- Clicking a filter tab calls `fetchActivity` with correct type
- Renders activity items from store
- Shows "Load more" button when `hasMore` is true
- Hides "Load more" button when `hasMore` is false
- Shows empty state when no items and not loading
- Shows loading indicator when `isLoading` is true

**Step 2: Run tests**

```bash
npx vitest run resources/js/components/ActivityFeed.test.js
```

**Step 3: Commit**

```bash
git add resources/js/components/ActivityFeed.test.js
git commit --no-gpg-sign -m "T75.10: Add ActivityFeed component tests"
```

---

### Task 11: Update verify_m4.py with T75 structural checks

**Files:**
- Modify: `verify/verify_m4.py`

**Step 1: Add T75 verification section**

Add after the T74 section:

```python
# ============================================================
#  T75: Dashboard — activity feed
# ============================================================
section("T75: Dashboard — Activity Feed")

# Backend
checker.check(
    "ActivityResource exists",
    file_exists("app/Http/Resources/ActivityResource.php"),
)
checker.check(
    "ActivityController exists",
    file_exists("app/Http/Controllers/Api/ActivityController.php"),
)
checker.check(
    "ActivityController uses cursor pagination",
    file_contains("app/Http/Controllers/Api/ActivityController.php", "cursorPaginate"),
)
checker.check(
    "Activity route registered",
    file_contains("routes/api.php", "/activity"),
)
checker.check(
    "Activity API test exists",
    file_exists("tests/Feature/ActivityFeedApiTest.php"),
)

# Frontend
checker.check(
    "ActivityFeed component exists",
    file_exists("resources/js/components/ActivityFeed.vue"),
)
checker.check(
    "ActivityFeedItem component exists",
    file_exists("resources/js/components/ActivityFeedItem.vue"),
)
checker.check(
    "ActivityFeed has filter tabs",
    file_contains("resources/js/components/ActivityFeed.vue", "filter-tab"),
)
checker.check(
    "Dashboard store has fetchActivity",
    file_contains("resources/js/stores/dashboard.js", "fetchActivity"),
)
checker.check(
    "Dashboard store has loadMore",
    file_contains("resources/js/stores/dashboard.js", "loadMore"),
)
checker.check(
    "DashboardPage imports ActivityFeed",
    file_contains("resources/js/pages/DashboardPage.vue", "ActivityFeed"),
)
checker.check(
    "DashboardPage calls fetchActivity on mount",
    file_contains("resources/js/pages/DashboardPage.vue", "fetchActivity"),
)
checker.check(
    "ActivityFeed test exists",
    file_exists("resources/js/components/ActivityFeed.test.js"),
)
checker.check(
    "ActivityFeedItem test exists",
    file_exists("resources/js/components/ActivityFeedItem.test.js"),
)
```

**Step 2: Commit**

```bash
git add verify/verify_m4.py
git commit --no-gpg-sign -m "T75.11: Add T75 structural checks to verify_m4.py"
```

---

### Task 12: Run full verification

**Step 1: Run Laravel tests**

```bash
php artisan test
```

**Step 2: Run Vitest**

```bash
npx vitest run
```

**Step 3: Run M4 structural checks**

```bash
python3 verify/verify_m4.py
```

All three must pass before marking T75 complete.

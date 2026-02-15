# T97: Dead Letter Queue — Admin UI Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build the admin UI for browsing, inspecting, retrying, and dismissing dead letter queue entries — completing the DLQ feature started in T96.

**Architecture:** A new `DeadLetterController` exposes 4 REST endpoints under `/api/v1/admin/dead-letter` (list, show, retry, dismiss). The Vue admin page gets a new "Dead Letter" tab rendering an `AdminDeadLetterQueue` component backed by Pinia store actions. Filters for reason, project, and date range are query-parameter-driven.

**Tech Stack:** Laravel 11 (controller + routes), Pest (feature tests), Vue 3 Composition API, Pinia, Vitest + Vue Test Utils

---

### Task 1: Create DeadLetterController with index/show/retry/dismiss endpoints

**Files:**
- Create: `app/Http/Controllers/Api/DeadLetterController.php`

**Action:** Create a controller following the exact pattern in `CostAlertController.php`. The controller has:

- `index(Request)` — returns active DLQ entries with optional filters:
  - `?reason=` — filter by `failure_reason` column
  - `?project_id=` — filter by `task_record->project_id` (JSONB arrow notation for PostgreSQL, fallback to `task_id` relationship for SQLite)
  - `?date_from=` / `?date_to=` — filter by `dead_lettered_at`
  - Orders by `dead_lettered_at` desc, limits to 50
  - Loads the `task` relationship for each entry
- `show(Request, DeadLetterEntry)` — returns single entry with `task`, `dismissedBy`, `retriedBy`, `retriedTask` relationships loaded
- `retry(Request, DeadLetterEntry)` — delegates to `DeadLetterService::retry()`, returns `{success: true, data: newTask}`; catches `LogicException` and returns 422
- `dismiss(Request, DeadLetterEntry)` — delegates to `DeadLetterService::dismiss()`, returns `{success: true}`; catches `LogicException` and returns 422
- `authorizeAdmin(Request)` — private method, same pattern as `CostAlertController`

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeadLetterEntry;
use App\Services\DeadLetterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeadLetterController extends Controller
{
    public function __construct(private readonly DeadLetterService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $query = DeadLetterEntry::active()
            ->with('task')
            ->when($request->filled('reason'), fn ($q) => $q->where('failure_reason', $request->input('reason')))
            ->when($request->filled('date_from'), fn ($q) => $q->where('dead_lettered_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->where('dead_lettered_at', '<=', $request->input('date_to')))
            ->orderByDesc('dead_lettered_at')
            ->limit(50);

        // Filter by project — task_record is JSONB with project_id
        if ($request->filled('project_id')) {
            $projectId = (int) $request->input('project_id');
            if (config('database.default') === 'sqlite' || DB::connection()->getDriverName() === 'sqlite') {
                $query->where('task_record', 'like', '%"project_id":' . $projectId . '%');
            } else {
                $query->whereRaw("(task_record->>'project_id')::int = ?", [$projectId]);
            }
        }

        return response()->json(['data' => $query->get()]);
    }

    public function show(Request $request, DeadLetterEntry $deadLetterEntry): JsonResponse
    {
        $this->authorizeAdmin($request);

        $deadLetterEntry->load(['task', 'dismissedBy', 'retriedBy', 'retriedTask']);

        return response()->json(['data' => $deadLetterEntry]);
    }

    public function retry(Request $request, DeadLetterEntry $deadLetterEntry): JsonResponse
    {
        $this->authorizeAdmin($request);

        try {
            $newTask = $this->service->retry($deadLetterEntry, $request->user());
            return response()->json(['success' => true, 'data' => $newTask]);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function dismiss(Request $request, DeadLetterEntry $deadLetterEntry): JsonResponse
    {
        $this->authorizeAdmin($request);

        try {
            $this->service->dismiss($deadLetterEntry, $request->user());
            return response()->json(['success' => true]);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        $hasAdmin = $user->projects()
            ->where('enabled', true)
            ->get()
            ->contains(fn ($project) => $user->hasPermission('admin.global_config', $project));

        if (! $hasAdmin) {
            abort(403, 'Dead letter queue access is restricted to administrators.');
        }
    }
}
```

**Note:** The route model binding for `DeadLetterEntry` uses the custom table name `dead_letter_queue`. Laravel's implicit binding works on the model's primary key, so the route parameter should be `{deadLetterEntry}` to match the class name convention.

---

### Task 2: Register DLQ admin routes in api.php

**Files:**
- Modify: `routes/api.php`

**Action:** Add 4 routes inside the existing `Route::middleware('auth')` group (after the overreliance-alerts routes, before the PRD template routes). Add the `use` import at the top.

Add import at line 1-21 area:
```php
use App\Http\Controllers\Api\DeadLetterController;
```

Add routes (after line 153, before the PRD template block):
```php
        // Dead letter queue admin (T97) — admin-only via RBAC
        Route::get('/admin/dead-letter', [DeadLetterController::class, 'index'])
            ->name('api.admin.dead-letter.index');
        Route::get('/admin/dead-letter/{deadLetterEntry}', [DeadLetterController::class, 'show'])
            ->name('api.admin.dead-letter.show');
        Route::post('/admin/dead-letter/{deadLetterEntry}/retry', [DeadLetterController::class, 'retry'])
            ->name('api.admin.dead-letter.retry');
        Route::post('/admin/dead-letter/{deadLetterEntry}/dismiss', [DeadLetterController::class, 'dismiss'])
            ->name('api.admin.dead-letter.dismiss');
```

---

### Task 3: Write feature tests for DeadLetterController

**Files:**
- Create: `tests/Feature/Http/Controllers/Api/DeadLetterControllerTest.php`

**Action:** Write Pest feature tests following the exact pattern in `CostAlertControllerTest.php`. Cover the M5 verification scenario: "Browse → list. Inspect → details. Retry → re-queued. Dismiss → acknowledged."

Test cases:
1. `GET /admin/dead-letter` — 401 for unauthenticated
2. `GET /admin/dead-letter` — 403 for non-admin
3. `GET /admin/dead-letter` — returns active entries for admin (excludes dismissed/retried)
4. `GET /admin/dead-letter` — returns empty array when no entries
5. `GET /admin/dead-letter?reason=expired` — filters by failure_reason
6. `GET /admin/dead-letter/{id}` — returns entry with relationships
7. `GET /admin/dead-letter/{id}` — 404 for non-existent entry
8. `POST /admin/dead-letter/{id}/retry` — 401 for unauthenticated
9. `POST /admin/dead-letter/{id}/retry` — 403 for non-admin
10. `POST /admin/dead-letter/{id}/retry` — retries entry, returns new task
11. `POST /admin/dead-letter/{id}/retry` — 422 for already-retried entry
12. `POST /admin/dead-letter/{id}/dismiss` — 401 for unauthenticated
13. `POST /admin/dead-letter/{id}/dismiss` — 403 for non-admin
14. `POST /admin/dead-letter/{id}/dismiss` — dismisses entry
15. `POST /admin/dead-letter/{id}/dismiss` — 422 for already-dismissed entry

Use `DeadLetterEntry::factory()` for test data. Use `Queue::fake()` in retry tests to prevent `ProcessTask` from running inline. Create admin/regular user helpers following the `CostAlertControllerTest` pattern.

The factory's `task_record` must include `project_id` for the entries to work with `DeadLetterService::retry()`. Override the factory state:
```php
DeadLetterEntry::factory()->create([
    'task_id' => $task->id,
    'task_record' => $task->toArray(),
]);
```

---

### Task 4: Add DLQ actions to Pinia admin store

**Files:**
- Modify: `resources/js/stores/admin.js`

**Action:** Add a new DLQ section (after the overreliance alerts section, before the `return` block). Follow the exact pattern of cost alerts — state refs + async actions.

State:
```javascript
// ─── Dead letter queue (T97) ────────────────────────────────
const deadLetterEntries = ref([]);
const deadLetterLoading = ref(false);
const deadLetterError = ref(null);
const deadLetterDetail = ref(null);
const deadLetterDetailLoading = ref(false);
```

Actions:
```javascript
async function fetchDeadLetterEntries(filters = {}) {
    deadLetterLoading.value = true;
    deadLetterError.value = null;
    try {
        const { data } = await axios.get('/api/v1/admin/dead-letter', { params: filters });
        deadLetterEntries.value = data.data;
    } catch (e) {
        deadLetterError.value = 'Failed to load dead letter queue.';
    } finally {
        deadLetterLoading.value = false;
    }
}

async function fetchDeadLetterDetail(entryId) {
    deadLetterDetailLoading.value = true;
    try {
        const { data } = await axios.get(`/api/v1/admin/dead-letter/${entryId}`);
        deadLetterDetail.value = data.data;
    } catch (e) {
        deadLetterError.value = 'Failed to load entry details.';
    } finally {
        deadLetterDetailLoading.value = false;
    }
}

async function retryDeadLetterEntry(entryId) {
    try {
        const { data } = await axios.post(`/api/v1/admin/dead-letter/${entryId}/retry`);
        if (data.success) {
            deadLetterEntries.value = deadLetterEntries.value.filter(e => e.id !== entryId);
            deadLetterDetail.value = null;
        }
        return { success: true, data: data.data };
    } catch (e) {
        return { success: false, error: e.response?.data?.error || 'Failed to retry entry.' };
    }
}

async function dismissDeadLetterEntry(entryId) {
    try {
        await axios.post(`/api/v1/admin/dead-letter/${entryId}/dismiss`);
        deadLetterEntries.value = deadLetterEntries.value.filter(e => e.id !== entryId);
        deadLetterDetail.value = null;
        return { success: true };
    } catch (e) {
        return { success: false, error: e.response?.data?.error || 'Failed to dismiss entry.' };
    }
}
```

Export all new refs and actions in the `return` block.

---

### Task 5: Create AdminDeadLetterQueue Vue component

**Files:**
- Create: `resources/js/components/AdminDeadLetterQueue.vue`

**Action:** Build the DLQ admin UI component following the `AdminProjectList.vue` pattern. Features:

1. **Filter bar** — dropdowns for reason (5 enum values), project (from admin projects list), date range (from/to date inputs)
2. **Entry list** — cards showing: failure_reason badge, task type, project name (from task_record), dead_lettered_at timestamp, error_details preview (truncated), attempt count
3. **Detail view** — clicking an entry shows full context: all task_record fields, full error_details, attempt history timeline, retry/dismiss action buttons
4. **Actions** — Retry button (confirm dialog) and Dismiss button (confirm dialog), with loading states and error handling
5. **States** — loading spinner, empty state ("No failed tasks in the dead letter queue"), error banner

Layout: list view by default. Clicking an entry opens a detail panel (inline expansion or side panel). Back button to return to list.

The component uses `data-testid` attributes for all interactive elements:
- `data-testid="dlq-filter-reason"` — reason dropdown
- `data-testid="dlq-entry-{id}"` — entry row
- `data-testid="dlq-detail-{id}"` — detail view
- `data-testid="dlq-retry-btn-{id}"` — retry button
- `data-testid="dlq-dismiss-btn-{id}"` — dismiss button
- `data-testid="dlq-action-error"` — action error banner
- `data-testid="dlq-empty"` — empty state
- `data-testid="dlq-loading"` — loading state

Failure reason badge colors:
- `max_retries_exceeded` → red
- `expired` → amber
- `invalid_request` → orange
- `context_exceeded` → purple
- `scheduling_timeout` → blue

---

### Task 6: Add "Dead Letter" tab to AdminPage

**Files:**
- Modify: `resources/js/pages/AdminPage.vue`

**Action:**
1. Add import: `import AdminDeadLetterQueue from '@/components/AdminDeadLetterQueue.vue';`
2. Add tab to `tabs` array: `{ key: 'dlq', label: 'Dead Letter' }`
3. Add conditional render in template: `<AdminDeadLetterQueue v-else-if="activeTab === 'dlq'" />`

---

### Task 7: Write Vitest component tests for AdminDeadLetterQueue

**Files:**
- Create: `resources/js/components/AdminDeadLetterQueue.test.js`

**Action:** Follow the `AdminProjectList.test.js` pattern. Tests:

1. Shows loading state while fetching
2. Shows empty state when no entries
3. Renders entry list with failure reason badges
4. Clicking entry shows detail view
5. Retry button calls store action and removes entry from list
6. Dismiss button calls store action and removes entry from list
7. Shows error banner on action failure
8. Filter controls update store fetch params

Setup: `vi.mock('axios')`, create Pinia in `beforeEach`, pre-set `admin.deadLetterEntries` with test data.

---

### Task 8: Add T97 structural checks to verify_m5.py

**Files:**
- Modify: `verify/verify_m5.py`

**Action:** Add a `T97` section before the Summary section (after T96 checks at line 658). Check for:

1. `DeadLetterController` exists
2. Controller has `index`, `show`, `retry`, `dismiss` methods
3. Controller has `authorizeAdmin` method
4. Routes registered: `/admin/dead-letter` appears in `routes/api.php`
5. `AdminDeadLetterQueue.vue` component exists
6. Component has `dlq-retry-btn` and `dlq-dismiss-btn` test IDs
7. Admin store has `fetchDeadLetterEntries` action
8. Admin store has `retryDeadLetterEntry` action
9. Admin store has `dismissDeadLetterEntry` action
10. `AdminPage.vue` includes `dlq` tab
11. Component test file exists
12. Controller test file exists

---

### Task 9: Run verification and fix any issues

**Action:**
```bash
php artisan test --parallel
python3 verify/verify_m5.py
```

Both must pass. Fix any failures before proceeding.

---

### Task 10: Commit

**Action:**
```bash
git add app/Http/Controllers/Api/DeadLetterController.php \
       routes/api.php \
       tests/Feature/Http/Controllers/Api/DeadLetterControllerTest.php \
       resources/js/stores/admin.js \
       resources/js/components/AdminDeadLetterQueue.vue \
       resources/js/components/AdminDeadLetterQueue.test.js \
       resources/js/pages/AdminPage.vue \
       verify/verify_m5.py

git commit --no-gpg-sign -m "$(cat <<'EOF'
T97: Add dead letter queue admin UI

Browse, inspect, retry, and dismiss DLQ entries from the admin dashboard.
New DeadLetterController with 4 endpoints, Vue component with filters,
detail view, and action buttons. Pinia store wired up.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

# T94: Cost Monitoring — 4 Alert Rules

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement 4 cost anomaly alert rules that evaluate after each task completion and on a scheduled basis, surfacing alerts in the admin dashboard.

**Architecture:** A `CostAlertService` evaluates 4 rules against `task_metrics` data. Alerts are stored in a `cost_alerts` table. A new `GET /api/v1/dashboard/cost-alerts` endpoint returns active alerts for the admin cost dashboard. The single-task outlier rule fires synchronously in the `TaskObserver` after metrics recording; the 3 aggregate rules (monthly anomaly, daily spike, approaching projection) run on a scheduled command every 15 minutes alongside metrics aggregation. The Vue `DashboardCost` component gains a new alerts section.

**Tech Stack:** Laravel 11, Pest, Pinia, Vue 3 Composition API, Vitest

**Dependencies:** T85 (cost tracking ✅), T90 (admin settings ✅)

---

### Task 1: Create cost_alerts migration

**Files:**
- Create: `database/migrations/2026_02_15_060000_create_cost_alerts_table.php`

**Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('rule');          // monthly_anomaly, daily_spike, single_task_outlier, approaching_projection
            $table->string('severity');      // warning, critical
            $table->text('message');
            $table->json('context');         // { threshold, actual, period, task_id, etc. }
            $table->boolean('acknowledged')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['acknowledged', 'created_at']);
            $table->index('rule');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_alerts');
    }
};
```

**Step 2: Run migration**

Run: `php artisan migrate`
Expected: Table created successfully

**Step 3: Commit**

```bash
git add database/migrations/2026_02_15_060000_create_cost_alerts_table.php
git commit --no-gpg-sign -m "T94.1: Create cost_alerts migration"
```

---

### Task 2: Create CostAlert model

**Files:**
- Create: `app/Models/CostAlert.php`

**Step 1: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostAlert extends Model
{
    protected $fillable = [
        'rule',
        'severity',
        'message',
        'context',
        'acknowledged',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'acknowledged' => 'boolean',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('acknowledged', false);
    }
}
```

**Step 2: Commit**

```bash
git add app/Models/CostAlert.php
git commit --no-gpg-sign -m "T94.2: Create CostAlert model"
```

---

### Task 3: Create CostAlertService with 4 alert rules

**Files:**
- Create: `app/Services/CostAlertService.php`
- Test: `tests/Unit/Services/CostAlertServiceTest.php`

**Step 1: Write the failing tests**

Test file: `tests/Unit/Services/CostAlertServiceTest.php`

Test cases to cover (from §21.5 verification table):
- Monthly spend > 2× rolling 3-month avg → alert created
- Monthly spend < 2× avg → no alert
- Daily spend > 5× daily avg → alert created
- Daily spend < 5× avg → no alert
- Single task cost > 3× type avg → alert created
- Single task cost < 3× type avg → no alert
- Projected month-end > 2× last month → alert created
- Projected month-end < 2× last month → no alert
- Deduplication: same rule + same day → no duplicate alert

These are **pure unit tests** — mock `DB` facade calls with Mockery. Do NOT use `uses(TestCase::class)`.

The service methods to test:
- `evaluateMonthlyAnomaly(): ?CostAlert` — checks current month vs 3-month rolling average
- `evaluateDailySpike(): ?CostAlert` — checks today's spend vs daily average
- `evaluateSingleTaskOutlier(Task $task): ?CostAlert` — checks one task vs type average
- `evaluateApproachingProjection(): ?CostAlert` — projects month-end vs last month
- `evaluateAll(): array` — runs all 3 aggregate rules (not single-task)

**Important design notes:**
- All queries go through `task_metrics` table (not materialized views) for consistency in SQLite tests.
- The service accepts an optional `now` parameter for testability (defaults to `now()`).
- Dedup: Before creating an alert, check if the same `rule` already has an unacknowledged alert created today.

**Step 2: Run tests — expect failures**

Run: `php artisan test --filter=CostAlertServiceTest`
Expected: FAIL — class not found

**Step 3: Implement CostAlertService**

```php
<?php

namespace App\Services;

use App\Models\CostAlert;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CostAlertService
{
    /**
     * Evaluate all aggregate alert rules (monthly, daily, projection).
     * Does NOT include single-task outlier — that runs per-task.
     *
     * @return CostAlert[]
     */
    public function evaluateAll(?Carbon $now = null): array
    {
        $now ??= now();
        $alerts = [];

        if ($alert = $this->evaluateMonthlyAnomaly($now)) {
            $alerts[] = $alert;
        }
        if ($alert = $this->evaluateDailySpike($now)) {
            $alerts[] = $alert;
        }
        if ($alert = $this->evaluateApproachingProjection($now)) {
            $alerts[] = $alert;
        }

        return $alerts;
    }

    /**
     * Rule 1: Monthly anomaly — current month spend > 2× rolling 3-month average.
     */
    public function evaluateMonthlyAnomaly(?Carbon $now = null): ?CostAlert
    {
        $now ??= now();

        if ($this->isDuplicateToday('monthly_anomaly', $now)) {
            return null;
        }

        $currentMonthStart = $now->copy()->startOfMonth();
        $currentMonthSpend = (float) DB::table('task_metrics')
            ->where('created_at', '>=', $currentMonthStart)
            ->sum('cost');

        // Rolling 3-month average (excluding current month)
        $threeMonthsAgo = $currentMonthStart->copy()->subMonths(3);
        $historicalSpend = (float) DB::table('task_metrics')
            ->where('created_at', '>=', $threeMonthsAgo)
            ->where('created_at', '<', $currentMonthStart)
            ->sum('cost');

        $monthsWithData = DB::table('task_metrics')
            ->where('created_at', '>=', $threeMonthsAgo)
            ->where('created_at', '<', $currentMonthStart)
            ->selectRaw('COUNT(DISTINCT ' . $this->monthExpression() . ') as months')
            ->value('months');

        if ($monthsWithData < 1) {
            return null; // Not enough history
        }

        $avgMonthly = $historicalSpend / $monthsWithData;
        $threshold = $avgMonthly * 2;

        if ($currentMonthSpend <= $threshold) {
            return null;
        }

        return CostAlert::create([
            'rule' => 'monthly_anomaly',
            'severity' => 'critical',
            'message' => sprintf(
                'Monthly spend ($%.2f) exceeds 2× the rolling 3-month average ($%.2f).',
                $currentMonthSpend,
                $avgMonthly,
            ),
            'context' => [
                'current_spend' => $currentMonthSpend,
                'avg_monthly' => $avgMonthly,
                'threshold' => $threshold,
                'period' => $now->format('Y-m'),
            ],
        ]);
    }

    /**
     * Rule 2: Daily spike — today's spend > 5× daily average.
     */
    public function evaluateDailySpike(?Carbon $now = null): ?CostAlert
    {
        $now ??= now();

        if ($this->isDuplicateToday('daily_spike', $now)) {
            return null;
        }

        $todayStart = $now->copy()->startOfDay();
        $todaySpend = (float) DB::table('task_metrics')
            ->where('created_at', '>=', $todayStart)
            ->sum('cost');

        // Average daily spend over last 30 days (excluding today)
        $thirtyDaysAgo = $todayStart->copy()->subDays(30);
        $historicalSpend = (float) DB::table('task_metrics')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->where('created_at', '<', $todayStart)
            ->sum('cost');

        $daysWithData = DB::table('task_metrics')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->where('created_at', '<', $todayStart)
            ->selectRaw('COUNT(DISTINCT ' . $this->dateExpression() . ') as days')
            ->value('days');

        if ($daysWithData < 1) {
            return null; // Not enough history
        }

        $avgDaily = $historicalSpend / $daysWithData;
        $threshold = $avgDaily * 5;

        if ($todaySpend <= $threshold) {
            return null;
        }

        return CostAlert::create([
            'rule' => 'daily_spike',
            'severity' => 'critical',
            'message' => sprintf(
                'Daily spend ($%.2f) exceeds 5× the daily average ($%.2f).',
                $todaySpend,
                $avgDaily,
            ),
            'context' => [
                'today_spend' => $todaySpend,
                'avg_daily' => $avgDaily,
                'threshold' => $threshold,
                'date' => $now->toDateString(),
            ],
        ]);
    }

    /**
     * Rule 3: Single task outlier — task cost > 3× average for its type.
     * Called per-task from TaskObserver, not from evaluateAll().
     */
    public function evaluateSingleTaskOutlier(int $taskId, string $taskType, float $taskCost, ?Carbon $now = null): ?CostAlert
    {
        $now ??= now();

        $avgCostForType = (float) DB::table('task_metrics')
            ->where('task_type', $taskType)
            ->where('task_id', '!=', $taskId)
            ->avg('cost');

        if ($avgCostForType <= 0) {
            return null; // No history for this type
        }

        $threshold = $avgCostForType * 3;

        if ($taskCost <= $threshold) {
            return null;
        }

        return CostAlert::create([
            'rule' => 'single_task_outlier',
            'severity' => 'warning',
            'message' => sprintf(
                'Task #%d (%s) cost $%.4f exceeds 3× the type average ($%.4f).',
                $taskId,
                $taskType,
                $taskCost,
                $avgCostForType,
            ),
            'context' => [
                'task_id' => $taskId,
                'task_type' => $taskType,
                'task_cost' => $taskCost,
                'avg_cost_for_type' => $avgCostForType,
                'threshold' => $threshold,
            ],
        ]);
    }

    /**
     * Rule 4: Approaching projection — projected month-end > 2× last month.
     */
    public function evaluateApproachingProjection(?Carbon $now = null): ?CostAlert
    {
        $now ??= now();

        if ($this->isDuplicateToday('approaching_projection', $now)) {
            return null;
        }

        $currentMonthStart = $now->copy()->startOfMonth();
        $daysElapsed = $now->day;
        $daysInMonth = $now->daysInMonth;

        if ($daysElapsed < 3) {
            return null; // Too early to project meaningfully
        }

        $currentSpend = (float) DB::table('task_metrics')
            ->where('created_at', '>=', $currentMonthStart)
            ->sum('cost');

        $projectedSpend = ($currentSpend / $daysElapsed) * $daysInMonth;

        // Last month's total
        $lastMonthStart = $currentMonthStart->copy()->subMonth();
        $lastMonthSpend = (float) DB::table('task_metrics')
            ->where('created_at', '>=', $lastMonthStart)
            ->where('created_at', '<', $currentMonthStart)
            ->sum('cost');

        if ($lastMonthSpend <= 0) {
            return null; // No last month data to compare
        }

        $threshold = $lastMonthSpend * 2;

        if ($projectedSpend <= $threshold) {
            return null;
        }

        return CostAlert::create([
            'rule' => 'approaching_projection',
            'severity' => 'warning',
            'message' => sprintf(
                'Projected month-end spend ($%.2f) exceeds 2× last month ($%.2f).',
                $projectedSpend,
                $lastMonthSpend,
            ),
            'context' => [
                'projected_spend' => round($projectedSpend, 6),
                'last_month_spend' => $lastMonthSpend,
                'threshold' => $threshold,
                'days_elapsed' => $daysElapsed,
                'days_in_month' => $daysInMonth,
                'period' => $now->format('Y-m'),
            ],
        ]);
    }

    /**
     * Check if an alert of the same rule was already created today (dedup).
     */
    private function isDuplicateToday(string $rule, Carbon $now): bool
    {
        return CostAlert::where('rule', $rule)
            ->where('acknowledged', false)
            ->where('created_at', '>=', $now->copy()->startOfDay())
            ->exists();
    }

    /**
     * SQL expression for extracting month from created_at (SQLite/PG compatible).
     */
    private function monthExpression(): string
    {
        $driver = DB::connection()->getDriverName();
        return $driver === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "TO_CHAR(created_at, 'YYYY-MM')";
    }

    /**
     * SQL expression for extracting date from created_at (SQLite/PG compatible).
     */
    private function dateExpression(): string
    {
        $driver = DB::connection()->getDriverName();
        return $driver === 'sqlite'
            ? "date(created_at)"
            : "DATE(created_at)";
    }
}
```

**Step 4: Run tests — expect all pass**

Run: `php artisan test --filter=CostAlertServiceTest`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Services/CostAlertService.php tests/Unit/Services/CostAlertServiceTest.php
git commit --no-gpg-sign -m "T94.3: Create CostAlertService with 4 alert rules and tests"
```

---

### Task 4: Wire single-task outlier into TaskObserver

**Files:**
- Modify: `app/Observers/TaskObserver.php` (~line 76, after TaskMetric::create)
- Test: `tests/Feature/Observers/TaskObserverCostAlertTest.php`

**Step 1: Write the failing test**

Feature test (needs database): When a task completes with cost > 3× type average, a `cost_alerts` row is created with rule `single_task_outlier`.

Create test data: 5 task_metrics with avg cost $0.50 for `code_review`, then complete a task with cost $2.00 (> 3 × $0.50).

**Step 2: Run test — expect failure**

Run: `php artisan test --filter=TaskObserverCostAlertTest`
Expected: FAIL — no alert row created

**Step 3: Modify TaskObserver**

In `recordMetricsOnTerminal()`, after the `TaskMetric::create(...)` call (line ~63-76), add:

```php
// Evaluate single-task cost outlier alert (T94)
if (($task->cost ?? 0) > 0) {
    try {
        app(CostAlertService::class)->evaluateSingleTaskOutlier(
            $task->id,
            $task->type->value,
            (float) $task->cost,
        );
    } catch (\Throwable $e) {
        Log::warning('TaskObserver: cost alert evaluation failed', [
            'task_id' => $task->id,
            'error' => $e->getMessage(),
        ]);
    }
}
```

Add import: `use App\Services\CostAlertService;`

**Step 4: Run test — expect pass**

Run: `php artisan test --filter=TaskObserverCostAlertTest`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Observers/TaskObserver.php tests/Feature/Observers/TaskObserverCostAlertTest.php
git commit --no-gpg-sign -m "T94.4: Wire single-task outlier alert into TaskObserver"
```

---

### Task 5: Create scheduled command for aggregate alerts

**Files:**
- Create: `app/Console/Commands/EvaluateCostAlerts.php`
- Modify: `routes/console.php` (add schedule entry)
- Test: `tests/Feature/Console/EvaluateCostAlertsTest.php`

**Step 1: Write failing test**

Feature test: The `cost-alerts:evaluate` artisan command runs `CostAlertService::evaluateAll()`.

**Step 2: Run test — expect failure**

Run: `php artisan test --filter=EvaluateCostAlertsTest`
Expected: FAIL — command not found

**Step 3: Create the command**

```php
<?php

namespace App\Console\Commands;

use App\Services\CostAlertService;
use Illuminate\Console\Command;

class EvaluateCostAlerts extends Command
{
    protected $signature = 'cost-alerts:evaluate';

    protected $description = 'Evaluate aggregate cost alert rules (monthly anomaly, daily spike, projection)';

    public function handle(CostAlertService $service): int
    {
        $alerts = $service->evaluateAll();

        if (count($alerts) > 0) {
            $this->info(count($alerts) . ' cost alert(s) created.');
        } else {
            $this->info('No cost alerts triggered.');
        }

        return self::SUCCESS;
    }
}
```

Add to `routes/console.php`:
```php
Schedule::command('cost-alerts:evaluate')->everyFifteenMinutes()->withoutOverlapping();
```

**Step 4: Run test — expect pass**

Run: `php artisan test --filter=EvaluateCostAlertsTest`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Console/Commands/EvaluateCostAlerts.php routes/console.php tests/Feature/Console/EvaluateCostAlertsTest.php
git commit --no-gpg-sign -m "T94.5: Create scheduled cost-alerts:evaluate command"
```

---

### Task 6: Create CostAlertController API endpoint

**Files:**
- Create: `app/Http/Controllers/Api/CostAlertController.php`
- Modify: `routes/api.php` (add routes)
- Test: `tests/Feature/Http/Controllers/Api/CostAlertControllerTest.php`

**Step 1: Write failing tests**

Test cases:
- `GET /api/v1/dashboard/cost-alerts` returns active (unacknowledged) alerts, admin-only
- `PATCH /api/v1/dashboard/cost-alerts/{id}/acknowledge` marks alert acknowledged
- Non-admin user gets 403

**Step 2: Run tests — expect failure**

Run: `php artisan test --filter=CostAlertControllerTest`
Expected: FAIL — route not found

**Step 3: Create controller**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CostAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CostAlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $alerts = CostAlert::active()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $alerts]);
    }

    public function acknowledge(Request $request, CostAlert $costAlert): JsonResponse
    {
        $this->authorizeAdmin($request);

        $costAlert->update([
            'acknowledged' => true,
            'acknowledged_at' => now(),
        ]);

        return response()->json(['success' => true, 'data' => $costAlert->fresh()]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        $hasAdmin = $user->projects()
            ->where('enabled', true)
            ->get()
            ->contains(fn ($project) => $user->hasPermission('admin.global_config', $project));

        if (! $hasAdmin) {
            abort(403, 'Cost alerts are restricted to administrators.');
        }
    }
}
```

Add routes to `routes/api.php` inside the `auth` middleware group:

```php
// Cost alert management (T94) — admin-only via RBAC
Route::get('/dashboard/cost-alerts', [CostAlertController::class, 'index'])
    ->name('api.dashboard.cost-alerts.index');
Route::patch('/dashboard/cost-alerts/{costAlert}/acknowledge', [CostAlertController::class, 'acknowledge'])
    ->name('api.dashboard.cost-alerts.acknowledge');
```

**Step 4: Run tests — expect pass**

Run: `php artisan test --filter=CostAlertControllerTest`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Http/Controllers/Api/CostAlertController.php routes/api.php tests/Feature/Http/Controllers/Api/CostAlertControllerTest.php
git commit --no-gpg-sign -m "T94.6: Create CostAlertController with index and acknowledge endpoints"
```

---

### Task 7: Add cost alerts to admin Pinia store

**Files:**
- Modify: `resources/js/stores/admin.js` (add costAlerts state + actions)
- Modify: `resources/js/stores/dashboard.js` (add costAlerts fetch)

**Step 1: Add to admin store**

Add a new section after PRD template management in `resources/js/stores/admin.js`:

```javascript
// ─── Cost alerts (T94) ────────────────────────────────────
const costAlerts = ref([]);
const costAlertsLoading = ref(false);
const costAlertsError = ref(null);

async function fetchCostAlerts() {
    costAlertsLoading.value = true;
    costAlertsError.value = null;
    try {
        const { data } = await axios.get('/api/v1/dashboard/cost-alerts');
        costAlerts.value = data.data;
    } catch (e) {
        costAlertsError.value = 'Failed to load cost alerts.';
    } finally {
        costAlertsLoading.value = false;
    }
}

async function acknowledgeCostAlert(alertId) {
    try {
        await axios.patch(`/api/v1/dashboard/cost-alerts/${alertId}/acknowledge`);
        costAlerts.value = costAlerts.value.filter((a) => a.id !== alertId);
        return { success: true };
    } catch (e) {
        return { success: false, error: e.response?.data?.error || 'Failed to acknowledge alert.' };
    }
}
```

Export new refs and functions in the return statement.

**Step 2: Also add to dashboard store**

In `resources/js/stores/dashboard.js`, add a `costAlerts` ref and `fetchCostAlerts` action that delegates to the admin store (or fetches directly). Since the dashboard cost view already exists and is admin-only, add the fetch alongside `fetchCost()`:

```javascript
const costAlerts = ref([]);

async function fetchCostAlerts() {
    try {
        const { data } = await axios.get('/api/v1/dashboard/cost-alerts');
        costAlerts.value = data.data;
    } catch (e) {
        // Supplementary — don't block dashboard
    }
}
```

**Step 3: Commit**

```bash
git add resources/js/stores/admin.js resources/js/stores/dashboard.js
git commit --no-gpg-sign -m "T94.7: Add cost alerts state and actions to Pinia stores"
```

---

### Task 8: Add alerts section to DashboardCost Vue component

**Files:**
- Modify: `resources/js/components/DashboardCost.vue`

**Step 1: Update the component**

Add to `<script setup>`:
```javascript
const costAlerts = computed(() => dashboard.costAlerts);

onMounted(() => {
    dashboard.fetchCostAlerts();
});

const severityColors = {
    critical: 'bg-red-100 dark:bg-red-900/30 border-red-300 dark:border-red-700 text-red-800 dark:text-red-200',
    warning: 'bg-amber-100 dark:bg-amber-900/30 border-amber-300 dark:border-amber-700 text-amber-800 dark:text-amber-200',
};

const ruleLabels = {
    monthly_anomaly: 'Monthly Anomaly',
    daily_spike: 'Daily Spike',
    single_task_outlier: 'Single Task Outlier',
    approaching_projection: 'Approaching Projection',
};
```

Add alerts section at the top of the cost data area (after `<div v-else-if="cost" class="space-y-6">`), before the summary row:

```html
<!-- Active cost alerts (T94) -->
<div v-if="costAlerts.length > 0" data-testid="cost-alerts">
  <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-3">Active Alerts</h3>
  <div class="space-y-2">
    <div
      v-for="alert in costAlerts"
      :key="alert.id"
      :data-testid="`cost-alert-${alert.id}`"
      :class="['rounded-lg border p-3 flex items-start justify-between', severityColors[alert.severity] || severityColors.warning]"
    >
      <div>
        <span class="text-xs font-semibold uppercase">{{ ruleLabels[alert.rule] || alert.rule }}</span>
        <p class="text-sm mt-0.5">{{ alert.message }}</p>
        <p class="text-xs opacity-70 mt-1">{{ new Date(alert.created_at).toLocaleString() }}</p>
      </div>
      <button
        data-testid="acknowledge-btn"
        class="ml-3 flex-shrink-0 text-xs font-medium underline opacity-70 hover:opacity-100"
        @click="$emit('acknowledge', alert.id)"
      >
        Dismiss
      </button>
    </div>
  </div>
</div>
```

Add emit: `const emit = defineEmits(['acknowledge']);`

Alternatively, handle acknowledge directly in the component by importing the admin store:

```javascript
import { useAdminStore } from '@/stores/admin';
const admin = useAdminStore();

async function handleAcknowledge(alertId) {
    await admin.acknowledgeCostAlert(alertId);
    dashboard.fetchCostAlerts(); // Refresh the list
}
```

And use `@click="handleAcknowledge(alert.id)"` instead of `$emit`.

**Step 2: Commit**

```bash
git add resources/js/components/DashboardCost.vue
git commit --no-gpg-sign -m "T94.8: Add active alerts section to DashboardCost component"
```

---

### Task 9: Write DashboardCost alert component tests

**Files:**
- Modify: `resources/js/components/DashboardCost.test.js` (or create if doesn't exist)

**Step 1: Write tests**

Test cases:
- Component renders alert cards when `costAlerts` has data
- Alert cards show rule label, message, severity colors
- Dismiss button calls `acknowledgeCostAlert`
- No alerts section shown when `costAlerts` is empty

**Step 2: Run tests**

Run: `npx vitest run resources/js/components/DashboardCost.test.js`
Expected: PASS

**Step 3: Commit**

```bash
git add resources/js/components/DashboardCost.test.js
git commit --no-gpg-sign -m "T94.9: Add DashboardCost alert section tests"
```

---

### Task 10: Create verify_m5.py structural checks for T94

**Files:**
- Create: `verify/verify_m5.py`

**Step 1: Create verification script**

Add structural checks for T94 (and stubs for future M5 tasks):
- `cost_alerts` migration exists
- `CostAlert` model exists
- `CostAlertService` with 4 rule methods
- `CostAlertController` with index + acknowledge
- Routes registered for cost-alerts
- `EvaluateCostAlerts` command exists
- Schedule entry in `routes/console.php`
- Vue `DashboardCost.vue` contains `cost-alerts` testid
- Pinia stores have `costAlerts` state

**Step 2: Run verification**

Run: `python3 verify/verify_m5.py`
Expected: All T94 checks pass

**Step 3: Commit**

```bash
git add verify/verify_m5.py
git commit --no-gpg-sign -m "T94.10: Create verify_m5.py with T94 structural checks"
```

---

### Task 11: Run full verification

**Step 1: Run PHP tests**

Run: `php artisan test --parallel`
Expected: All pass

**Step 2: Run Vue tests**

Run: `npx vitest run`
Expected: All pass

**Step 3: Run structural verification**

Run: `python3 verify/verify_m5.py`
Expected: All T94 checks pass

**Step 4: Update progress.md and handoff.md, commit**

Mark T94 complete in `progress.md`. Clear `handoff.md`. Commit.

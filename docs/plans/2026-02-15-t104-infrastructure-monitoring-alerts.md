# T104: Infrastructure Monitoring Alerts — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add infrastructure monitoring alerts for container health, CPU, memory, disk, and queue depth — surfaced in the admin dashboard and sent to team chat.

**Architecture:** Extend the existing `AlertEventService` with three new evaluation methods (`evaluateContainerHealth`, `evaluateCpuUsage`, `evaluateMemoryUsage`). The existing `evaluateQueueDepth` and `evaluateDiskUsage` already cover two of the five T104 metrics. Create an `InfrastructureAlertController` to surface `AlertEvent` records in the admin dashboard (following the `CostAlertController` pattern). Add a Vue component to display infrastructure alerts in the `DashboardOverview` section. The `EvaluateSystemAlerts` command already runs every 5 minutes via the scheduler and calls `evaluateAll()` — new checks are automatically included.

**Tech Stack:** Laravel 11 (PHP 8.3), Pest (testing), Vue 3 + Pinia, AlertEvent model, TeamChatNotificationService

---

### Task 1: Add `evaluateContainerHealth` method to AlertEventService

**Files:**
- Modify: `app/Services/AlertEventService.php`
- Modify: `tests/Feature/Services/AlertEventServiceTest.php`

**Step 1: Write the failing tests**

Add to `tests/Feature/Services/AlertEventServiceTest.php`:

```php
// ─── Container Health Detection (T104) ─────────────────────────

it('detects container health issue when health endpoint reports unhealthy', function () {
    Http::fake([
        '127.0.0.1/health' => Http::response([
            'status' => 'unhealthy',
            'checks' => [
                'postgresql' => ['status' => 'ok'],
                'redis' => ['status' => 'fail', 'error' => 'Connection refused'],
                'queue_worker' => ['status' => 'ok'],
                'reverb' => ['status' => 'ok'],
                'disk' => ['status' => 'ok'],
            ],
        ], 503),
        '*' => Http::response('ok', 200),
    ]);
    Cache::put('infra:health_first_failure', now()->subMinutes(3)->toIso8601String(), 3600);

    $service = app(AlertEventService::class);
    $alert = $service->evaluateContainerHealth();

    expect($alert)->not->toBeNull();
    expect($alert->alert_type)->toBe('container_health');
    expect($alert->status)->toBe('active');
    expect($alert->severity)->toBe('high');
    expect($alert->message)->toContain('unhealthy');
});

it('does not trigger container health alert if unhealthy for less than 2 minutes', function () {
    Http::fake([
        '127.0.0.1/health' => Http::response(['status' => 'unhealthy', 'checks' => []], 503),
        '*' => Http::response('ok', 200),
    ]);
    Cache::put('infra:health_first_failure', now()->toIso8601String(), 3600);

    $service = app(AlertEventService::class);
    expect($service->evaluateContainerHealth())->toBeNull();
});

it('resolves container health alert when health endpoint recovers', function () {
    Http::fake([
        '127.0.0.1/health' => Http::response(['status' => 'healthy', 'checks' => []], 200),
        '*' => Http::response('ok', 200),
    ]);
    AlertEvent::factory()->create([
        'alert_type' => 'container_health',
        'status' => 'active',
        'notified_at' => now()->subHour(),
    ]);

    $service = app(AlertEventService::class);
    $resolved = $service->evaluateContainerHealth();

    expect($resolved)->not->toBeNull();
    expect($resolved->status)->toBe('resolved');
    expect($resolved->recovery_notified_at)->not->toBeNull();
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="container health"`
Expected: FAIL — method `evaluateContainerHealth` not found

**Step 3: Implement**

In `AlertEventService.php`:
1. Add `use Illuminate\Support\Facades\Cache;` and `use Illuminate\Support\Facades\Http;` to imports
2. Add `'container_health' => fn () => $this->evaluateContainerHealth($now)` to the `$checks` array in `evaluateAll()`
3. Add `'container_health' => 'Container health'` to `$typeLabels` in `notifyRecovery()`
4. Add the `evaluateContainerHealth()` method — polls the `/health` endpoint, uses `Cache::put('infra:health_first_failure', ...)` to track sustained failure duration (>2 min threshold), creates `container_health` alert on sustained failure, resolves on recovery

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter="container health"`

**Step 5: Commit**

```
T104.1: Add container health monitoring to AlertEventService
```

---

### Task 2: Add `evaluateCpuUsage` method to AlertEventService

**Files:**
- Modify: `app/Services/AlertEventService.php`
- Modify: `tests/Feature/Services/AlertEventServiceTest.php`

**Step 1: Write the failing tests**

```php
// ─── CPU Usage Detection (T104) ────────────────────────────────

it('detects high CPU usage when sustained above 90% for 5+ minutes', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    Cache::put('infra:cpu_first_high', now()->subMinutes(6)->toIso8601String(), 3600);
    Cache::put('infra:cpu_current', 95.2, 300);

    $service = app(AlertEventService::class);
    $alert = $service->evaluateCpuUsage();

    expect($alert)->not->toBeNull();
    expect($alert->alert_type)->toBe('cpu_usage');
    expect($alert->severity)->toBe('high');
    expect($alert->message)->toContain('CPU');
});

it('does not trigger CPU alert if high for less than 5 minutes', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    Cache::put('infra:cpu_first_high', now()->subMinutes(2)->toIso8601String(), 3600);
    Cache::put('infra:cpu_current', 95.0, 300);

    $service = app(AlertEventService::class);
    expect($service->evaluateCpuUsage())->toBeNull();
});

it('resolves CPU alert when usage drops below threshold', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    AlertEvent::factory()->create([
        'alert_type' => 'cpu_usage',
        'status' => 'active',
        'notified_at' => now()->subHour(),
    ]);
    Cache::forget('infra:cpu_first_high');
    Cache::forget('infra:cpu_current');

    $service = app(AlertEventService::class);
    $resolved = $service->evaluateCpuUsage();

    expect($resolved)->not->toBeNull();
    expect($resolved->status)->toBe('resolved');
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="CPU"`

**Step 3: Implement**

1. Add `'cpu_usage' => fn () => $this->evaluateCpuUsage($now)` to `evaluateAll()` checks
2. Add `'cpu_usage' => 'High CPU usage'` to `notifyRecovery()` typeLabels
3. Add method: reads `Cache::get('infra:cpu_current')` (written by scheduled infra check), fallback to `sys_getloadavg()` / CPU count. Uses `Cache::put('infra:cpu_first_high', ...)` for sustained duration tracking (>5 min, >90% threshold)

**Step 4: Run tests**

Run: `php artisan test --filter="CPU"`

**Step 5: Commit**

```
T104.2: Add CPU usage monitoring to AlertEventService
```

---

### Task 3: Add `evaluateMemoryUsage` method to AlertEventService

**Files:**
- Modify: `app/Services/AlertEventService.php`
- Modify: `tests/Feature/Services/AlertEventServiceTest.php`

**Step 1: Write the failing tests**

```php
// ─── Memory Usage Detection (T104) ─────────────────────────────

it('detects high memory usage when sustained above 85% for 5+ minutes', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    Cache::put('infra:memory_first_high', now()->subMinutes(6)->toIso8601String(), 3600);
    Cache::put('infra:memory_current', 92.5, 300);

    $service = app(AlertEventService::class);
    $alert = $service->evaluateMemoryUsage();

    expect($alert)->not->toBeNull();
    expect($alert->alert_type)->toBe('memory_usage');
    expect($alert->severity)->toBe('high');
    expect($alert->message)->toContain('Memory');
});

it('does not trigger memory alert if high for less than 5 minutes', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    Cache::put('infra:memory_first_high', now()->subMinutes(2)->toIso8601String(), 3600);
    Cache::put('infra:memory_current', 92.5, 300);

    $service = app(AlertEventService::class);
    expect($service->evaluateMemoryUsage())->toBeNull();
});

it('resolves memory alert when usage drops below threshold', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    AlertEvent::factory()->create([
        'alert_type' => 'memory_usage',
        'status' => 'active',
        'notified_at' => now()->subHour(),
    ]);
    Cache::forget('infra:memory_first_high');
    Cache::forget('infra:memory_current');

    $service = app(AlertEventService::class);
    $resolved = $service->evaluateMemoryUsage();

    expect($resolved)->not->toBeNull();
    expect($resolved->status)->toBe('resolved');
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="memory"`

**Step 3: Implement**

1. Add `'memory_usage' => fn () => $this->evaluateMemoryUsage($now)` to `evaluateAll()` checks
2. Add `'memory_usage' => 'High memory usage'` to `notifyRecovery()` typeLabels
3. Add method: reads `Cache::get('infra:memory_current')`, fallback to `/proc/meminfo` (Linux) or `vm_stat` (Darwin). Sustained duration tracking via `infra:memory_first_high` cache key (>5 min, >85% threshold)
4. Add private helper `getSystemMemoryPercent(): ?float`

**Step 4: Run tests**

Run: `php artisan test --filter="memory"`

**Step 5: Commit**

```
T104.3: Add memory usage monitoring to AlertEventService
```

---

### Task 4: Create InfrastructureAlertController for admin dashboard API

**Files:**
- Create: `app/Http/Controllers/Api/InfrastructureAlertController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Http/Controllers/Api/InfrastructureAlertControllerTest.php`

**Step 1: Write the failing tests**

Create `tests/Feature/Http/Controllers/Api/InfrastructureAlertControllerTest.php`:

```php
<?php

use App\Models\AlertEvent;
use App\Models\Project;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->project = Project::factory()->enabled()->create();
    $this->admin->projects()->attach($this->project->id, ['role_id' => null]);

    $role = \App\Models\Role::factory()->create([
        'project_id' => $this->project->id,
        'permissions' => ['admin.global_config'],
    ]);
    $this->admin->projects()->updateExistingPivot($this->project->id, ['role_id' => $role->id]);

    $this->regularUser = User::factory()->create();
});

it('lists active infrastructure alerts for admin users', function () {
    AlertEvent::factory()->create([
        'alert_type' => 'container_health',
        'status' => 'active',
        'severity' => 'high',
        'message' => 'Container unhealthy for >5 minutes.',
    ]);
    AlertEvent::factory()->create([
        'alert_type' => 'cpu_usage',
        'status' => 'active',
        'severity' => 'high',
        'message' => 'CPU at 95%.',
    ]);
    AlertEvent::factory()->resolved()->create(['alert_type' => 'memory_usage']);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/dashboard/infrastructure-alerts');

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
});

it('denies non-admin access to infrastructure alerts', function () {
    $this->actingAs($this->regularUser)
        ->getJson('/api/v1/dashboard/infrastructure-alerts')
        ->assertForbidden();
});

it('returns current infrastructure status summary', function () {
    AlertEvent::factory()->create([
        'alert_type' => 'container_health',
        'status' => 'active',
        'severity' => 'high',
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/dashboard/infrastructure-status');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => ['overall_status', 'active_alerts_count', 'checks'],
    ]);
    $response->assertJsonPath('data.overall_status', 'degraded');
    $response->assertJsonPath('data.active_alerts_count', 1);
});

it('returns healthy status when no active alerts', function () {
    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/dashboard/infrastructure-status');

    $response->assertOk();
    $response->assertJsonPath('data.overall_status', 'healthy');
    $response->assertJsonPath('data.active_alerts_count', 0);
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="InfrastructureAlertController"`
Expected: 404 routes

**Step 3: Implement**

Create controller (follows CostAlertController pattern):
- `index()` — lists active AlertEvents, admin-only
- `status()` — returns overall_status (healthy/degraded), active_alerts_count, and per-check status for 5 infra types
- `authorizeAdmin()` — same pattern as CostAlertController

Add routes to `routes/api.php`:
- `GET /dashboard/infrastructure-alerts` → `InfrastructureAlertController::index`
- `GET /dashboard/infrastructure-status` → `InfrastructureAlertController::status`

Add `use App\Http\Controllers\Api\InfrastructureAlertController;` import.

**Step 4: Run tests**

Run: `php artisan test --filter="InfrastructureAlertController"`

**Step 5: Commit**

```
T104.4: Add InfrastructureAlertController with admin API endpoints
```

---

### Task 5: Add infrastructure alerts to dashboard Pinia store

**Files:**
- Modify: `resources/js/stores/dashboard.js`

**Step 1: Add state and fetch methods**

Add refs:
```javascript
const infrastructureAlerts = ref([]);
const infrastructureStatus = ref(null);
```

Add fetch methods:
```javascript
async function fetchInfrastructureAlerts() { ... }
async function fetchInfrastructureStatus() { ... }
```

Add to `$reset()` and `return` block.

**Step 2: Commit**

```
T104.5: Add infrastructure alert state to dashboard Pinia store
```

---

### Task 6: Create DashboardInfrastructure Vue component

**Files:**
- Create: `resources/js/components/DashboardInfrastructure.vue`

**Step 1: Create the component**

Component shows:
- Overall status banner (healthy/degraded)
- 5-column grid of system check statuses (container health, CPU, memory, disk, queue depth)
- Active alerts list with severity badges and timestamps

Fetches `fetchInfrastructureStatus()` and `fetchInfrastructureAlerts()` on mount.

**Step 2: Commit**

```
T104.6: Create DashboardInfrastructure Vue component
```

---

### Task 7: Wire DashboardInfrastructure into DashboardPage

**Files:**
- Modify: `resources/js/pages/DashboardPage.vue`

**Step 1: Add infrastructure tab**

- Import `DashboardInfrastructure`
- Add `{ key: 'infrastructure', label: 'Infrastructure' }` to admin-only views (alongside cost tab)
- Add `<DashboardInfrastructure v-else-if="activeView === 'infrastructure'" />` to template

**Step 2: Commit**

```
T104.7: Wire infrastructure tab into DashboardPage (admin-only)
```

---

### Task 8: Write T104 integration test — queue depth lifecycle

**Files:**
- Modify: `tests/Feature/Services/AlertEventServiceTest.php`

**Step 1: Write integration test**

Per M5 verification spec: "Queue >50 → alert to dashboard + chat. Drops below → recovery"

Test creates 51 queued tasks, evaluates queue depth (expect alert), verifies dashboard visibility and chat notification, drains queue, re-evaluates (expect recovery), verifies exactly 2 notifications.

**Step 2: Run and verify**

Run: `php artisan test --filter="T104 integration"`

**Step 3: Commit**

```
T104.8: Add T104 integration test for queue depth alert lifecycle
```

---

### Task 9: Add T104 verification checks to verify_m5.py

**Files:**
- Modify: `verify/verify_m5.py`

**Step 1: Add checks**

Append T104 section with checks for:
- AlertEventService has all 3 new evaluate methods
- AlertEventService registers all 3 new types in evaluateAll
- InfrastructureAlertController exists
- Routes registered (infrastructure-alerts, infrastructure-status)
- DashboardInfrastructure.vue exists
- DashboardPage imports it
- Dashboard store has fetch methods
- Scheduler runs every 5 minutes
- Tests exist for container health, CPU, memory, integration

**Step 2: Run verify**

Run: `python3 verify/verify_m5.py`

**Step 3: Commit**

```
T104.9: Add T104 verification checks to verify_m5.py
```

---

### Task 10: Run full verification, update progress, finalize

**Step 1: Run full test suite**

```bash
php artisan test --parallel
```

**Step 2: Run M5 verification**

```bash
python3 verify/verify_m5.py
```

**Step 3: Update progress.md**

- Check `[x]` for T104
- Bold next task (T116)
- Update milestone count: M5 17/18
- Update summary: Current Task → T116

**Step 4: Clear handoff.md**

**Step 5: Commit**

```
T104: Complete infrastructure monitoring alerts — mark task done
```

# T95: Over-Reliance Detection — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Detect when engineers are rubber-stamping AI code reviews by monitoring acceptance rate, bulk resolution patterns, and zero-reaction patterns — surface alerts in the admin dashboard.

**Architecture:** Reuses the same model/service/controller/store pattern as T94 cost alerts. A new `OverrelianceAlert` model stores detection alerts. An `OverrelianceDetectionService` evaluates 4 detection rules against `finding_acceptances` data. A scheduled Artisan command runs weekly evaluations. The Quality dashboard tab shows active alerts with dismiss buttons. Reuses existing `CostAlert`-style dedup and acknowledge patterns.

**Tech Stack:** Laravel 11 (Eloquent, Artisan commands, Pest tests), Vue 3 + Pinia, Vitest

---

## Design Decision: Reuse CostAlert or New Model?

The spec says "Auto-alert in admin dashboard" — same pattern as cost alerts. However, over-reliance alerts are conceptually distinct (quality vs. cost) and have different rule types. **Decision: Create a separate `OverrelianceAlert` model** (mirrors `CostAlert` structure) to keep concerns separated. The Quality dashboard tab is the natural home for these alerts (not the Cost tab).

## Detection Rules (from §16.5)

| Rule | Key | Threshold | Severity | Data Source |
|---|---|---|---|---|
| High acceptance rate | `high_acceptance_rate` | >95% for 2+ consecutive weeks | warning | `finding_acceptances.status` grouped by week |
| Critical acceptance rate | `critical_acceptance_rate` | >99% critical findings accepted | warning | `finding_acceptances` where `severity = critical` |
| Bulk resolution pattern | `bulk_resolution` | Multiple MRs with `bulk_resolved = true` in recent window | warning | `finding_acceptances.bulk_resolved` |
| Zero negative reactions | `zero_reactions` | Zero 👎 across many reviews (20+) | info | `finding_acceptances.emoji_negative_count` |

---

### Task 1: Create OverrelianceAlert migration

**Files:**
- Create: `database/migrations/2026_02_15_070000_create_overreliance_alerts_table.php`

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
        Schema::create('overreliance_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('rule');          // high_acceptance_rate, critical_acceptance_rate, bulk_resolution, zero_reactions
            $table->string('severity');      // warning, info
            $table->text('message');
            $table->json('context');         // { acceptance_rate, threshold, weeks, project_id, etc. }
            $table->boolean('acknowledged')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['acknowledged', 'created_at']);
            $table->index('rule');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overreliance_alerts');
    }
};
```

**Step 2: Commit**

```bash
git add database/migrations/2026_02_15_070000_create_overreliance_alerts_table.php
git commit --no-gpg-sign -m "T95.1: Add overreliance_alerts migration"
```

---

### Task 2: Create OverrelianceAlert model

**Files:**
- Create: `app/Models/OverrelianceAlert.php`

**Step 1: Create the model**

Mirror the `CostAlert` model pattern exactly:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OverrelianceAlert extends Model
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
git add app/Models/OverrelianceAlert.php
git commit --no-gpg-sign -m "T95.2: Add OverrelianceAlert model"
```

---

### Task 3: Create OverrelianceDetectionService with tests

**Files:**
- Create: `app/Services/OverrelianceDetectionService.php`
- Create: `tests/Feature/Services/OverrelianceDetectionServiceTest.php`

**Step 1: Write the tests first**

Key test cases from the spec (§21.6 unit tests table):
- `>95% for 2 weeks → alert`
- `94% → no alert`
- `>95% for 1 week → no alert (needs 2)`
- Bulk resolution detection
- Zero-reaction detection
- Deduplication

```php
<?php

use App\Models\FindingAcceptance;
use App\Models\OverrelianceAlert;
use App\Models\Project;
use App\Models\Task;
use App\Services\OverrelianceDetectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->enabled()->create();
});

// ─── High acceptance rate ───────────────────────────────────────────

it('creates alert when acceptance rate exceeds 95% for 2 consecutive weeks', function () {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // Week 1 (Feb 3-9): 20 findings, 19 accepted = 95%  (above threshold)
    seedAcceptances($this->project, 19, 'accepted', $now->copy()->subWeeks(2)->addDay());
    seedAcceptances($this->project, 1, 'dismissed', $now->copy()->subWeeks(2)->addDays(2));

    // Week 2 (Feb 10-16): 20 findings, 20 accepted = 100%
    seedAcceptances($this->project, 20, 'accepted', $now->copy()->subWeek()->addDay());

    $service = new OverrelianceDetectionService();
    $alert = $service->evaluateHighAcceptanceRate($now);

    expect($alert)->not->toBeNull()
        ->and($alert->rule)->toBe('high_acceptance_rate')
        ->and($alert->severity)->toBe('warning');
});

it('does not create alert when acceptance rate is 94%', function () {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // Week 1: 50 findings, 47 accepted = 94%
    seedAcceptances($this->project, 47, 'accepted', $now->copy()->subWeeks(2)->addDay());
    seedAcceptances($this->project, 3, 'dismissed', $now->copy()->subWeeks(2)->addDays(2));

    // Week 2: same
    seedAcceptances($this->project, 47, 'accepted', $now->copy()->subWeek()->addDay());
    seedAcceptances($this->project, 3, 'dismissed', $now->copy()->subWeek()->addDays(2));

    $service = new OverrelianceDetectionService();
    $alert = $service->evaluateHighAcceptanceRate($now);

    expect($alert)->toBeNull();
});

it('does not create alert when high acceptance rate for only 1 week', function () {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // Week 1: 20 findings, 15 accepted = 75% (below threshold)
    seedAcceptances($this->project, 15, 'accepted', $now->copy()->subWeeks(2)->addDay());
    seedAcceptances($this->project, 5, 'dismissed', $now->copy()->subWeeks(2)->addDays(2));

    // Week 2: 20 findings, 20 accepted = 100% (above threshold but only 1 week)
    seedAcceptances($this->project, 20, 'accepted', $now->copy()->subWeek()->addDay());

    $service = new OverrelianceDetectionService();
    $alert = $service->evaluateHighAcceptanceRate($now);

    expect($alert)->toBeNull();
});

it('skips high acceptance rate when insufficient data', function () {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // Only 2 findings total — not enough to be meaningful
    seedAcceptances($this->project, 2, 'accepted', $now->copy()->subWeek()->addDay());

    $service = new OverrelianceDetectionService();
    $alert = $service->evaluateHighAcceptanceRate($now);

    expect($alert)->toBeNull();
});

// ─── Critical acceptance rate ───────────────────────────────────────

it('creates alert when critical finding acceptance exceeds 99%', function () {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // 100 critical findings, all accepted
    seedAcceptances($this->project, 100, 'accepted', $now->copy()->subDays(14), 'critical');

    $service = new OverrelianceDetectionService();
    $alert = $service->evaluateCriticalAcceptanceRate($now);

    expect($alert)->not->toBeNull()
        ->and($alert->rule)->toBe('critical_acceptance_rate')
        ->and($alert->severity)->toBe('warning');
});

it('does not create alert when critical acceptance is below 99%', function () {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // 100 critical findings, 98 accepted = 98%
    seedAcceptances($this->project, 98, 'accepted', $now->copy()->subDays(14), 'critical');
    seedAcceptances($this->project, 2, 'dismissed', $now->copy()->subDays(14), 'critical');

    $service = new OverrelianceDetectionService();
    $alert = $service->evaluateCriticalAcceptanceRate($now);

    expect($alert)->toBeNull();
});

// ─── Bulk resolution pattern ────────────────────────────────────────

it('creates alert when bulk resolution ratio exceeds threshold', function () {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // 20 findings, 15 bulk-resolved (75%)
    seedAcceptances($this->project, 15, 'accepted', $now->copy()->subDays(7), 'major', true);
    seedAcceptances($this->project, 5, 'accepted', $now->copy()->subDays(7), 'major', false);

    $service = new OverrelianceDetectionService();
    $alert = $service->evaluateBulkResolution($now);

    expect($alert)->not->toBeNull()
        ->and($alert->rule)->toBe('bulk_resolution')
        ->and($alert->severity)->toBe('warning');
});

it('does not create alert when bulk resolution ratio is low', function () {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // 20 findings, 2 bulk-resolved (10%)
    seedAcceptances($this->project, 2, 'accepted', $now->copy()->subDays(7), 'major', true);
    seedAcceptances($this->project, 18, 'accepted', $now->copy()->subDays(7), 'major', false);

    $service = new OverrelianceDetectionService();
    $alert = $service->evaluateBulkResolution($now);

    expect($alert)->toBeNull();
});

// ─── Zero reactions ─────────────────────────────────────────────────

it('creates alert when zero negative reactions across many reviews', function () {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // 30 findings, all with 0 negative reactions
    seedAcceptances($this->project, 30, 'accepted', $now->copy()->subDays(14));

    $service = new OverrelianceDetectionService();
    $alert = $service->evaluateZeroReactions($now);

    expect($alert)->not->toBeNull()
        ->and($alert->rule)->toBe('zero_reactions')
        ->and($alert->severity)->toBe('info');
});

it('does not create alert when negative reactions exist', function () {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // 30 findings, some with negative reactions
    seedAcceptances($this->project, 28, 'accepted', $now->copy()->subDays(14));
    // 2 findings with negative feedback
    seedAcceptances($this->project, 2, 'accepted', $now->copy()->subDays(14), 'major', false, 1);

    $service = new OverrelianceDetectionService();
    $alert = $service->evaluateZeroReactions($now);

    expect($alert)->toBeNull();
});

it('does not create alert when too few findings for reaction check', function () {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // Only 5 findings — below minimum threshold of 20
    seedAcceptances($this->project, 5, 'accepted', $now->copy()->subDays(14));

    $service = new OverrelianceDetectionService();
    $alert = $service->evaluateZeroReactions($now);

    expect($alert)->toBeNull();
});

// ─── Deduplication ──────────────────────────────────────────────────

it('does not create duplicate alert for same rule in same week', function () {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // Set up data triggering high acceptance rate
    seedAcceptances($this->project, 20, 'accepted', $now->copy()->subWeeks(2)->addDay());
    seedAcceptances($this->project, 20, 'accepted', $now->copy()->subWeek()->addDay());

    $service = new OverrelianceDetectionService();

    $alert1 = $service->evaluateHighAcceptanceRate($now);
    expect($alert1)->not->toBeNull();

    $alert2 = $service->evaluateHighAcceptanceRate($now);
    expect($alert2)->toBeNull();

    expect(OverrelianceAlert::where('rule', 'high_acceptance_rate')->count())->toBe(1);
});

// ─── evaluateAll ────────────────────────────────────────────────────

it('evaluateAll runs all 4 rules and returns created alerts', function () {
    $now = Carbon::parse('2026-02-15 12:00:00');

    // Trigger high acceptance rate
    seedAcceptances($this->project, 20, 'accepted', $now->copy()->subWeeks(2)->addDay());
    seedAcceptances($this->project, 20, 'accepted', $now->copy()->subWeek()->addDay());

    $service = new OverrelianceDetectionService();
    $alerts = $service->evaluateAll($now);

    expect($alerts)->not->toBeEmpty();
    $rules = collect($alerts)->pluck('rule')->all();
    expect($rules)->toContain('high_acceptance_rate');
});

// ─── Helper ─────────────────────────────────────────────────────────

function seedAcceptances(
    Project $project,
    int $count,
    string $status,
    Carbon $createdAt,
    string $severity = 'major',
    bool $bulkResolved = false,
    int $emojiNegativeCount = 0,
): void {
    $task = Task::factory()->create(['project_id' => $project->id]);

    for ($i = 0; $i < $count; $i++) {
        FindingAcceptance::create([
            'task_id' => $task->id,
            'project_id' => $project->id,
            'mr_iid' => 1,
            'finding_id' => (string) ($i + 1),
            'file' => 'src/example.php',
            'line' => $i + 1,
            'severity' => $severity,
            'title' => "Finding {$i}",
            'status' => $status,
            'bulk_resolved' => $bulkResolved,
            'emoji_negative_count' => $emojiNegativeCount,
            'emoji_positive_count' => 0,
            'emoji_sentiment' => $emojiNegativeCount > 0 ? 'negative' : 'neutral',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
```

**Step 2: Run tests — verify they fail**

```bash
php artisan test --filter=OverrelianceDetectionServiceTest
```

Expected: Failures (class not found).

**Step 3: Implement the service**

```php
<?php

namespace App\Services;

use App\Models\OverrelianceAlert;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OverrelianceDetectionService
{
    private const ACCEPTANCE_RATE_THRESHOLD = 95.0;
    private const CRITICAL_ACCEPTANCE_THRESHOLD = 99.0;
    private const BULK_RESOLUTION_RATIO_THRESHOLD = 50.0; // >50% bulk-resolved
    private const MIN_FINDINGS_PER_WEEK = 5;
    private const MIN_FINDINGS_FOR_REACTIONS = 20;
    private const WEEKS_REQUIRED = 2;

    /**
     * Evaluate all over-reliance detection rules.
     *
     * @return OverrelianceAlert[]
     */
    public function evaluateAll(?Carbon $now = null): array
    {
        $now ??= now();
        $alerts = [];

        if ($alert = $this->evaluateHighAcceptanceRate($now)) {
            $alerts[] = $alert;
        }
        if ($alert = $this->evaluateCriticalAcceptanceRate($now)) {
            $alerts[] = $alert;
        }
        if ($alert = $this->evaluateBulkResolution($now)) {
            $alerts[] = $alert;
        }
        if ($alert = $this->evaluateZeroReactions($now)) {
            $alerts[] = $alert;
        }

        return $alerts;
    }

    /**
     * Rule 1: Overall acceptance rate >95% for 2+ consecutive weeks.
     */
    public function evaluateHighAcceptanceRate(?Carbon $now = null): ?OverrelianceAlert
    {
        $now ??= now();

        if ($this->isDuplicateThisWeek('high_acceptance_rate', $now)) {
            return null;
        }

        $consecutiveWeeks = 0;
        $weeklyRates = [];

        for ($w = 1; $w <= self::WEEKS_REQUIRED; $w++) {
            $weekStart = $now->copy()->subWeeks($w)->startOfWeek();
            $weekEnd = $weekStart->copy()->endOfWeek();

            $total = DB::table('finding_acceptances')
                ->where('created_at', '>=', $weekStart)
                ->where('created_at', '<=', $weekEnd)
                ->count();

            if ($total < self::MIN_FINDINGS_PER_WEEK) {
                return null; // Not enough data for this week
            }

            $accepted = DB::table('finding_acceptances')
                ->where('created_at', '>=', $weekStart)
                ->where('created_at', '<=', $weekEnd)
                ->whereIn('status', ['accepted', 'accepted_auto'])
                ->count();

            $rate = ($accepted / $total) * 100;
            $weeklyRates[] = ['week' => $weekStart->toDateString(), 'rate' => round($rate, 1), 'total' => $total];

            if ($rate > self::ACCEPTANCE_RATE_THRESHOLD) {
                $consecutiveWeeks++;
            } else {
                return null; // Chain broken
            }
        }

        if ($consecutiveWeeks < self::WEEKS_REQUIRED) {
            return null;
        }

        $overallRate = collect($weeklyRates)->avg('rate');

        return OverrelianceAlert::create([
            'rule' => 'high_acceptance_rate',
            'severity' => 'warning',
            'message' => sprintf(
                'Acceptance rate has been above %.0f%% for %d consecutive weeks (avg: %.1f%%). Engineers may be rubber-stamping reviews.',
                self::ACCEPTANCE_RATE_THRESHOLD,
                $consecutiveWeeks,
                $overallRate,
            ),
            'context' => [
                'weekly_rates' => $weeklyRates,
                'consecutive_weeks' => $consecutiveWeeks,
                'threshold' => self::ACCEPTANCE_RATE_THRESHOLD,
            ],
        ]);
    }

    /**
     * Rule 2: Critical finding acceptance >99% — almost nothing disputed.
     */
    public function evaluateCriticalAcceptanceRate(?Carbon $now = null): ?OverrelianceAlert
    {
        $now ??= now();

        if ($this->isDuplicateThisWeek('critical_acceptance_rate', $now)) {
            return null;
        }

        $lookback = $now->copy()->subDays(30);

        $total = DB::table('finding_acceptances')
            ->where('severity', 'critical')
            ->where('created_at', '>=', $lookback)
            ->count();

        if ($total < self::MIN_FINDINGS_FOR_REACTIONS) {
            return null; // Not enough critical findings
        }

        $accepted = DB::table('finding_acceptances')
            ->where('severity', 'critical')
            ->where('created_at', '>=', $lookback)
            ->whereIn('status', ['accepted', 'accepted_auto'])
            ->count();

        $rate = ($accepted / $total) * 100;

        if ($rate <= self::CRITICAL_ACCEPTANCE_THRESHOLD) {
            return null;
        }

        return OverrelianceAlert::create([
            'rule' => 'critical_acceptance_rate',
            'severity' => 'warning',
            'message' => sprintf(
                'Critical finding acceptance rate is %.1f%% (last 30 days). Engineers may not be scrutinizing critical findings.',
                $rate,
            ),
            'context' => [
                'acceptance_rate' => round($rate, 1),
                'total_critical' => $total,
                'accepted_critical' => $accepted,
                'threshold' => self::CRITICAL_ACCEPTANCE_THRESHOLD,
                'lookback_days' => 30,
            ],
        ]);
    }

    /**
     * Rule 3: Bulk resolution pattern — many threads resolved within seconds.
     */
    public function evaluateBulkResolution(?Carbon $now = null): ?OverrelianceAlert
    {
        $now ??= now();

        if ($this->isDuplicateThisWeek('bulk_resolution', $now)) {
            return null;
        }

        $lookback = $now->copy()->subDays(14);

        $total = DB::table('finding_acceptances')
            ->where('created_at', '>=', $lookback)
            ->count();

        if ($total < self::MIN_FINDINGS_PER_WEEK) {
            return null;
        }

        $bulkCount = DB::table('finding_acceptances')
            ->where('created_at', '>=', $lookback)
            ->where('bulk_resolved', true)
            ->count();

        $ratio = ($bulkCount / $total) * 100;

        if ($ratio <= self::BULK_RESOLUTION_RATIO_THRESHOLD) {
            return null;
        }

        return OverrelianceAlert::create([
            'rule' => 'bulk_resolution',
            'severity' => 'warning',
            'message' => sprintf(
                '%.0f%% of findings (%d/%d) were bulk-resolved in the last 14 days. Possible "resolve all" behavior.',
                $ratio,
                $bulkCount,
                $total,
            ),
            'context' => [
                'bulk_count' => $bulkCount,
                'total_findings' => $total,
                'ratio' => round($ratio, 1),
                'threshold' => self::BULK_RESOLUTION_RATIO_THRESHOLD,
                'lookback_days' => 14,
            ],
        ]);
    }

    /**
     * Rule 4: Zero negative reactions across many reviews.
     */
    public function evaluateZeroReactions(?Carbon $now = null): ?OverrelianceAlert
    {
        $now ??= now();

        if ($this->isDuplicateThisWeek('zero_reactions', $now)) {
            return null;
        }

        $lookback = $now->copy()->subDays(30);

        $total = DB::table('finding_acceptances')
            ->where('created_at', '>=', $lookback)
            ->count();

        if ($total < self::MIN_FINDINGS_FOR_REACTIONS) {
            return null;
        }

        $negativeCount = (int) DB::table('finding_acceptances')
            ->where('created_at', '>=', $lookback)
            ->sum('emoji_negative_count');

        if ($negativeCount > 0) {
            return null;
        }

        return OverrelianceAlert::create([
            'rule' => 'zero_reactions',
            'severity' => 'info',
            'message' => sprintf(
                'Zero negative (👎) reactions across %d findings in the last 30 days. Feedback mechanism may not be used.',
                $total,
            ),
            'context' => [
                'total_findings' => $total,
                'negative_count' => 0,
                'lookback_days' => 30,
            ],
        ]);
    }

    /**
     * Check if an alert of the same rule was already created this week (dedup).
     * Over-reliance rules run weekly, so dedup window is 7 days.
     */
    private function isDuplicateThisWeek(string $rule, Carbon $now): bool
    {
        return OverrelianceAlert::where('rule', $rule)
            ->where('acknowledged', false)
            ->where('created_at', '>=', $now->copy()->subDays(7))
            ->exists();
    }
}
```

**Step 4: Run tests — verify they pass**

```bash
php artisan test --filter=OverrelianceDetectionServiceTest
```

Expected: All tests pass.

**Step 5: Commit**

```bash
git add app/Services/OverrelianceDetectionService.php tests/Feature/Services/OverrelianceDetectionServiceTest.php
git commit --no-gpg-sign -m "T95.3: Add OverrelianceDetectionService with 4 detection rules and tests"
```

---

### Task 4: Create OverrelianceAlertController with tests

**Files:**
- Create: `app/Http/Controllers/Api/OverrelianceAlertController.php`
- Create: `tests/Feature/Http/Controllers/Api/OverrelianceAlertControllerTest.php`
- Modify: `routes/api.php`

**Step 1: Write the controller tests first**

```php
<?php

use App\Models\OverrelianceAlert;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->enabled()->create();

    $this->adminUser = User::factory()->create();
    $adminRole = Role::factory()->create(['permissions' => ['admin.global_config']]);
    $this->adminUser->projects()->attach($this->project->id, ['role_id' => $adminRole->id]);

    $this->regularUser = User::factory()->create();
    $regularRole = Role::factory()->create(['permissions' => ['task.view']]);
    $this->regularUser->projects()->attach($this->project->id, ['role_id' => $regularRole->id]);
});

// ─── Index ──────────────────────────────────────────────────────────

it('returns active overreliance alerts for admin', function () {
    OverrelianceAlert::create([
        'rule' => 'high_acceptance_rate',
        'severity' => 'warning',
        'message' => 'Test alert',
        'context' => ['threshold' => 95],
    ]);

    $response = $this->actingAs($this->adminUser)
        ->getJson('/api/v1/dashboard/overreliance-alerts');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.rule', 'high_acceptance_rate');
});

it('excludes acknowledged alerts from index', function () {
    OverrelianceAlert::create([
        'rule' => 'high_acceptance_rate',
        'severity' => 'warning',
        'message' => 'Active alert',
        'context' => [],
    ]);
    OverrelianceAlert::create([
        'rule' => 'bulk_resolution',
        'severity' => 'warning',
        'message' => 'Acknowledged',
        'context' => [],
        'acknowledged' => true,
        'acknowledged_at' => now(),
    ]);

    $response = $this->actingAs($this->adminUser)
        ->getJson('/api/v1/dashboard/overreliance-alerts');

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('returns 403 for non-admin user', function () {
    $response = $this->actingAs($this->regularUser)
        ->getJson('/api/v1/dashboard/overreliance-alerts');

    $response->assertForbidden();
});

// ─── Acknowledge ────────────────────────────────────────────────────

it('acknowledges an overreliance alert', function () {
    $alert = OverrelianceAlert::create([
        'rule' => 'high_acceptance_rate',
        'severity' => 'warning',
        'message' => 'Test',
        'context' => [],
    ]);

    $response = $this->actingAs($this->adminUser)
        ->patchJson("/api/v1/dashboard/overreliance-alerts/{$alert->id}/acknowledge");

    $response->assertOk()
        ->assertJsonPath('success', true);

    $alert->refresh();
    expect($alert->acknowledged)->toBeTrue()
        ->and($alert->acknowledged_at)->not->toBeNull();
});

it('returns 403 when non-admin tries to acknowledge', function () {
    $alert = OverrelianceAlert::create([
        'rule' => 'high_acceptance_rate',
        'severity' => 'warning',
        'message' => 'Test',
        'context' => [],
    ]);

    $response = $this->actingAs($this->regularUser)
        ->patchJson("/api/v1/dashboard/overreliance-alerts/{$alert->id}/acknowledge");

    $response->assertForbidden();
});
```

**Step 2: Run tests — verify they fail**

```bash
php artisan test --filter=OverrelianceAlertControllerTest
```

**Step 3: Create the controller**

Mirror `CostAlertController` exactly:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OverrelianceAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverrelianceAlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $alerts = OverrelianceAlert::active()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $alerts]);
    }

    public function acknowledge(Request $request, OverrelianceAlert $overrelianceAlert): JsonResponse
    {
        $this->authorizeAdmin($request);

        $overrelianceAlert->update([
            'acknowledged' => true,
            'acknowledged_at' => now(),
        ]);

        return response()->json(['success' => true, 'data' => $overrelianceAlert->fresh()]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        $hasAdmin = $user->projects()
            ->where('enabled', true)
            ->get()
            ->contains(fn ($project) => $user->hasPermission('admin.global_config', $project));

        if (! $hasAdmin) {
            abort(403, 'Over-reliance alerts are restricted to administrators.');
        }
    }
}
```

**Step 4: Add routes to `routes/api.php`**

After the cost-alerts routes (line ~146), add:

```php
        // Over-reliance alert management (T95) — admin-only via RBAC
        Route::get('/dashboard/overreliance-alerts', [OverrelianceAlertController::class, 'index'])
            ->name('api.dashboard.overreliance-alerts.index');
        Route::patch('/dashboard/overreliance-alerts/{overrelianceAlert}/acknowledge', [OverrelianceAlertController::class, 'acknowledge'])
            ->name('api.dashboard.overreliance-alerts.acknowledge');
```

Also add the import at top of `routes/api.php`:

```php
use App\Http\Controllers\Api\OverrelianceAlertController;
```

**Step 5: Run tests — verify they pass**

```bash
php artisan test --filter=OverrelianceAlertControllerTest
```

**Step 6: Commit**

```bash
git add app/Http/Controllers/Api/OverrelianceAlertController.php tests/Feature/Http/Controllers/Api/OverrelianceAlertControllerTest.php routes/api.php
git commit --no-gpg-sign -m "T95.4: Add OverrelianceAlertController with API routes and tests"
```

---

### Task 5: Create scheduled Artisan command with tests

**Files:**
- Create: `app/Console/Commands/EvaluateOverrelianceAlerts.php`
- Create: `tests/Feature/Console/EvaluateOverrelianceAlertsTest.php`
- Modify: `routes/console.php`

**Step 1: Write the command test first**

```php
<?php

use App\Models\FindingAcceptance;
use App\Models\OverrelianceAlert;
use App\Models\Project;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('evaluates overreliance rules and reports results', function () {
    $this->artisan('overreliance:evaluate')
        ->expectsOutput('No over-reliance alerts triggered.')
        ->assertExitCode(0);
});

it('reports created alerts count', function () {
    $project = Project::factory()->enabled()->create();
    $now = Carbon::parse('2026-02-15 12:00:00');

    Carbon::setTestNow($now);

    // Create enough accepted findings for 2 consecutive weeks
    $task1 = Task::factory()->create(['project_id' => $project->id]);
    for ($i = 0; $i < 20; $i++) {
        FindingAcceptance::create([
            'task_id' => $task1->id,
            'project_id' => $project->id,
            'mr_iid' => 1,
            'finding_id' => (string) $i,
            'file' => 'src/test.php',
            'line' => $i,
            'severity' => 'major',
            'title' => "Finding $i",
            'status' => 'accepted',
            'emoji_positive_count' => 0,
            'emoji_negative_count' => 0,
            'emoji_sentiment' => 'neutral',
            'created_at' => $now->copy()->subWeeks(2)->addDay(),
            'updated_at' => $now->copy()->subWeeks(2)->addDay(),
        ]);
    }

    $task2 = Task::factory()->create(['project_id' => $project->id]);
    for ($i = 0; $i < 20; $i++) {
        FindingAcceptance::create([
            'task_id' => $task2->id,
            'project_id' => $project->id,
            'mr_iid' => 2,
            'finding_id' => (string) $i,
            'file' => 'src/test.php',
            'line' => $i,
            'severity' => 'major',
            'title' => "Finding $i",
            'status' => 'accepted',
            'emoji_positive_count' => 0,
            'emoji_negative_count' => 0,
            'emoji_sentiment' => 'neutral',
            'created_at' => $now->copy()->subWeek()->addDay(),
            'updated_at' => $now->copy()->subWeek()->addDay(),
        ]);
    }

    $this->artisan('overreliance:evaluate')
        ->assertExitCode(0);

    // At least one alert should have been created
    expect(OverrelianceAlert::count())->toBeGreaterThanOrEqual(1);

    Carbon::setTestNow(null);
});
```

**Step 2: Run tests — verify they fail**

```bash
php artisan test --filter=EvaluateOverrelianceAlertsTest
```

**Step 3: Create the command**

```php
<?php

namespace App\Console\Commands;

use App\Services\OverrelianceDetectionService;
use Illuminate\Console\Command;

class EvaluateOverrelianceAlerts extends Command
{
    protected $signature = 'overreliance:evaluate';

    protected $description = 'Evaluate over-reliance detection rules (acceptance rate, bulk resolution, reactions)';

    public function handle(OverrelianceDetectionService $service): int
    {
        $alerts = $service->evaluateAll();

        if (count($alerts) > 0) {
            $this->info(count($alerts) . ' over-reliance alert(s) created.');
        } else {
            $this->info('No over-reliance alerts triggered.');
        }

        return self::SUCCESS;
    }
}
```

**Step 4: Add schedule entry to `routes/console.php`**

Add after the `cost-alerts:evaluate` schedule:

```php
Schedule::command('overreliance:evaluate')
    ->weekly()
    ->mondays()
    ->at('09:00')
    ->withoutOverlapping();
```

**Step 5: Run tests — verify they pass**

```bash
php artisan test --filter=EvaluateOverrelianceAlertsTest
```

**Step 6: Commit**

```bash
git add app/Console/Commands/EvaluateOverrelianceAlerts.php tests/Feature/Console/EvaluateOverrelianceAlertsTest.php routes/console.php
git commit --no-gpg-sign -m "T95.5: Add overreliance:evaluate scheduled command with tests"
```

---

### Task 6: Add over-reliance alerts to Pinia stores

**Files:**
- Modify: `resources/js/stores/dashboard.js`
- Modify: `resources/js/stores/admin.js`

**Step 1: Add to dashboard store**

In `resources/js/stores/dashboard.js`:
- Add `overrelianceAlerts` ref (next to `costAlerts`)
- Add `fetchOverrelianceAlerts()` method (mirrors `fetchCostAlerts`)
- Add to `$reset()` method
- Add to return object

**Step 2: Add to admin store**

In `resources/js/stores/admin.js`:
- Add `overrelianceAlerts`, `overrelianceAlertsLoading`, `overrelianceAlertsError` refs
- Add `fetchOverrelianceAlerts()` method
- Add `acknowledgeOverrelianceAlert(alertId)` method
- Add to return object

The patterns are identical to the existing cost alert store code — just change the endpoint URL from `cost-alerts` to `overreliance-alerts`.

**Step 3: Commit**

```bash
git add resources/js/stores/dashboard.js resources/js/stores/admin.js
git commit --no-gpg-sign -m "T95.6: Add over-reliance alerts state to Pinia stores"
```

---

### Task 7: Add over-reliance alerts to DashboardQuality component with tests

**Files:**
- Modify: `resources/js/components/DashboardQuality.vue`
- Modify: `resources/js/components/DashboardQuality.test.js`

**Step 1: Write the Vue component tests first**

Add to `DashboardQuality.test.js` — new `describe` block for over-reliance alerts:

```javascript
    // -- Over-reliance alerts (T95) --

    const sampleOverrelianceAlerts = [
        {
            id: 1,
            rule: 'high_acceptance_rate',
            severity: 'warning',
            message: 'Acceptance rate has been above 95% for 2 consecutive weeks (avg: 97.5%).',
            context: { weekly_rates: [], consecutive_weeks: 2, threshold: 95 },
            acknowledged: false,
            created_at: '2026-02-15T09:00:00.000Z',
        },
        {
            id: 2,
            rule: 'zero_reactions',
            severity: 'info',
            message: 'Zero negative reactions across 30 findings in the last 30 days.',
            context: { total_findings: 30, negative_count: 0, lookback_days: 30 },
            acknowledged: false,
            created_at: '2026-02-15T09:00:00.000Z',
        },
    ];

    it('renders overreliance alert cards when data exists', () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        store.overrelianceAlerts = sampleOverrelianceAlerts;
        const wrapper = mountQuality();
        expect(wrapper.find('[data-testid="overreliance-alerts"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-testid^="overreliance-alert-"]')).toHaveLength(2);
    });

    it('does not render overreliance alerts section when empty', () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        store.overrelianceAlerts = [];
        const wrapper = mountQuality();
        expect(wrapper.find('[data-testid="overreliance-alerts"]').exists()).toBe(false);
    });

    it('displays rule label and message for overreliance alerts', () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        store.overrelianceAlerts = sampleOverrelianceAlerts;
        const wrapper = mountQuality();

        const alert1 = wrapper.find('[data-testid="overreliance-alert-1"]');
        expect(alert1.text()).toContain('High Acceptance Rate');
        expect(alert1.text()).toContain('above 95%');
    });

    it('calls acknowledgeOverrelianceAlert on dismiss click', async () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        store.overrelianceAlerts = [sampleOverrelianceAlerts[0]];
        const adminStore = useAdminStore();
        adminStore.acknowledgeOverrelianceAlert = vi.fn().mockResolvedValue({ success: true });
        store.fetchOverrelianceAlerts = vi.fn().mockResolvedValue(undefined);

        const wrapper = mountQuality();
        await wrapper.find('[data-testid="overreliance-acknowledge-btn"]').trigger('click');

        expect(adminStore.acknowledgeOverrelianceAlert).toHaveBeenCalledWith(1);
    });
```

Note: The test file needs to import `useAdminStore` too (add alongside `useDashboardStore`). Also add `patch` to the axios mock.

**Step 2: Update the DashboardQuality component**

Add the alert section at the top of the quality cards div (inside `<div v-else-if="quality" class="space-y-6">`), before the top row grid. This mirrors how DashboardCost shows cost alerts above the main content.

Add to `<script setup>`:
- Import `useAdminStore`
- Add `const admin = useAdminStore();`
- Fetch overreliance alerts in `onMounted`
- Add `overrelianceAlerts` computed from store
- Add `handleOverrelianceAcknowledge` function
- Add `overrelianceRuleLabels` and `overrelianceSeverityColors` objects

Add to `<template>`:
- Alert section (identical structure to cost alerts in DashboardCost)
- Rule labels: `high_acceptance_rate: 'High Acceptance Rate'`, `critical_acceptance_rate: 'Critical Finding Acceptance'`, `bulk_resolution: 'Bulk Resolution Pattern'`, `zero_reactions: 'Zero Negative Reactions'`
- Severity colors: `warning` → amber, `info` → blue

**Step 3: Run tests — verify they pass**

```bash
npx vitest run resources/js/components/DashboardQuality.test.js
```

**Step 4: Commit**

```bash
git add resources/js/components/DashboardQuality.vue resources/js/components/DashboardQuality.test.js
git commit --no-gpg-sign -m "T95.7: Add over-reliance alerts to DashboardQuality component with tests"
```

---

### Task 8: Update verify_m5.py with T95 structural checks

**Files:**
- Modify: `verify/verify_m5.py`

**Step 1: Add T95 section to verify_m5.py**

Add before the Summary section, after T94:

```python
# ============================================================
#  T95: Over-reliance detection
# ============================================================
section("T95: Over-Reliance Detection")

# Migration
checker.check(
    "overreliance_alerts migration exists",
    file_exists("database/migrations/2026_02_15_070000_create_overreliance_alerts_table.php"),
)
checker.check(
    "Migration creates overreliance_alerts table",
    file_contains("database/migrations/2026_02_15_070000_create_overreliance_alerts_table.php", "overreliance_alerts"),
)

# Model
checker.check(
    "OverrelianceAlert model exists",
    file_exists("app/Models/OverrelianceAlert.php"),
)
checker.check(
    "OverrelianceAlert has rule field",
    file_contains("app/Models/OverrelianceAlert.php", "'rule'"),
)
checker.check(
    "OverrelianceAlert has active scope",
    file_contains("app/Models/OverrelianceAlert.php", "scopeActive"),
)
checker.check(
    "OverrelianceAlert casts context as array",
    file_contains("app/Models/OverrelianceAlert.php", "'context' => 'array'"),
)

# Service
checker.check(
    "OverrelianceDetectionService exists",
    file_exists("app/Services/OverrelianceDetectionService.php"),
)
checker.check(
    "OverrelianceDetectionService has evaluateAll",
    file_contains("app/Services/OverrelianceDetectionService.php", "evaluateAll"),
)
checker.check(
    "Service has high acceptance rate rule",
    file_contains("app/Services/OverrelianceDetectionService.php", "evaluateHighAcceptanceRate"),
)
checker.check(
    "Service has critical acceptance rate rule",
    file_contains("app/Services/OverrelianceDetectionService.php", "evaluateCriticalAcceptanceRate"),
)
checker.check(
    "Service has bulk resolution rule",
    file_contains("app/Services/OverrelianceDetectionService.php", "evaluateBulkResolution"),
)
checker.check(
    "Service has zero reactions rule",
    file_contains("app/Services/OverrelianceDetectionService.php", "evaluateZeroReactions"),
)
checker.check(
    "Service has dedup check",
    file_contains("app/Services/OverrelianceDetectionService.php", "isDuplicateThisWeek"),
)

# Controller
checker.check(
    "OverrelianceAlertController exists",
    file_exists("app/Http/Controllers/Api/OverrelianceAlertController.php"),
)
checker.check(
    "OverrelianceAlertController has index method",
    file_contains("app/Http/Controllers/Api/OverrelianceAlertController.php", "function index"),
)
checker.check(
    "OverrelianceAlertController has acknowledge method",
    file_contains("app/Http/Controllers/Api/OverrelianceAlertController.php", "function acknowledge"),
)
checker.check(
    "Overreliance alert routes registered",
    file_contains("routes/api.php", "overreliance-alerts"),
)

# Scheduled command
checker.check(
    "EvaluateOverrelianceAlerts command exists",
    file_exists("app/Console/Commands/EvaluateOverrelianceAlerts.php"),
)
checker.check(
    "EvaluateOverrelianceAlerts has correct signature",
    file_contains("app/Console/Commands/EvaluateOverrelianceAlerts.php", "overreliance:evaluate"),
)
checker.check(
    "Schedule entry in console routes",
    file_contains("routes/console.php", "overreliance:evaluate"),
)

# Frontend — Vue
checker.check(
    "DashboardQuality has overreliance-alerts section",
    file_contains("resources/js/components/DashboardQuality.vue", 'data-testid="overreliance-alerts"'),
)
checker.check(
    "DashboardQuality has overreliance acknowledge handler",
    file_contains("resources/js/components/DashboardQuality.vue", "handleOverrelianceAcknowledge"),
)
checker.check(
    "DashboardQuality imports admin store",
    file_contains("resources/js/components/DashboardQuality.vue", "useAdminStore"),
)

# Frontend — Pinia stores
checker.check(
    "Dashboard store has overrelianceAlerts ref",
    file_contains("resources/js/stores/dashboard.js", "overrelianceAlerts"),
)
checker.check(
    "Dashboard store has fetchOverrelianceAlerts action",
    file_contains("resources/js/stores/dashboard.js", "fetchOverrelianceAlerts"),
)
checker.check(
    "Admin store has overrelianceAlerts ref",
    file_contains("resources/js/stores/admin.js", "overrelianceAlerts"),
)
checker.check(
    "Admin store has acknowledgeOverrelianceAlert action",
    file_contains("resources/js/stores/admin.js", "acknowledgeOverrelianceAlert"),
)

# Tests
checker.check(
    "OverrelianceDetectionService test exists",
    file_exists("tests/Feature/Services/OverrelianceDetectionServiceTest.php"),
)
checker.check(
    "EvaluateOverrelianceAlerts command test exists",
    file_exists("tests/Feature/Console/EvaluateOverrelianceAlertsTest.php"),
)
checker.check(
    "OverrelianceAlertController API test exists",
    file_exists("tests/Feature/Http/Controllers/Api/OverrelianceAlertControllerTest.php"),
)
checker.check(
    "DashboardQuality test covers overreliance alerts",
    file_contains("resources/js/components/DashboardQuality.test.js", "overreliance-alerts"),
)
```

**Step 2: Run the structural checks**

```bash
python3 verify/verify_m5.py
```

Expected: All checks pass.

**Step 3: Commit**

```bash
git add verify/verify_m5.py
git commit --no-gpg-sign -m "T95.8: Add T95 structural checks to verify_m5.py"
```

---

### Task 9: Run full verification and update progress

**Files:**
- Modify: `progress.md`
- Modify: `handoff.md`

**Step 1: Run Laravel tests**

```bash
php artisan test --parallel
```

Expected: All tests pass (including new T95 tests).

**Step 2: Run structural checks**

```bash
python3 verify/verify_m5.py
```

Expected: All checks pass.

**Step 3: Update progress.md**

- Check `[x]` for T95
- Bold the next task (T96)
- Update milestone count: `M5 — Admin & Configuration (8/18)`
- Update summary: `Tasks Complete: 96 / 116 (82.8%)`, `Current Task: T96`, `Last Verified: T95`

**Step 4: Clear handoff.md**

Reset to empty template.

**Step 5: Commit**

```bash
git add progress.md handoff.md
git commit --no-gpg-sign -m "T95.9: Mark T95 complete, advance to T96"
```

**Step 6: STOP. Do not start T96.**

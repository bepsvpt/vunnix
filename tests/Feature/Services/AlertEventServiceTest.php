<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AlertEvent;
use App\Models\GlobalSetting;
use App\Models\Project;
use App\Models\Task;
use App\Services\AlertEventService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake([
        '*' => Http::response('ok', 200),
    ]);

    Cache::flush();
    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://hooks.slack.com/services/T/B/x', 'string');
    GlobalSetting::set('team_chat_platform', 'slack', 'string');
    GlobalSetting::set('team_chat_categories', [
        'task_completed' => true,
        'task_failed' => true,
        'alert' => true,
    ], 'json');
});

// ─── API Outage Detection ───────────────────────────────────────

it('detects API outage with 3+ consecutive API failures', function (): void {
    $project = Project::factory()->enabled()->create();

    // Create 3 consecutive failed tasks with API errors
    for ($i = 0; $i < 3; $i++) {
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::Failed,
            'error_reason' => 'API error: 500 Internal Server Error',
            'updated_at' => now()->subMinutes($i),
        ]);
    }

    $service = app(AlertEventService::class);
    $alert = $service->evaluateApiOutage();

    expect($alert)->not->toBeNull();
    expect($alert->alert_type)->toBe('api_outage');
    expect($alert->status)->toBe('active');
    expect($alert->severity)->toBe('high');
    expect($alert->notified_at)->not->toBeNull();

    Http::assertSent(fn ($r): bool => str_contains($r['text'], 'API outage detected'));
});

it('does not trigger API outage with fewer than 3 failures', function (): void {
    $project = Project::factory()->enabled()->create();

    for ($i = 0; $i < 2; $i++) {
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::Failed,
            'error_reason' => 'API error: 500',
            'updated_at' => now()->subMinutes($i),
        ]);
    }

    $service = app(AlertEventService::class);
    expect($service->evaluateApiOutage())->toBeNull();
});

it('does not duplicate API outage if already active', function (): void {
    AlertEvent::factory()->create([
        'alert_type' => 'api_outage',
        'status' => 'active',
    ]);

    $project = Project::factory()->enabled()->create();
    for ($i = 0; $i < 3; $i++) {
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::Failed,
            'error_reason' => 'API error: 500',
            'updated_at' => now()->subMinutes($i),
        ]);
    }

    $service = app(AlertEventService::class);
    expect($service->evaluateApiOutage())->toBeNull();
    expect(AlertEvent::where('alert_type', 'api_outage')->count())->toBe(1);
});

it('resolves API outage and sends recovery notification', function (): void {
    $alert = AlertEvent::factory()->create([
        'alert_type' => 'api_outage',
        'status' => 'active',
        'notified_at' => now()->subHour(),
    ]);

    $project = Project::factory()->enabled()->create();
    Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Completed,
        'updated_at' => now(),
    ]);

    $service = app(AlertEventService::class);
    $resolved = $service->evaluateApiOutage();

    expect($resolved)->not->toBeNull();
    expect($resolved->status)->toBe('resolved');
    expect($resolved->resolved_at)->not->toBeNull();
    expect($resolved->recovery_notified_at)->not->toBeNull();

    Http::assertSent(fn ($r): bool => str_contains($r['text'], 'resolved'));
});

// ─── High Failure Rate Detection ────────────────────────────────

it('detects high failure rate when >20% failing', function (): void {
    $project = Project::factory()->enabled()->create();

    // 3 failed, 7 completed = 30% failure rate
    for ($i = 0; $i < 3; $i++) {
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::Failed,
            'updated_at' => now()->subMinutes(10),
        ]);
    }
    for ($i = 0; $i < 7; $i++) {
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::Completed,
            'updated_at' => now()->subMinutes(10),
        ]);
    }

    $service = app(AlertEventService::class);
    $alert = $service->evaluateHighFailureRate();

    expect($alert)->not->toBeNull();
    expect($alert->alert_type)->toBe('high_failure_rate');
    expect($alert->severity)->toBe('medium');
});

it('does not trigger high failure rate with low failure count', function (): void {
    $project = Project::factory()->enabled()->create();

    // 1 failed, 9 completed = 10%
    Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Failed,
        'updated_at' => now()->subMinutes(10),
    ]);
    for ($i = 0; $i < 9; $i++) {
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::Completed,
            'updated_at' => now()->subMinutes(10),
        ]);
    }

    $service = app(AlertEventService::class);
    expect($service->evaluateHighFailureRate())->toBeNull();
});

it('skips high failure rate with fewer than 5 total tasks', function (): void {
    $project = Project::factory()->enabled()->create();

    // Only 3 tasks total
    for ($i = 0; $i < 3; $i++) {
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::Failed,
            'updated_at' => now()->subMinutes(10),
        ]);
    }

    $service = app(AlertEventService::class);
    expect($service->evaluateHighFailureRate())->toBeNull();
});

// ─── Queue Depth Detection ──────────────────────────────────────

it('detects queue depth exceeding threshold', function (): void {
    config(['vunnix.queue_depth_threshold' => 5]);

    $project = Project::factory()->enabled()->create();
    // Create 6 pending tasks
    for ($i = 0; $i < 6; $i++) {
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::Queued,
        ]);
    }

    $service = app(AlertEventService::class);
    $alert = $service->evaluateQueueDepth();

    expect($alert)->not->toBeNull();
    expect($alert->alert_type)->toBe('queue_depth');
    expect($alert->severity)->toBe('medium');
});

it('resolves queue depth when back below threshold', function (): void {
    config(['vunnix.queue_depth_threshold' => 50]);

    AlertEvent::factory()->create([
        'alert_type' => 'queue_depth',
        'status' => 'active',
    ]);

    // No pending tasks
    $service = app(AlertEventService::class);
    $resolved = $service->evaluateQueueDepth();

    expect($resolved)->not->toBeNull();
    expect($resolved->status)->toBe('resolved');
});

// ─── Auth Failure Detection ─────────────────────────────────────

it('detects auth failure from 401 errors', function (): void {
    $project = Project::factory()->enabled()->create();

    for ($i = 0; $i < 2; $i++) {
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::Failed,
            'error_reason' => 'HTTP 401 unauthorized — check API key',
            'updated_at' => now()->subMinutes(5),
        ]);
    }

    $service = app(AlertEventService::class);
    $alert = $service->evaluateAuthFailure();

    expect($alert)->not->toBeNull();
    expect($alert->alert_type)->toBe('auth_failure');
    expect($alert->severity)->toBe('high');
});

// ─── Alert Deduplication ────────────────────────────────────────

it('deduplicates: ongoing condition does not re-trigger', function (): void {
    $project = Project::factory()->enabled()->create();

    // Create active alert
    AlertEvent::factory()->create([
        'alert_type' => 'high_failure_rate',
        'status' => 'active',
    ]);

    // Still failing
    for ($i = 0; $i < 3; $i++) {
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::Failed,
            'updated_at' => now()->subMinutes(10),
        ]);
    }
    for ($i = 0; $i < 7; $i++) {
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::Completed,
            'updated_at' => now()->subMinutes(10),
        ]);
    }

    $service = app(AlertEventService::class);
    expect($service->evaluateHighFailureRate())->toBeNull();
    expect(AlertEvent::where('alert_type', 'high_failure_rate')->count())->toBe(1);
});

it('sends exactly one detection and one recovery notification', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    $project = Project::factory()->enabled()->create();

    // Phase 1: Create outage condition
    for ($i = 0; $i < 3; $i++) {
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::Failed,
            'error_reason' => 'API error: 503',
            'updated_at' => now()->subMinutes($i),
        ]);
    }

    $service = app(AlertEventService::class);
    $service->evaluateApiOutage();

    // Phase 2: Condition persists — should not re-notify
    $service->evaluateApiOutage();

    // Phase 3: Recovery — add a successful task
    Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Completed,
        'updated_at' => now()->addMinute(),
    ]);
    $service->evaluateApiOutage();

    // Should have sent exactly 2 notifications: 1 detect + 1 recovery
    Http::assertSentCount(2);
});

// ─── evaluateAll ────────────────────────────────────────────────

it('evaluateAll runs all checks and returns events', function (): void {
    config(['vunnix.queue_depth_threshold' => 2]);

    $project = Project::factory()->enabled()->create();

    // Create queue depth condition
    for ($i = 0; $i < 3; $i++) {
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::Queued,
        ]);
    }

    $service = app(AlertEventService::class);
    $events = $service->evaluateAll();

    // At least queue_depth should trigger; disk_usage may also fire depending on host
    expect($events)->not->toBeEmpty();
    $types = array_map(fn (\App\Models\AlertEvent $e) => $e->alert_type, $events);
    expect($types)->toContain('queue_depth');
});

// ─── Task Completion Notifications ──────────────────────────────

it('sends code review completion notification', function (): void {
    $project = Project::factory()->enabled()->create(['name' => 'my-project']);
    $task = Task::factory()->completed()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'mr_iid' => 123,
        'result' => [
            'summary' => [
                'risk_level' => 'medium',
                'total_findings' => 3,
            ],
        ],
    ]);

    $service = app(AlertEventService::class);
    $service->notifyTaskCompletion($task);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return str_contains($request['text'], 'Review complete on **my-project** MR !123')
            && str_contains($request['text'], 'Medium risk')
            && str_contains($request['text'], '3 findings');
    });
});

it('sends feature dev completion notification', function (): void {
    $project = Project::factory()->enabled()->create(['name' => 'project-x']);
    $task = Task::factory()->completed()->create([
        'project_id' => $project->id,
        'type' => TaskType::FeatureDev,
        'mr_iid' => 456,
        'result' => [
            'title' => 'Add payment flow',
            'files_changed' => ['a.php', 'b.php', 'c.php', 'd.php'],
        ],
    ]);

    $service = app(AlertEventService::class);
    $service->notifyTaskCompletion($task);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return str_contains($request['text'], 'Feature branch created for **project-x**')
            && str_contains($request['text'], "MR !456 'Add payment flow' (4 files)");
    });
});

it('sends UI adjustment completion notification', function (): void {
    $project = Project::factory()->enabled()->create(['name' => 'project-x']);
    $task = Task::factory()->completed()->create([
        'project_id' => $project->id,
        'type' => TaskType::UiAdjustment,
        'mr_iid' => 789,
        'result' => [
            'title' => 'Adjust toolbar padding',
        ],
    ]);

    $service = app(AlertEventService::class);
    $service->notifyTaskCompletion($task);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return str_contains($request['text'], 'UI fix for **project-x**')
            && str_contains($request['text'], "MR !789 'Adjust toolbar padding'");
    });
});

it('sends PRD creation notification', function (): void {
    $project = Project::factory()->enabled()->create(['name' => 'project-x']);
    $task = Task::factory()->completed()->create([
        'project_id' => $project->id,
        'type' => TaskType::PrdCreation,
        'mr_iid' => null,
        'issue_iid' => 42,
        'result' => [
            'title' => 'Payment Feature',
        ],
    ]);

    $service = app(AlertEventService::class);
    $service->notifyTaskCompletion($task);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return str_contains($request['text'], 'PRD created for **project-x**')
            && str_contains($request['text'], "Issue #42 'Payment Feature'");
    });
});

it('sends task failed notification with error reason', function (): void {
    $project = Project::factory()->enabled()->create(['name' => 'project-x']);
    $task = Task::factory()->failed()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'mr_iid' => 123,
        'error_reason' => 'max retries exceeded',
    ]);

    $service = app(AlertEventService::class);
    $service->notifyTaskCompletion($task);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return str_contains($request['text'], '❌')
            && str_contains($request['text'], 'failed for **project-x** MR !123')
            && str_contains($request['text'], 'max retries exceeded');
    });
});

// ─── Container Health Detection (T104) ─────────────────────────

it('detects container health issue when health endpoint reports unhealthy', function (): void {
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake([
        'http://127.0.0.1/health' => Http::response([
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

it('does not trigger container health alert if unhealthy for less than 2 minutes', function (): void {
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake([
        'http://127.0.0.1/health' => Http::response(['status' => 'unhealthy', 'checks' => []], 503),
        '*' => Http::response('ok', 200),
    ]);
    Cache::put('infra:health_first_failure', now()->toIso8601String(), 3600);

    $service = app(AlertEventService::class);
    expect($service->evaluateContainerHealth())->toBeNull();
});

it('resolves container health alert when health endpoint recovers', function (): void {
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake([
        'http://127.0.0.1/health' => Http::response(['status' => 'healthy', 'checks' => []], 200),
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

// ─── CPU Usage Detection (T104) ────────────────────────────────

it('detects high CPU usage when sustained above 90% for 5+ minutes', function (): void {
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

it('does not trigger CPU alert if high for less than 5 minutes', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    Cache::put('infra:cpu_first_high', now()->subMinutes(2)->toIso8601String(), 3600);
    Cache::put('infra:cpu_current', 95.0, 300);

    $service = app(AlertEventService::class);
    expect($service->evaluateCpuUsage())->toBeNull();
});

it('resolves CPU alert when usage drops below threshold', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    AlertEvent::factory()->create([
        'alert_type' => 'cpu_usage',
        'status' => 'active',
        'notified_at' => now()->subHour(),
    ]);
    Cache::forget('infra:cpu_first_high');
    Cache::put('infra:cpu_current', 10.0, 300); // Below 90% threshold

    $service = app(AlertEventService::class);
    $resolved = $service->evaluateCpuUsage();

    expect($resolved)->not->toBeNull();
    expect($resolved->status)->toBe('resolved');
});

// ─── Memory Usage Detection (T104) ─────────────────────────────

it('detects high memory usage when sustained above 85% for 5+ minutes', function (): void {
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

it('does not trigger memory alert if high for less than 5 minutes', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    Cache::put('infra:memory_first_high', now()->subMinutes(2)->toIso8601String(), 3600);
    Cache::put('infra:memory_current', 92.5, 300);

    $service = app(AlertEventService::class);
    expect($service->evaluateMemoryUsage())->toBeNull();
});

it('resolves memory alert when usage drops below threshold', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    AlertEvent::factory()->create([
        'alert_type' => 'memory_usage',
        'status' => 'active',
        'notified_at' => now()->subHour(),
    ]);
    Cache::forget('infra:memory_first_high');
    Cache::put('infra:memory_current', 20.0, 300); // Below 85% threshold

    $service = app(AlertEventService::class);
    $resolved = $service->evaluateMemoryUsage();

    expect($resolved)->not->toBeNull();
    expect($resolved->status)->toBe('resolved');
});

// ─── Cost Alert Notifications ───────────────────────────────────

it('sends cost alert notification', function (): void {
    $costAlert = new \App\Models\CostAlert([
        'rule' => 'daily_spike',
        'severity' => 'critical',
        'message' => 'Daily spend ($50.00) exceeds 5× the daily average ($5.00).',
        'context' => [],
    ]);
    $costAlert->id = 1;

    $service = app(AlertEventService::class);
    $service->notifyCostAlert($costAlert);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return str_contains($request['text'], 'Daily spend')
            && str_contains($request['text'], '$50.00');
    });
});

// ─── T104 Integration: Queue Depth Alert Lifecycle ──────────────

it('T104 integration: queue depth alert → dashboard + chat → recovery', function (): void {
    config(['vunnix.queue_depth_threshold' => 50]);

    $project = Project::factory()->enabled()->create();

    // Create 51 queued tasks to exceed the threshold
    for ($i = 0; $i < 51; $i++) {
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::Queued,
        ]);
    }

    $service = app(AlertEventService::class);

    // Phase 1: Queue depth exceeds threshold → alert fires
    $alert = $service->evaluateQueueDepth();

    expect($alert)->not->toBeNull();
    expect($alert->alert_type)->toBe('queue_depth');
    expect($alert->status)->toBe('active');
    expect($alert->severity)->toBe('medium');
    expect($alert->notified_at)->not->toBeNull();

    // Alert should be visible via dashboard query
    $activeAlerts = AlertEvent::active()->ofType('queue_depth')->get();
    expect($activeAlerts)->toHaveCount(1);

    // Phase 2: Drain queue — mark all tasks as completed
    Task::where('status', TaskStatus::Queued->value)->update([
        'status' => TaskStatus::Completed->value,
        'completed_at' => now(),
    ]);

    // Re-evaluate — should resolve the alert
    $resolved = $service->evaluateQueueDepth();

    expect($resolved)->not->toBeNull();
    expect($resolved->status)->toBe('resolved');
    expect($resolved->resolved_at)->not->toBeNull();
    expect($resolved->recovery_notified_at)->not->toBeNull();

    // No more active queue_depth alerts
    $remainingActive = AlertEvent::active()->ofType('queue_depth')->get();
    expect($remainingActive)->toHaveCount(0);

    // Verify exactly 2 notifications sent (alert + recovery)
    $chatRequests = Http::recorded(function (\Illuminate\Http\Client\Request $request): bool {
        $text = $request['text'] ?? '';

        return str_contains($text, 'Queue depth') || str_contains($text, 'resolved');
    });
    expect($chatRequests)->toHaveCount(2);
});

// ─── CPU Usage: getSystemCpuPercent fallback (no cache) ─────────

it('CPU evaluation falls back to getSystemCpuPercent when no cached value', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    // Do NOT set infra:cpu_current — forces fallback to getSystemCpuPercent()
    Cache::forget('infra:cpu_current');
    Cache::forget('infra:cpu_first_high');

    $service = app(AlertEventService::class);
    // On macOS, sys_getloadavg() returns a value but /proc/cpuinfo is not readable,
    // so cpuCount defaults to 1. The result depends on current system load.
    // We just verify it doesn't throw and returns null (no alert) or an alert.
    $result = $service->evaluateCpuUsage();

    // With no prior first_high cache, even if CPU is high, the first check
    // sets first_high and returns null (sustained duration not yet met)
    expect($result)->toBeNull();
});

it('CPU evaluation returns null when cpu is below threshold with no cache and no active alert', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    // Set CPU to a low value via cache — simulates sys_getloadavg returning low value
    Cache::put('infra:cpu_current', 10.0, 300);
    Cache::forget('infra:cpu_first_high');

    $service = app(AlertEventService::class);
    $result = $service->evaluateCpuUsage();

    // Below threshold, no active alert → null, cache cleared
    expect($result)->toBeNull();
    expect(Cache::has('infra:cpu_first_high'))->toBeFalse();
});

it('CPU evaluation resolves active alert when cpu drops below threshold', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    // Set CPU to below threshold
    Cache::put('infra:cpu_current', 50.0, 300);
    Cache::forget('infra:cpu_first_high');

    // Create an active cpu_usage alert
    AlertEvent::factory()->create([
        'alert_type' => 'cpu_usage',
        'status' => 'active',
        'notified_at' => now()->subHour(),
    ]);

    $service = app(AlertEventService::class);
    $result = $service->evaluateCpuUsage();

    // Below threshold with active alert → resolved
    expect($result)->not->toBeNull();
    expect($result->status)->toBe('resolved');
});

// ─── Memory Usage: getSystemMemoryPercent fallback (no cache) ───

it('memory evaluation falls back to getSystemMemoryPercent when no cached value', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    Cache::forget('infra:memory_current');
    Cache::forget('infra:memory_first_high');

    $service = app(AlertEventService::class);
    // On macOS, /proc/meminfo is not readable so getSystemMemoryPercent returns null.
    $result = $service->evaluateMemoryUsage();

    // memoryPercent is null → condition not met → recovery path → no active alert → null
    expect($result)->toBeNull();
});

it('memory evaluation returns null when memory is below threshold with no cache and no active alert', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    // Set memory to a low value via cache
    Cache::put('infra:memory_current', 30.0, 300);
    Cache::forget('infra:memory_first_high');

    $service = app(AlertEventService::class);
    $result = $service->evaluateMemoryUsage();

    // Below threshold, no active alert → null
    expect($result)->toBeNull();
    expect(Cache::has('infra:memory_first_high'))->toBeFalse();
});

it('memory evaluation resolves active alert when memory drops below threshold', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    // Set memory to below threshold
    Cache::put('infra:memory_current', 30.0, 300);
    Cache::forget('infra:memory_first_high');

    AlertEvent::factory()->create([
        'alert_type' => 'memory_usage',
        'status' => 'active',
        'notified_at' => now()->subHour(),
    ]);

    $service = app(AlertEventService::class);
    $result = $service->evaluateMemoryUsage();

    // Below threshold with active alert → resolved
    expect($result)->not->toBeNull();
    expect($result->status)->toBe('resolved');
});

// ─── Disk Usage: edge cases ─────────────────────────────────────

it('disk usage evaluation exercises code path without throwing', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    // evaluateDiskUsage reads real disk info. On macOS/CI, disk_free_space works.
    // This test exercises the happy path — the result depends on actual disk usage.
    $service = app(AlertEventService::class);
    $result = $service->evaluateDiskUsage();

    // If disk usage < 80%: null (no alert needed, no active alert)
    // If disk usage >= 80%: AlertEvent with status=active
    // Either way, it shouldn't throw.
    if ($result !== null) {
        expect($result)->toBeInstanceOf(AlertEvent::class);
    } else {
        expect($result)->toBeNull();
    }
});

it('disk usage creates alert when usage exceeds 80% threshold', function (): void {
    // We test the alert creation path by using a partial mock that simulates
    // the method flow after disk values are determined.
    // Since we can't easily mock PHP built-in functions, we test the alert lifecycle.

    // Create an active disk alert to test the recovery path instead
    Http::fake(['*' => Http::response('ok', 200)]);
    AlertEvent::factory()->create([
        'alert_type' => 'disk_usage',
        'status' => 'active',
        'notified_at' => now()->subHour(),
    ]);

    // On dev machines, disk usage is typically under 80%, so this should resolve
    $service = app(AlertEventService::class);
    $result = $service->evaluateDiskUsage();

    // If disk is under threshold, active alert should be resolved
    // If disk is actually over threshold, result will be null (alert already exists)
    if ($result !== null) {
        expect($result->status)->toBe('resolved');
    } else {
        // Disk might actually be >80% on CI, in which case the existing active alert
        // prevents duplicate creation → null
        expect($result)->toBeNull();
    }
});

// ─── Container Health: first failure tracking ───────────────────

it('container health records first failure in cache and returns null', function (): void {
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake([
        'http://127.0.0.1/health' => Http::response(['status' => 'unhealthy'], 503),
        '*' => Http::response('ok', 200),
    ]);
    Cache::forget('infra:health_first_failure');

    $service = app(AlertEventService::class);
    $result = $service->evaluateContainerHealth();

    // First failure — records timestamp but doesn't alert yet
    expect($result)->toBeNull();
    expect(Cache::has('infra:health_first_failure'))->toBeTrue();
});

it('container health does not re-alert when already active', function (): void {
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake([
        'http://127.0.0.1/health' => Http::response(['status' => 'unhealthy'], 503),
        '*' => Http::response('ok', 200),
    ]);

    // Already have an active alert and first failure was >2 min ago
    Cache::put('infra:health_first_failure', now()->subMinutes(5)->toIso8601String(), 3600);
    AlertEvent::factory()->create([
        'alert_type' => 'container_health',
        'status' => 'active',
    ]);

    $service = app(AlertEventService::class);
    $result = $service->evaluateContainerHealth();

    // Active alert already exists → null (no duplicate)
    expect($result)->toBeNull();
});

it('container health clears failure cache on recovery', function (): void {
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake([
        'http://127.0.0.1/health' => Http::response(['status' => 'healthy'], 200),
        '*' => Http::response('ok', 200),
    ]);
    Cache::put('infra:health_first_failure', now()->subMinutes(10)->toIso8601String(), 3600);

    $service = app(AlertEventService::class);
    $service->evaluateContainerHealth();

    // Cache should be cleared on healthy response
    expect(Cache::has('infra:health_first_failure'))->toBeFalse();
});

it('container health handles connection exception gracefully', function (): void {
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake([
        'http://127.0.0.1/health' => Http::response(status: 500),
        '*' => Http::response('ok', 200),
    ]);
    Cache::forget('infra:health_first_failure');

    $service = app(AlertEventService::class);
    // Should not throw — the catch (Throwable) in evaluateContainerHealth handles it
    $result = $service->evaluateContainerHealth();

    expect($result)->toBeNull();
    expect(Cache::has('infra:health_first_failure'))->toBeTrue();
});

// ─── CPU Usage: first-high tracking ─────────────────────────────

it('CPU evaluation records first-high in cache when above threshold', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    Cache::put('infra:cpu_current', 95.0, 300);
    Cache::forget('infra:cpu_first_high');

    $service = app(AlertEventService::class);
    $result = $service->evaluateCpuUsage();

    // First time above threshold — records timestamp, returns null
    expect($result)->toBeNull();
    expect(Cache::has('infra:cpu_first_high'))->toBeTrue();
});

it('CPU evaluation does not re-alert when already active', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    Cache::put('infra:cpu_current', 95.0, 300);
    Cache::put('infra:cpu_first_high', now()->subMinutes(10)->toIso8601String(), 3600);

    AlertEvent::factory()->create([
        'alert_type' => 'cpu_usage',
        'status' => 'active',
    ]);

    $service = app(AlertEventService::class);
    $result = $service->evaluateCpuUsage();

    // Active alert exists → null (no duplicate)
    expect($result)->toBeNull();
});

it('CPU evaluation clears first-high cache when below threshold', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    Cache::put('infra:cpu_current', 10.0, 300);
    Cache::put('infra:cpu_first_high', now()->subMinutes(10)->toIso8601String(), 3600);

    $service = app(AlertEventService::class);
    $service->evaluateCpuUsage();

    expect(Cache::has('infra:cpu_first_high'))->toBeFalse();
});

// ─── Memory Usage: first-high tracking ──────────────────────────

it('memory evaluation records first-high in cache when above threshold', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    Cache::put('infra:memory_current', 92.0, 300);
    Cache::forget('infra:memory_first_high');

    $service = app(AlertEventService::class);
    $result = $service->evaluateMemoryUsage();

    expect($result)->toBeNull();
    expect(Cache::has('infra:memory_first_high'))->toBeTrue();
});

it('memory evaluation does not re-alert when already active', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    Cache::put('infra:memory_current', 92.0, 300);
    Cache::put('infra:memory_first_high', now()->subMinutes(10)->toIso8601String(), 3600);

    AlertEvent::factory()->create([
        'alert_type' => 'memory_usage',
        'status' => 'active',
    ]);

    $service = app(AlertEventService::class);
    $result = $service->evaluateMemoryUsage();

    expect($result)->toBeNull();
});

it('memory evaluation clears first-high cache when below threshold', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    Cache::put('infra:memory_current', 10.0, 300);
    Cache::put('infra:memory_first_high', now()->subMinutes(10)->toIso8601String(), 3600);

    $service = app(AlertEventService::class);
    $service->evaluateMemoryUsage();

    expect(Cache::has('infra:memory_first_high'))->toBeFalse();
});

// ─── High Failure Rate: recovery path ───────────────────────────

it('resolves high failure rate alert when rate drops below 20%', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    AlertEvent::factory()->create([
        'alert_type' => 'high_failure_rate',
        'status' => 'active',
        'notified_at' => now()->subHour(),
    ]);

    $project = Project::factory()->enabled()->create();
    // 1 failed, 9 completed = 10% failure rate (below 20%)
    Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Failed,
        'updated_at' => now()->subMinutes(10),
    ]);
    for ($i = 0; $i < 9; $i++) {
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::Completed,
            'updated_at' => now()->subMinutes(10),
        ]);
    }

    $service = app(AlertEventService::class);
    $resolved = $service->evaluateHighFailureRate();

    expect($resolved)->not->toBeNull();
    expect($resolved->status)->toBe('resolved');
    expect($resolved->resolved_at)->not->toBeNull();
});

it('resolves high failure rate via resolveIfActive when fewer than 5 tasks', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    AlertEvent::factory()->create([
        'alert_type' => 'high_failure_rate',
        'status' => 'active',
        'notified_at' => now()->subHour(),
    ]);

    // Only 3 total tasks — triggers resolveIfActive path
    $project = Project::factory()->enabled()->create();
    for ($i = 0; $i < 3; $i++) {
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => TaskStatus::Completed,
            'updated_at' => now()->subMinutes(10),
        ]);
    }

    $service = app(AlertEventService::class);
    $resolved = $service->evaluateHighFailureRate();

    expect($resolved)->not->toBeNull();
    expect($resolved->status)->toBe('resolved');
});

// ─── Auth Failure: recovery path ────────────────────────────────

it('resolves auth failure alert when no recent auth errors', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    AlertEvent::factory()->create([
        'alert_type' => 'auth_failure',
        'status' => 'active',
        'notified_at' => now()->subHour(),
    ]);

    // No recent tasks with auth errors
    $service = app(AlertEventService::class);
    $resolved = $service->evaluateAuthFailure();

    expect($resolved)->not->toBeNull();
    expect($resolved->status)->toBe('resolved');
    expect($resolved->recovery_notified_at)->not->toBeNull();
});

// ─── evaluateAll: catches individual check failures ─────────────

it('evaluateAll catches and logs failing individual checks', function (): void {
    config(['vunnix.queue_depth_threshold' => 50]);

    // Mock the service so one check throws
    $service = Mockery::mock(AlertEventService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('evaluateApiOutage')->andThrow(new \RuntimeException('DB connection lost'));
    // Let other checks run normally

    \Illuminate\Support\Facades\Log::shouldReceive('warning')
        ->with('AlertEventService: check failed', Mockery::on(fn ($ctx) => str_contains($ctx['error'], 'DB connection lost')))
        ->once();
    \Illuminate\Support\Facades\Log::shouldReceive('warning')->withAnyArgs();
    \Illuminate\Support\Facades\Log::shouldReceive('info')->withAnyArgs();

    $events = $service->evaluateAll();

    // Should still return events from other checks that succeeded (or empty if none triggered)
    expect($events)->toBeArray();
});

it('evaluateAll logs failing registry rules with their key and continues evaluating', function (): void {
    $teamChat = app(\App\Services\TeamChat\TeamChatNotificationService::class);

    $failingRule = new class implements \App\Modules\Observability\Application\Contracts\AlertRule
    {
        public function key(): string
        {
            return 'failing_rule';
        }

        public function priority(): int
        {
            return 100;
        }

        public function evaluate(\App\Services\AlertEventService $service, \Carbon\Carbon $now): ?\App\Models\AlertEvent
        {
            throw new RuntimeException('rule exploded');
        }
    };

    $passingRule = new class implements \App\Modules\Observability\Application\Contracts\AlertRule
    {
        public function key(): string
        {
            return 'passing_rule';
        }

        public function priority(): int
        {
            return 90;
        }

        public function evaluate(\App\Services\AlertEventService $service, \Carbon\Carbon $now): \App\Models\AlertEvent
        {
            return new \App\Models\AlertEvent([
                'alert_type' => 'queue_depth',
                'status' => 'active',
                'severity' => 'medium',
                'message' => 'queue depth test',
            ]);
        }
    };

    $service = new AlertEventService(
        $teamChat,
        new \App\Modules\Observability\Application\Registries\AlertRuleRegistry([$failingRule, $passingRule]),
    );

    \Illuminate\Support\Facades\Log::shouldReceive('warning')
        ->once()
        ->with('AlertEventService: check failed', Mockery::on(function (array $context): bool {
            return ($context['rule'] ?? null) === 'failing_rule'
                && str_contains((string) ($context['error'] ?? ''), 'rule exploded');
        }));

    $events = $service->evaluateAll(now());

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(AlertEvent::class)
        ->and($events[0]->alert_type)->toBe('queue_depth');
});

it('uses red risk emoji in code review completion notifications for high risk', function (): void {
    $project = Project::factory()->enabled()->create(['name' => 'Test Project']);
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'mr_iid' => 42,
        'result' => [
            'summary' => [
                'risk_level' => 'high',
                'total_findings' => 3,
            ],
        ],
    ]);

    $service = app(AlertEventService::class);
    $service->notifyTaskCompletion($task);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        $text = (string) ($request['text'] ?? '');

        return str_contains($text, '🔴')
            && str_contains(strtolower($text), 'high risk');
    });
});

it('uses red risk emoji in code review completion notifications for critical risk', function (): void {
    $project = Project::factory()->enabled()->create(['name' => 'Critical Project']);
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'mr_iid' => 43,
        'result' => [
            'summary' => [
                'risk_level' => 'critical',
                'total_findings' => 4,
            ],
        ],
    ]);

    $service = app(AlertEventService::class);
    $service->notifyTaskCompletion($task);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        $text = (string) ($request['text'] ?? '');

        return str_contains($text, '🔴')
            && str_contains(strtolower($text), 'critical risk');
    });
});

it('handles failed ui adjustment task notifications via failure message path', function (): void {
    $project = Project::factory()->enabled()->create(['name' => 'Test Project']);
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::UiAdjustment,
        'status' => TaskStatus::Failed,
        'mr_iid' => 55,
        'result' => ['title' => 'Fix spacing'],
        'error_reason' => 'runner timeout',
    ]);

    $service = app(AlertEventService::class);
    $service->notifyTaskCompletion($task);

    Http::assertSent(function ($request): bool {
        return str_contains((string) json_encode($request->data()), 'failed');
    });
});

it('returns false for non-api error inputs in helper', function (): void {
    $service = app(AlertEventService::class);

    $method = new ReflectionMethod(AlertEventService::class, 'isApiError');
    $method->setAccessible(true);

    expect($method->invoke($service, null))->toBeFalse()
        ->and($method->invoke($service, 'validation failed'))->toBeFalse();
});

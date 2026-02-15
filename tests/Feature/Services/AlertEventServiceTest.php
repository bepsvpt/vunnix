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

beforeEach(function () {
    Http::fake([
        '*' => Http::response('ok', 200),
    ]);

    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://hooks.slack.com/services/T/B/x', 'string');
    GlobalSetting::set('team_chat_platform', 'slack', 'string');
});

// ─── API Outage Detection ───────────────────────────────────────

it('detects API outage with 3+ consecutive API failures', function () {
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

    Http::assertSent(fn ($r) => str_contains($r['text'], 'API outage detected'));
});

it('does not trigger API outage with fewer than 3 failures', function () {
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

it('does not duplicate API outage if already active', function () {
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

it('resolves API outage and sends recovery notification', function () {
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

    Http::assertSent(fn ($r) => str_contains($r['text'], 'resolved'));
});

// ─── High Failure Rate Detection ────────────────────────────────

it('detects high failure rate when >20% failing', function () {
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

it('does not trigger high failure rate with low failure count', function () {
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

it('skips high failure rate with fewer than 5 total tasks', function () {
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

it('detects queue depth exceeding threshold', function () {
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

it('resolves queue depth when back below threshold', function () {
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

it('detects auth failure from 401 errors', function () {
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

it('deduplicates: ongoing condition does not re-trigger', function () {
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

it('sends exactly one detection and one recovery notification', function () {
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

it('evaluateAll runs all checks and returns events', function () {
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
    $types = array_map(fn ($e) => $e->alert_type, $events);
    expect($types)->toContain('queue_depth');
});

// ─── Task Completion Notifications ──────────────────────────────

it('sends code review completion notification', function () {
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

    Http::assertSent(function ($request) {
        return str_contains($request['text'], 'Review complete on **my-project** MR !123')
            && str_contains($request['text'], 'Medium risk')
            && str_contains($request['text'], '3 findings');
    });
});

it('sends feature dev completion notification', function () {
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

    Http::assertSent(function ($request) {
        return str_contains($request['text'], 'Feature branch created for **project-x**')
            && str_contains($request['text'], "MR !456 'Add payment flow' (4 files)");
    });
});

it('sends UI adjustment completion notification', function () {
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

    Http::assertSent(function ($request) {
        return str_contains($request['text'], 'UI fix for **project-x**')
            && str_contains($request['text'], "MR !789 'Adjust toolbar padding'");
    });
});

it('sends PRD creation notification', function () {
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

    Http::assertSent(function ($request) {
        return str_contains($request['text'], 'PRD created for **project-x**')
            && str_contains($request['text'], "Issue #42 'Payment Feature'");
    });
});

it('sends task failed notification with error reason', function () {
    $project = Project::factory()->enabled()->create(['name' => 'project-x']);
    $task = Task::factory()->failed()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'mr_iid' => 123,
        'error_reason' => 'max retries exceeded',
    ]);

    $service = app(AlertEventService::class);
    $service->notifyTaskCompletion($task);

    Http::assertSent(function ($request) {
        return str_contains($request['text'], '❌')
            && str_contains($request['text'], 'failed for **project-x** MR !123')
            && str_contains($request['text'], 'max retries exceeded');
    });
});

// ─── Container Health Detection (T104) ─────────────────────────

it('detects container health issue when health endpoint reports unhealthy', function () {
    Http::swap(new \Illuminate\Http\Client\Factory());
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

it('does not trigger container health alert if unhealthy for less than 2 minutes', function () {
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake([
        'http://127.0.0.1/health' => Http::response(['status' => 'unhealthy', 'checks' => []], 503),
        '*' => Http::response('ok', 200),
    ]);
    Cache::put('infra:health_first_failure', now()->toIso8601String(), 3600);

    $service = app(AlertEventService::class);
    expect($service->evaluateContainerHealth())->toBeNull();
});

it('resolves container health alert when health endpoint recovers', function () {
    Http::swap(new \Illuminate\Http\Client\Factory());
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
    Cache::put('infra:cpu_current', 10.0, 300); // Below 90% threshold

    $service = app(AlertEventService::class);
    $resolved = $service->evaluateCpuUsage();

    expect($resolved)->not->toBeNull();
    expect($resolved->status)->toBe('resolved');
});

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
    Cache::put('infra:memory_current', 20.0, 300); // Below 85% threshold

    $service = app(AlertEventService::class);
    $resolved = $service->evaluateMemoryUsage();

    expect($resolved)->not->toBeNull();
    expect($resolved->status)->toBe('resolved');
});

// ─── Cost Alert Notifications ───────────────────────────────────

it('sends cost alert notification', function () {
    $costAlert = new \App\Models\CostAlert([
        'rule' => 'daily_spike',
        'severity' => 'critical',
        'message' => 'Daily spend ($50.00) exceeds 5× the daily average ($5.00).',
        'context' => [],
    ]);
    $costAlert->id = 1;

    $service = app(AlertEventService::class);
    $service->notifyCostAlert($costAlert);

    Http::assertSent(function ($request) {
        return str_contains($request['text'], 'Daily spend')
            && str_contains($request['text'], '$50.00');
    });
});

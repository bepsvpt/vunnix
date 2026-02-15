<?php

namespace App\Services;

use App\Models\AlertEvent;
use App\Services\TeamChat\TeamChatNotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class AlertEventService
{
    public function __construct(
        private readonly TeamChatNotificationService $teamChat,
    ) {}

    /**
     * Evaluate all system alert rules. Returns newly created or resolved alerts.
     *
     * @return AlertEvent[]
     */
    public function evaluateAll(?Carbon $now = null): array
    {
        $now ??= now();
        $events = [];

        $checks = [
            'api_outage' => fn () => $this->evaluateApiOutage($now),
            'high_failure_rate' => fn () => $this->evaluateHighFailureRate($now),
            'queue_depth' => fn () => $this->evaluateQueueDepth($now),
            'auth_failure' => fn () => $this->evaluateAuthFailure($now),
            'disk_usage' => fn () => $this->evaluateDiskUsage($now),
            'container_health' => fn () => $this->evaluateContainerHealth($now),
            'cpu_usage' => fn () => $this->evaluateCpuUsage($now),
            'memory_usage' => fn () => $this->evaluateMemoryUsage($now),
        ];

        foreach ($checks as $check) {
            try {
                if ($event = $check()) {
                    $events[] = $event;
                }
            } catch (\Throwable $e) {
                Log::warning('AlertEventService: check failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $events;
    }

    /**
     * API outage: 3+ consecutive task failures with API errors.
     * Recovery: first successful task after detected outage.
     */
    public function evaluateApiOutage(?Carbon $now = null): ?AlertEvent
    {
        $now ??= now();

        // Check last 5 tasks for consecutive API errors
        $recentTasks = DB::table('tasks')
            ->whereIn('status', ['completed', 'failed'])
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get(['status', 'error_reason']);

        $consecutiveApiErrors = 0;
        foreach ($recentTasks as $task) {
            if ($task->status === 'failed' && $this->isApiError($task->error_reason)) {
                $consecutiveApiErrors++;
            } else {
                break;
            }
        }

        $activeAlert = AlertEvent::active()->ofType('api_outage')->first();

        if ($consecutiveApiErrors >= 3 && ! $activeAlert) {
            // Trigger new alert
            $alert = AlertEvent::create([
                'alert_type' => 'api_outage',
                'status' => 'active',
                'severity' => 'high',
                'message' => "API outage detected — {$consecutiveApiErrors} consecutive task failures with API errors.",
                'context' => ['consecutive_failures' => $consecutiveApiErrors],
                'detected_at' => $now,
            ]);

            $this->notifyAlert($alert);

            return $alert;
        }

        if ($consecutiveApiErrors === 0 && $activeAlert) {
            // Recovery — first success after outage
            $activeAlert->update([
                'status' => 'resolved',
                'resolved_at' => $now,
            ]);

            $this->notifyRecovery($activeAlert);

            return $activeAlert;
        }

        return null;
    }

    /**
     * High failure rate: >20% of tasks failing in the last hour.
     */
    public function evaluateHighFailureRate(?Carbon $now = null): ?AlertEvent
    {
        $now ??= now();
        $oneHourAgo = $now->copy()->subHour();

        $total = DB::table('tasks')
            ->whereIn('status', ['completed', 'failed'])
            ->where('updated_at', '>=', $oneHourAgo)
            ->count();

        if ($total < 5) {
            // Not enough data to evaluate
            return $this->resolveIfActive('high_failure_rate', $now);
        }

        $failed = DB::table('tasks')
            ->where('status', 'failed')
            ->where('updated_at', '>=', $oneHourAgo)
            ->count();

        $failureRate = $failed / $total;
        $activeAlert = AlertEvent::active()->ofType('high_failure_rate')->first();

        if ($failureRate > 0.20 && ! $activeAlert) {
            $percent = round($failureRate * 100, 1);
            $alert = AlertEvent::create([
                'alert_type' => 'high_failure_rate',
                'status' => 'active',
                'severity' => 'medium',
                'message' => "High failure rate detected — {$percent}% of tasks failing in the last hour ({$failed}/{$total}).",
                'context' => [
                    'failure_rate' => $failureRate,
                    'failed_count' => $failed,
                    'total_count' => $total,
                ],
                'detected_at' => $now,
            ]);

            $this->notifyAlert($alert);

            return $alert;
        }

        if ($failureRate <= 0.20 && $activeAlert) {
            $activeAlert->update([
                'status' => 'resolved',
                'resolved_at' => $now,
            ]);

            $this->notifyRecovery($activeAlert);

            return $activeAlert;
        }

        return null;
    }

    /**
     * Queue depth: queue depth exceeds threshold (default: 50).
     */
    public function evaluateQueueDepth(?Carbon $now = null): ?AlertEvent
    {
        $now ??= now();

        $queueDepth = DB::table('tasks')
            ->whereIn('status', ['received', 'queued'])
            ->count();

        $threshold = (int) config('vunnix.queue_depth_threshold', 50);
        $activeAlert = AlertEvent::active()->ofType('queue_depth')->first();

        if ($queueDepth > $threshold && ! $activeAlert) {
            $alert = AlertEvent::create([
                'alert_type' => 'queue_depth',
                'status' => 'active',
                'severity' => 'medium',
                'message' => "Queue depth growing — {$queueDepth} tasks pending (threshold: {$threshold}).",
                'context' => [
                    'queue_depth' => $queueDepth,
                    'threshold' => $threshold,
                ],
                'detected_at' => $now,
            ]);

            $this->notifyAlert($alert);

            return $alert;
        }

        if ($queueDepth <= $threshold && $activeAlert) {
            $activeAlert->update([
                'status' => 'resolved',
                'resolved_at' => $now,
            ]);

            $this->notifyRecovery($activeAlert);

            return $activeAlert;
        }

        return null;
    }

    /**
     * Auth failure: 401 from Claude API (API key issue).
     * Detected via recent task failures with auth-related errors.
     */
    public function evaluateAuthFailure(?Carbon $now = null): ?AlertEvent
    {
        $now ??= now();
        $thirtyMinutesAgo = $now->copy()->subMinutes(30);

        $authFailures = DB::table('tasks')
            ->where('status', 'failed')
            ->where('updated_at', '>=', $thirtyMinutesAgo)
            ->where(function ($q) {
                $q->where('error_reason', 'like', '%401%')
                    ->orWhere('error_reason', 'like', '%unauthorized%')
                    ->orWhere('error_reason', 'like', '%authentication%')
                    ->orWhere('error_reason', 'like', '%api_key%')
                    ->orWhere('error_reason', 'like', '%invalid_api_key%');
            })
            ->count();

        $activeAlert = AlertEvent::active()->ofType('auth_failure')->first();

        if ($authFailures >= 2 && ! $activeAlert) {
            $alert = AlertEvent::create([
                'alert_type' => 'auth_failure',
                'status' => 'active',
                'severity' => 'high',
                'message' => "Authentication failure detected — {$authFailures} tasks failed with auth errors in the last 30 minutes. Check your API key configuration.",
                'context' => ['auth_failure_count' => $authFailures],
                'detected_at' => $now,
            ]);

            $this->notifyAlert($alert);

            return $alert;
        }

        if ($authFailures === 0 && $activeAlert) {
            $activeAlert->update([
                'status' => 'resolved',
                'resolved_at' => $now,
            ]);

            $this->notifyRecovery($activeAlert);

            return $activeAlert;
        }

        return null;
    }

    /**
     * Disk usage: storage exceeds 80% threshold.
     */
    public function evaluateDiskUsage(?Carbon $now = null): ?AlertEvent
    {
        $now ??= now();

        try {
            $path = storage_path();
            $freeBytes = disk_free_space($path);
            $totalBytes = disk_total_space($path);

            if ($freeBytes === false || $totalBytes === false) {
                return null;
            }

            $usedPercent = round((1 - $freeBytes / $totalBytes) * 100, 1);
        } catch (\Throwable) {
            return null;
        }

        $threshold = 80.0;
        $activeAlert = AlertEvent::active()->ofType('disk_usage')->first();

        if ($usedPercent >= $threshold && ! $activeAlert) {
            $alert = AlertEvent::create([
                'alert_type' => 'disk_usage',
                'status' => 'active',
                'severity' => 'medium',
                'message' => "Disk usage warning — storage at {$usedPercent}% (threshold: {$threshold}%).",
                'context' => [
                    'used_percent' => $usedPercent,
                    'threshold' => $threshold,
                ],
                'detected_at' => $now,
            ]);

            $this->notifyAlert($alert);

            return $alert;
        }

        if ($usedPercent < $threshold && $activeAlert) {
            $activeAlert->update([
                'status' => 'resolved',
                'resolved_at' => $now,
            ]);

            $this->notifyRecovery($activeAlert);

            return $activeAlert;
        }

        return null;
    }

    /**
     * Container health: polls /health endpoint, sustained failure >2 min.
     */
    public function evaluateContainerHealth(?Carbon $now = null): ?AlertEvent
    {
        $now ??= now();
        $activeAlert = AlertEvent::active()->ofType('container_health')->first();

        try {
            $response = Http::timeout(5)->get('http://127.0.0.1/health');
            $healthy = $response->successful() && ($response->json('status') === 'healthy');
        } catch (\Throwable) {
            $healthy = false;
        }

        if (! $healthy) {
            $firstFailure = Cache::get('infra:health_first_failure');
            if (! $firstFailure) {
                Cache::put('infra:health_first_failure', $now->toIso8601String(), 3600);

                return null;
            }

            $duration = (int) $now->diffInMinutes(Carbon::parse($firstFailure), absolute: true);
            if ($duration < 2) {
                return null;
            }

            if ($activeAlert) {
                return null;
            }

            $alert = AlertEvent::create([
                'alert_type' => 'container_health',
                'status' => 'active',
                'severity' => 'high',
                'message' => "Container unhealthy for >{$duration} minutes.",
                'context' => ['duration_minutes' => $duration],
                'detected_at' => $now,
            ]);

            $this->notifyAlert($alert);

            return $alert;
        }

        // Healthy — clear failure tracking and resolve active alert
        Cache::forget('infra:health_first_failure');

        if ($activeAlert) {
            $activeAlert->update([
                'status' => 'resolved',
                'resolved_at' => $now,
            ]);

            $this->notifyRecovery($activeAlert);

            return $activeAlert;
        }

        return null;
    }

    /**
     * CPU usage: sustained >90% for >5 minutes.
     * Reads from Cache::get('infra:cpu_current') (set by scheduled collector),
     * falls back to sys_getloadavg() / CPU count.
     */
    public function evaluateCpuUsage(?Carbon $now = null): ?AlertEvent
    {
        $now ??= now();
        $threshold = 90.0;
        $sustainedMinutes = 5;

        $cpuPercent = Cache::get('infra:cpu_current');
        if ($cpuPercent === null) {
            $cpuPercent = $this->getSystemCpuPercent();
        }

        $activeAlert = AlertEvent::active()->ofType('cpu_usage')->first();

        if ($cpuPercent !== null && $cpuPercent > $threshold) {
            $firstHigh = Cache::get('infra:cpu_first_high');
            if (! $firstHigh) {
                Cache::put('infra:cpu_first_high', $now->toIso8601String(), 3600);

                return null;
            }

            $duration = (int) $now->diffInMinutes(Carbon::parse($firstHigh), absolute: true);
            if ($duration < $sustainedMinutes) {
                return null;
            }

            if ($activeAlert) {
                return null;
            }

            $rounded = round($cpuPercent, 1);
            $alert = AlertEvent::create([
                'alert_type' => 'cpu_usage',
                'status' => 'active',
                'severity' => 'high',
                'message' => "CPU usage at {$rounded}% for >{$duration} minutes (threshold: {$threshold}%).",
                'context' => ['cpu_percent' => $rounded, 'duration_minutes' => $duration],
                'detected_at' => $now,
            ]);

            $this->notifyAlert($alert);

            return $alert;
        }

        // Below threshold — clear tracking, resolve if active
        Cache::forget('infra:cpu_first_high');

        if ($activeAlert) {
            $activeAlert->update([
                'status' => 'resolved',
                'resolved_at' => $now,
            ]);

            $this->notifyRecovery($activeAlert);

            return $activeAlert;
        }

        return null;
    }

    /**
     * Memory usage: sustained >85% for >5 minutes.
     * Reads from Cache::get('infra:memory_current') (set by scheduled collector),
     * falls back to system memory info.
     */
    public function evaluateMemoryUsage(?Carbon $now = null): ?AlertEvent
    {
        $now ??= now();
        $threshold = 85.0;
        $sustainedMinutes = 5;

        $memoryPercent = Cache::get('infra:memory_current');
        if ($memoryPercent === null) {
            $memoryPercent = $this->getSystemMemoryPercent();
        }

        $activeAlert = AlertEvent::active()->ofType('memory_usage')->first();

        if ($memoryPercent !== null && $memoryPercent > $threshold) {
            $firstHigh = Cache::get('infra:memory_first_high');
            if (! $firstHigh) {
                Cache::put('infra:memory_first_high', $now->toIso8601String(), 3600);

                return null;
            }

            $duration = (int) $now->diffInMinutes(Carbon::parse($firstHigh), absolute: true);
            if ($duration < $sustainedMinutes) {
                return null;
            }

            if ($activeAlert) {
                return null;
            }

            $rounded = round($memoryPercent, 1);
            $alert = AlertEvent::create([
                'alert_type' => 'memory_usage',
                'status' => 'active',
                'severity' => 'high',
                'message' => "Memory usage at {$rounded}% for >{$duration} minutes (threshold: {$threshold}%).",
                'context' => ['memory_percent' => $rounded, 'duration_minutes' => $duration],
                'detected_at' => $now,
            ]);

            $this->notifyAlert($alert);

            return $alert;
        }

        // Below threshold — clear tracking, resolve if active
        Cache::forget('infra:memory_first_high');

        if ($activeAlert) {
            $activeAlert->update([
                'status' => 'resolved',
                'resolved_at' => $now,
            ]);

            $this->notifyRecovery($activeAlert);

            return $activeAlert;
        }

        return null;
    }

    /**
     * Get CPU usage percentage from system load average.
     */
    private function getSystemCpuPercent(): ?float
    {
        if (! function_exists('sys_getloadavg')) {
            return null;
        }

        $load = sys_getloadavg();
        if ($load === false) {
            return null;
        }

        // Use 1-minute load average, normalize by CPU count
        $cpuCount = 1;
        if (is_readable('/proc/cpuinfo')) {
            $cpuCount = max(1, substr_count((string) file_get_contents('/proc/cpuinfo'), 'processor'));
        }

        return min(100.0, ($load[0] / $cpuCount) * 100);
    }

    /**
     * Get system memory usage percentage.
     */
    private function getSystemMemoryPercent(): ?float
    {
        if (is_readable('/proc/meminfo')) {
            $meminfo = (string) file_get_contents('/proc/meminfo');
            if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $totalMatch)
                && preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $availMatch)) {
                $total = (int) $totalMatch[1];
                $available = (int) $availMatch[1];
                if ($total > 0) {
                    return round((1 - $available / $total) * 100, 1);
                }
            }
        }

        return null;
    }

    /**
     * Send a team chat notification for a newly detected alert.
     */
    public function notifyAlert(AlertEvent $alert): void
    {
        $urgencyMap = [
            'high' => 'high',
            'medium' => 'medium',
            'info' => 'info',
        ];

        $this->teamChat->send('alert', $alert->message, [
            'category' => 'alert',
            'urgency' => $urgencyMap[$alert->severity] ?? 'medium',
            'alert_type' => $alert->alert_type,
        ]);

        $alert->update(['notified_at' => now()]);
    }

    /**
     * Send a team chat recovery notification.
     */
    public function notifyRecovery(AlertEvent $alert): void
    {
        $typeLabels = [
            'api_outage' => 'API outage',
            'high_failure_rate' => 'High failure rate',
            'queue_depth' => 'Queue depth',
            'infrastructure' => 'Infrastructure issue',
            'auth_failure' => 'Auth failure',
            'disk_usage' => 'Disk usage',
            'container_health' => 'Container health',
            'cpu_usage' => 'High CPU usage',
            'memory_usage' => 'High memory usage',
            'bot_pat_rotation' => 'Bot PAT rotation reminder',
        ];

        $label = $typeLabels[$alert->alert_type] ?? $alert->alert_type;
        $message = "✅ {$label} resolved.";

        $this->teamChat->send('alert', $message, [
            'category' => 'alert',
            'urgency' => 'info',
            'alert_type' => $alert->alert_type,
        ]);

        $alert->update(['recovery_notified_at' => now()]);
    }

    /**
     * Send a team chat notification for a cost alert.
     */
    public function notifyCostAlert(\App\Models\CostAlert $costAlert): void
    {
        $severityToUrgency = [
            'critical' => 'high',
            'warning' => 'medium',
        ];

        $this->teamChat->send('alert', $costAlert->message, [
            'category' => 'alert',
            'urgency' => $severityToUrgency[$costAlert->severity] ?? 'medium',
            'alert_type' => 'cost_' . $costAlert->rule,
        ]);
    }

    /**
     * Send a team chat notification for a task completion.
     * Idempotent: uses task_id to prevent duplicates.
     */
    public function notifyTaskCompletion(\App\Models\Task $task): void
    {
        $project = $task->project;
        $projectName = $project?->name ?? 'unknown';

        $message = $this->buildTaskCompletionMessage($task, $projectName);
        $category = $task->status === \App\Enums\TaskStatus::Failed ? 'task_failed' : 'task_completed';

        $this->teamChat->send($category, $message, [
            'category' => $category,
            'urgency' => $task->status === \App\Enums\TaskStatus::Failed ? 'high' : 'info',
            'task_id' => $task->id,
            'project' => $projectName,
        ]);
    }

    /**
     * Build a human-readable message for task completion per §18.2.
     */
    private function buildTaskCompletionMessage(\App\Models\Task $task, string $projectName): string
    {
        $result = $task->result ?? [];

        return match ($task->type) {
            \App\Enums\TaskType::CodeReview => $this->buildCodeReviewMessage($task, $projectName, $result),
            \App\Enums\TaskType::SecurityAudit => $this->buildCodeReviewMessage($task, $projectName, $result),
            \App\Enums\TaskType::FeatureDev => $this->buildFeatureDevMessage($task, $projectName, $result),
            \App\Enums\TaskType::UiAdjustment => $this->buildUiAdjustmentMessage($task, $projectName, $result),
            \App\Enums\TaskType::PrdCreation => $this->buildPrdMessage($task, $projectName, $result),
            default => $this->buildFailedOrGenericMessage($task, $projectName),
        };
    }

    private function buildCodeReviewMessage(\App\Models\Task $task, string $projectName, array $result): string
    {
        if ($task->status === \App\Enums\TaskStatus::Failed) {
            return $this->buildFailedOrGenericMessage($task, $projectName);
        }

        $riskLevel = $result['summary']['risk_level'] ?? 'unknown';
        $findingsCount = $result['summary']['total_findings'] ?? 0;
        $mrIid = $task->mr_iid;

        $riskEmoji = match ($riskLevel) {
            'critical' => '🔴',
            'high' => '🔴',
            'medium' => '🟡',
            'low' => '🟢',
            default => '⚪',
        };

        return "🤖 Review complete on **{$projectName}** MR !{$mrIid} — {$riskEmoji} " . ucfirst($riskLevel) . " risk, {$findingsCount} findings";
    }

    private function buildFeatureDevMessage(\App\Models\Task $task, string $projectName, array $result): string
    {
        if ($task->status === \App\Enums\TaskStatus::Failed) {
            return $this->buildFailedOrGenericMessage($task, $projectName);
        }

        $mrIid = $task->mr_iid;
        $title = $result['title'] ?? $result['mr_title'] ?? 'Feature';
        $filesChanged = $result['files_changed'] ?? [];
        $count = count($filesChanged);

        return "🤖 Feature branch created for **{$projectName}** — MR !{$mrIid} '{$title}' ({$count} files)";
    }

    private function buildUiAdjustmentMessage(\App\Models\Task $task, string $projectName, array $result): string
    {
        if ($task->status === \App\Enums\TaskStatus::Failed) {
            return $this->buildFailedOrGenericMessage($task, $projectName);
        }

        $mrIid = $task->mr_iid;
        $title = $result['title'] ?? $result['mr_title'] ?? 'UI fix';

        return "🤖 UI fix for **{$projectName}** — MR !{$mrIid} '{$title}'";
    }

    private function buildPrdMessage(\App\Models\Task $task, string $projectName, array $result): string
    {
        if ($task->status === \App\Enums\TaskStatus::Failed) {
            return $this->buildFailedOrGenericMessage($task, $projectName);
        }

        $issueIid = $task->issue_iid;
        $title = $result['title'] ?? 'PRD';

        return "🤖 PRD created for **{$projectName}** — Issue #{$issueIid} '{$title}'";
    }

    private function buildFailedOrGenericMessage(\App\Models\Task $task, string $projectName): string
    {
        if ($task->status === \App\Enums\TaskStatus::Failed) {
            $typeLabel = str_replace('_', ' ', $task->type->value);
            $mrRef = $task->mr_iid ? " MR !{$task->mr_iid}" : '';
            $reason = $task->error_reason ?: 'max retries exceeded';

            return "❌ " . ucfirst($typeLabel) . " failed for **{$projectName}**{$mrRef} — {$reason}";
        }

        return "🤖 Task #{$task->id} completed for **{$projectName}**";
    }

    /**
     * Resolve an active alert if it exists (used when conditions no longer met).
     */
    private function resolveIfActive(string $alertType, Carbon $now): ?AlertEvent
    {
        $activeAlert = AlertEvent::active()->ofType($alertType)->first();

        if ($activeAlert) {
            $activeAlert->update([
                'status' => 'resolved',
                'resolved_at' => $now,
            ]);

            $this->notifyRecovery($activeAlert);

            return $activeAlert;
        }

        return null;
    }

    private function isApiError(?string $errorReason): bool
    {
        if ($errorReason === null) {
            return false;
        }

        $patterns = ['api error', 'api_error', '500', '502', '503', '529', 'timeout', 'connection refused', 'overloaded'];

        foreach ($patterns as $pattern) {
            if (str_contains(strtolower($errorReason), $pattern)) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace App\Observers;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Events\TaskStatusChanged;
use App\Models\Task;
use App\Models\TaskMetric;
use App\Services\AlertEventService;
use App\Services\CostAlertService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TaskObserver
{
    /**
     * Log state transitions whenever a task is updated.
     */
    public function updated(Task $task): void
    {
        if (! $task->wasChanged('status')) {
            return;
        }

        DB::table('task_transition_logs')->insert([
            'task_id' => $task->id,
            'from_status' => $task->getOriginal('status') instanceof \App\Enums\TaskStatus
                ? $task->getOriginal('status')->value
                : $task->getOriginal('status'),
            'to_status' => $task->status->value,
            'transitioned_at' => now(),
        ]);

        TaskStatusChanged::dispatch($task);

        $this->recordMetricsOnTerminal($task);
    }

    /**
     * Record a task_metrics row when a task reaches a terminal state.
     *
     * Only fires for Completed and Failed transitions. Superseded tasks
     * have no meaningful execution data, so they are excluded.
     *
     * Idempotency: uses firstOrCreate keyed on task_id to prevent
     * duplicate metrics if the observer fires more than once.
     */
    private function recordMetricsOnTerminal(Task $task): void
    {
        if (! in_array($task->status, [TaskStatus::Completed, TaskStatus::Failed], true)) {
            return;
        }

        // Prevent duplicate metrics
        if (TaskMetric::where('task_id', $task->id)->exists()) {
            return;
        }

        $severities = $this->extractSeverityCounts($task);
        $findingsCount = $this->extractFindingsCount($task);
        $duration = $this->calculateDuration($task);

        try {
            TaskMetric::create([
                'task_id' => $task->id,
                'project_id' => $task->project_id,
                'task_type' => $task->type->value,
                'input_tokens' => $task->input_tokens ?? 0,
                'output_tokens' => $task->output_tokens ?? 0,
                'cost' => $task->cost ?? 0,
                'duration' => $duration,
                'severity_critical' => $severities['critical'],
                'severity_high' => $severities['high'],
                'severity_medium' => $severities['medium'],
                'severity_low' => $severities['low'],
                'findings_count' => $findingsCount,
            ]);

            // Evaluate single-task cost outlier alert (T94)
            if (($task->cost ?? 0) > 0) {
                try {
                    $costAlert = app(CostAlertService::class)->evaluateSingleTaskOutlier(
                        $task->id,
                        $task->type->value,
                        (float) $task->cost,
                    );

                    // Route single-task cost alert to team chat (T99)
                    if ($costAlert !== null) {
                        app(AlertEventService::class)->notifyCostAlert($costAlert);
                    }
                } catch (Throwable $e) {
                    Log::warning('TaskObserver: cost alert evaluation failed', [
                        'task_id' => $task->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (Throwable $e) {
            Log::error('TaskObserver: failed to record metrics', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract severity counts from the task result for review-type tasks.
     *
     * CodeReviewSchema defines severities as critical/major/minor.
     * These map to the metrics columns as:
     *   critical → severity_critical
     *   major    → severity_high
     *   minor    → severity_medium
     *   (severity_low is always 0 — no 'low' severity in the review schema)
     *
     * @return array{critical: int, high: int, medium: int, low: int}
     */
    private function extractSeverityCounts(Task $task): array
    {
        $defaults = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

        if (! $this->isReviewType($task)) {
            return $defaults;
        }

        $result = $task->result;
        $severities = is_array($result) ? ($result['summary']['findings_by_severity'] ?? null) : null;

        if (! is_array($severities)) {
            return $defaults;
        }

        return [
            'critical' => (int) ($severities['critical'] ?? 0),
            'high' => (int) ($severities['major'] ?? 0),
            'medium' => (int) ($severities['minor'] ?? 0),
            'low' => 0,
        ];
    }

    /**
     * Extract total findings count from the task result.
     */
    private function extractFindingsCount(Task $task): int
    {
        if (! $this->isReviewType($task)) {
            return 0;
        }

        $result = $task->result;

        return is_array($result) ? (int) ($result['summary']['total_findings'] ?? 0) : 0;
    }

    /**
     * Calculate task duration in seconds.
     *
     * Prefers the executor-reported duration_seconds (accurate wall-clock time).
     * Falls back to completed_at - started_at if duration_seconds is not available.
     */
    private function calculateDuration(Task $task): int
    {
        if ($task->duration_seconds !== null) {
            return $task->duration_seconds;
        }

        if ($task->started_at !== null && $task->completed_at !== null) {
            return (int) $task->started_at->diffInSeconds($task->completed_at);
        }

        return 0;
    }

    /**
     * Check if a task type produces structured review findings.
     */
    private function isReviewType(Task $task): bool
    {
        return in_array($task->type, [TaskType::CodeReview, TaskType::SecurityAudit], true);
    }
}

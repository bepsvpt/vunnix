<?php

namespace App\Services\Architecture;

use App\Enums\TaskStatus;
use App\Models\ArchitectureIterationMetric;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class IterationMetricsCollector
{
    public function collect(?Carbon $asOf = null): ArchitectureIterationMetric
    {
        $asOf ??= now();
        $weekStart = $asOf->copy()->startOfWeek();
        $weekEnd = $asOf->copy()->endOfWeek();

        $tasks = Task::query()
            ->whereBetween('created_at', [$weekStart, $weekEnd])
            ->get();

        $moduleTouchBreadth = $tasks->pluck('type')->filter()->unique()->count();
        $medianFilesChanged = $this->median($this->extractFilesChangedCounts($tasks));
        $leadTimeHoursP50 = $this->median($this->extractLeadTimes($tasks));
        $reopenedRegressionsCount = $this->countReopenedRegressions($tasks);

        $metric = ArchitectureIterationMetric::query()->updateOrCreate(
            ['snapshot_date' => $weekStart->toDateString()],
            [
                'module_touch_breadth' => $moduleTouchBreadth,
                'median_files_changed' => $medianFilesChanged,
                'fast_lane_minutes_p50' => null, // CI duration source can be injected later.
                'reopened_regressions_count' => $reopenedRegressionsCount,
                'lead_time_hours_p50' => $leadTimeHoursP50,
                'metadata' => [
                    'window_start' => $weekStart->toIso8601String(),
                    'window_end' => $weekEnd->toIso8601String(),
                    'task_sample_size' => $tasks->count(),
                ],
            ],
        );

        return $metric->fresh() ?? $metric;
    }

    /**
     * @return array<int, float>
     */
    private function extractFilesChangedCounts(Collection $tasks): array
    {
        return $tasks
            ->filter(fn (Task $task): bool => $task->status === TaskStatus::Completed)
            ->map(function (Task $task): ?float {
                $result = $task->result ?? [];
                $filesChanged = $result['files_changed'] ?? null;

                if (! is_array($filesChanged)) {
                    return null;
                }

                return (float) count($filesChanged);
            })
            ->filter(fn (?float $value): bool => $value !== null)
            ->values()
            ->all();
    }

    /**
     * @return array<int, float>
     */
    private function extractLeadTimes(Collection $tasks): array
    {
        return $tasks
            ->filter(fn (Task $task): bool => $task->status === TaskStatus::Completed)
            ->map(function (Task $task): ?float {
                if (! $task->created_at instanceof Carbon || ! $task->updated_at instanceof Carbon) {
                    return null;
                }

                $seconds = $task->updated_at->diffInSeconds($task->created_at, absolute: true);

                return round($seconds / 3600, 2);
            })
            ->filter(fn (?float $value): bool => $value !== null)
            ->values()
            ->all();
    }

    private function countReopenedRegressions(Collection $tasks): int
    {
        $failedTasks = $tasks->filter(fn (Task $task): bool => $task->status === TaskStatus::Failed && $task->mr_iid !== null);

        $count = 0;
        foreach ($failedTasks as $failedTask) {
            $hasFollowUpSuccess = $tasks->contains(function (Task $candidate) use ($failedTask): bool {
                if ($candidate->status !== TaskStatus::Completed) {
                    return false;
                }

                if ($candidate->mr_iid === null || $failedTask->mr_iid === null) {
                    return false;
                }

                return $candidate->mr_iid === $failedTask->mr_iid
                    && $candidate->type === $failedTask->type
                    && $candidate->created_at instanceof Carbon
                    && $failedTask->created_at instanceof Carbon
                    && $candidate->created_at->greaterThan($failedTask->created_at);
            });

            if ($hasFollowUpSuccess) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<int, float>  $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values, SORT_NUMERIC);
        $count = count($values);
        $mid = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$mid];
        }

        return round(($values[$mid - 1] + $values[$mid]) / 2, 2);
    }
}

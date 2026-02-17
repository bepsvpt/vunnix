<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardEfficiencyController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $projectIds = $user->projects()
            ->where('enabled', true)
            ->pluck('projects.id');

        // Time to first review — avg minutes from created_at to started_at for completed CodeReview tasks
        $completedReviews = Task::whereIn('project_id', $projectIds)
            ->where('type', TaskType::CodeReview)
            ->where('status', TaskStatus::Completed)
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->get(['created_at', 'started_at', 'completed_at']);

        $timeToFirstReview = null;
        $reviewTurnaround = null;

        if ($completedReviews->isNotEmpty()) {
            $timeToFirstReview = (float) round(
                ($completedReviews->avg(fn ($task) => abs($task->started_at?->diffInSeconds($task->created_at) ?? 0)) ?? 0) / 60,
                1
            );

            // Review turnaround — avg minutes from created_at to completed_at
            $reviewTurnaround = (float) round(
                ($completedReviews->avg(fn ($task) => abs($task->completed_at?->diffInSeconds($task->created_at) ?? 0)) ?? 0) / 60,
                1
            );
        }

        // Task completion rate by type — completed / (completed + failed) for each type
        $terminalTasks = Task::whereIn('project_id', $projectIds)
            ->whereIn('status', [TaskStatus::Completed, TaskStatus::Failed])
            ->get(['type', 'status']);

        $completionRateByType = [];
        foreach (TaskType::cases() as $type) {
            $ofType = $terminalTasks->where('type', $type);
            $total = $ofType->count();
            if ($total > 0) {
                $completed = $ofType->where('status', TaskStatus::Completed)->count();
                $completionRateByType[$type->value] = (float) round(($completed / $total) * 100, 1);
            }
        }

        return response()->json([
            'data' => [
                'time_to_first_review' => $timeToFirstReview,
                'review_turnaround' => $reviewTurnaround,
                'completion_rate_by_type' => $completionRateByType,
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardOverviewController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        $projectIds = $user->projects()
            ->where('enabled', true)
            ->pluck('projects.id');

        $tasksByType = Task::whereIn('project_id', $projectIds)
            ->select('type', DB::raw('count(*) as count'))
            ->groupBy('type')
            ->pluck('count', 'type');

        $activeTasks = Task::whereIn('project_id', $projectIds)
            ->whereIn('status', [TaskStatus::Queued, TaskStatus::Running])
            ->count();

        $completedCount = Task::whereIn('project_id', $projectIds)
            ->where('status', TaskStatus::Completed)
            ->count();

        $failedCount = Task::whereIn('project_id', $projectIds)
            ->where('status', TaskStatus::Failed)
            ->count();

        $total = $completedCount + $failedCount;
        $successRate = $total > 0 ? round(($completedCount / $total) * 100, 1) : null;

        $recentActivity = Task::whereIn('project_id', $projectIds)
            ->max('created_at');

        // Build tasks_by_type with all known display types, defaulting to 0
        $typeMap = [];
        foreach ([TaskType::CodeReview, TaskType::FeatureDev, TaskType::UiAdjustment, TaskType::PrdCreation] as $type) {
            $typeMap[$type->value] = (int) ($tasksByType[$type->value] ?? 0);
        }

        return response()->json([
            'data' => [
                'tasks_by_type' => $typeMap,
                'active_tasks' => $activeTasks,
                'success_rate' => $successRate,
                'total_completed' => $completedCount,
                'total_failed' => $failedCount,
                'recent_activity' => $recentActivity,
            ],
        ]);
    }
}

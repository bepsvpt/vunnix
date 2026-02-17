<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\FindingAcceptance;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExternalMetricsController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        $projectIds = $user->accessibleProjects()->pluck('id');

        $completedCount = Task::whereIn('project_id', $projectIds)
            ->where('status', TaskStatus::Completed)
            ->count();

        $failedCount = Task::whereIn('project_id', $projectIds)
            ->where('status', TaskStatus::Failed)
            ->count();

        $activeCount = Task::whereIn('project_id', $projectIds)
            ->whereIn('status', [TaskStatus::Queued, TaskStatus::Running])
            ->count();

        $totalTerminal = $completedCount + $failedCount;
        $successRate = $totalTerminal > 0
            ? round(($completedCount / $totalTerminal) * 100, 1)
            : null;

        $tasksByType = Task::whereIn('project_id', $projectIds)
            ->select('type', DB::raw('count(*) as count'))
            ->groupBy('type')
            ->pluck('count', 'type');

        $typeMap = [];
        foreach ([TaskType::CodeReview, TaskType::FeatureDev, TaskType::UiAdjustment, TaskType::PrdCreation] as $type) {
            $typeMap[$type->value] = (int) ($tasksByType[$type->value] ?? 0);
        }

        $totalCost = Task::whereIn('project_id', $projectIds)
            ->where('status', TaskStatus::Completed)
            ->sum('cost');

        // Acceptance rate from FindingAcceptance records
        $totalFindings = FindingAcceptance::whereIn('project_id', $projectIds)->count();
        $acceptedFindings = FindingAcceptance::whereIn('project_id', $projectIds)
            ->where('status', 'accepted')
            ->count();
        $acceptanceRate = $totalFindings > 0
            ? round(($acceptedFindings / $totalFindings) * 100, 1)
            : null;

        return response()->json([
            'data' => [
                'total_completed' => $completedCount,
                'total_failed' => $failedCount,
                'active_tasks' => $activeCount,
                'success_rate' => $successRate,
                'tasks_by_type' => $typeMap,
                'total_cost' => round((float) $totalCost, 6),
                'acceptance_rate' => $acceptanceRate,
            ],
        ]);
    }
}

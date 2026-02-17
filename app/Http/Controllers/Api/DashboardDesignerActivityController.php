<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskOrigin;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardDesignerActivityController extends Controller
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

        // UI adjustments dispatched — completed UiAdjustment tasks
        $completedUiTasks = Task::whereIn('project_id', $projectIds)
            ->where('type', TaskType::UiAdjustment)
            ->where('status', TaskStatus::Completed)
            ->get(['retry_count', 'origin', 'mr_iid']);

        $uiAdjustmentsDispatched = $completedUiTasks->count();

        // Avg iterations per adjustment — retry_count + 1 = total attempts
        $avgIterations = null;
        if ($uiAdjustmentsDispatched > 0) {
            $avgIterations = round(
                $completedUiTasks->avg(fn ($task): int => $task->retry_count + 1) ?? 0,
                1
            );
        }

        // MRs created from chat — conversation-originated UI tasks with an MR
        $mrsCreatedFromChat = $completedUiTasks
            ->where('origin', TaskOrigin::Conversation)
            ->whereNotNull('mr_iid')
            ->count();

        // First-attempt success rate — % of completed UI tasks with retry_count = 0
        $firstAttemptSuccessRate = null;
        if ($uiAdjustmentsDispatched > 0) {
            $firstAttemptCount = $completedUiTasks->where('retry_count', 0)->count();
            $firstAttemptSuccessRate = round(
                ($firstAttemptCount / $uiAdjustmentsDispatched) * 100,
                1
            );
        }

        return response()->json([
            'data' => [
                'ui_adjustments_dispatched' => $uiAdjustmentsDispatched,
                'avg_iterations' => $avgIterations,
                'mrs_created_from_chat' => $mrsCreatedFromChat,
                'first_attempt_success_rate' => $firstAttemptSuccessRate,
            ],
        ]);
    }
}

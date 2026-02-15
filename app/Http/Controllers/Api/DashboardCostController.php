<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardCostController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        // Admin-only: user must have admin.global_config on at least one project (D29)
        $hasAdmin = $user->projects()
            ->where('enabled', true)
            ->get()
            ->contains(fn ($project) => $user->hasPermission('admin.global_config', $project));

        if (! $hasAdmin) {
            abort(403, 'Cost data is restricted to administrators.');
        }

        $projectIds = $user->projects()
            ->where('enabled', true)
            ->pluck('projects.id');

        // Token usage by task type — sum tokens_used grouped by type
        $tokensByType = Task::whereIn('project_id', $projectIds)
            ->whereIn('status', [TaskStatus::Completed, TaskStatus::Failed])
            ->whereNotNull('tokens_used')
            ->select('type', DB::raw('SUM(tokens_used) as total_tokens'))
            ->groupBy('type')
            ->pluck('total_tokens', 'type')
            ->mapWithKeys(fn ($tokens, $type) => [$type => (int) $tokens])
            ->all();

        // Cost per task type — avg cost grouped by type
        $costPerType = Task::whereIn('project_id', $projectIds)
            ->where('status', TaskStatus::Completed)
            ->whereNotNull('cost')
            ->select('type', DB::raw('AVG(cost) as avg_cost'), DB::raw('SUM(cost) as total_cost'), DB::raw('COUNT(*) as task_count'))
            ->groupBy('type')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->type->value => [
                    'avg_cost' => (float) round($row->avg_cost, 6),
                    'total_cost' => (float) round($row->total_cost, 6),
                    'task_count' => (int) $row->task_count,
                ],
            ])
            ->all();

        // Cost per project — total cost grouped by project
        $costPerProject = Task::whereIn('project_id', $projectIds)
            ->where('status', TaskStatus::Completed)
            ->whereNotNull('cost')
            ->join('projects', 'tasks.project_id', '=', 'projects.id')
            ->select('projects.id as project_id', 'projects.name as project_name', DB::raw('SUM(tasks.cost) as total_cost'), DB::raw('COUNT(*) as task_count'))
            ->groupBy('projects.id', 'projects.name')
            ->get()
            ->map(fn ($row) => [
                'project_id' => (int) $row->project_id,
                'project_name' => $row->project_name,
                'total_cost' => (float) round($row->total_cost, 6),
                'task_count' => (int) $row->task_count,
            ])
            ->values()
            ->all();

        // Monthly trend — total cost and token usage per month (last 12 months)
        $driver = DB::connection()->getDriverName();
        $monthExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "TO_CHAR(created_at, 'YYYY-MM')";

        $monthlyTrend = Task::whereIn('project_id', $projectIds)
            ->whereIn('status', [TaskStatus::Completed, TaskStatus::Failed])
            ->where('created_at', '>=', now()->subMonths(12)->startOfMonth())
            ->select(
                DB::raw("{$monthExpr} as month"),
                DB::raw('SUM(cost) as total_cost'),
                DB::raw('SUM(tokens_used) as total_tokens'),
                DB::raw('COUNT(*) as task_count')
            )
            ->groupBy(DB::raw($monthExpr))
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => [
                'month' => $row->month,
                'total_cost' => (float) round($row->total_cost ?? 0, 6),
                'total_tokens' => (int) ($row->total_tokens ?? 0),
                'task_count' => (int) $row->task_count,
            ])
            ->values()
            ->all();

        // Total cost summary
        $totalCost = Task::whereIn('project_id', $projectIds)
            ->where('status', TaskStatus::Completed)
            ->sum('cost');

        $totalTokens = Task::whereIn('project_id', $projectIds)
            ->whereIn('status', [TaskStatus::Completed, TaskStatus::Failed])
            ->sum('tokens_used');

        return response()->json([
            'data' => [
                'total_cost' => (float) round($totalCost, 6),
                'total_tokens' => (int) $totalTokens,
                'token_usage_by_type' => $tokensByType,
                'cost_per_type' => $costPerType,
                'cost_per_project' => $costPerProject,
                'monthly_trend' => $monthlyTrend,
            ],
        ]);
    }
}

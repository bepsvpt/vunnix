<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskOrigin;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardAdoptionController extends Controller
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

        // AI-reviewed MR % — distinct MRs with completed code review / all distinct MRs with any task
        // Use concatenation for composite uniqueness (mr_iid + project_id) — works on both SQLite and PostgreSQL
        $reviewedMrCount = (int) Task::whereIn('project_id', $projectIds)
            ->where('type', TaskType::CodeReview)
            ->where('status', TaskStatus::Completed)
            ->whereNotNull('mr_iid')
            ->selectRaw("COUNT(DISTINCT mr_iid || '-' || project_id) as cnt")
            ->value('cnt');

        $totalMrCount = (int) Task::whereIn('project_id', $projectIds)
            ->whereNotNull('mr_iid')
            ->selectRaw("COUNT(DISTINCT mr_iid || '-' || project_id) as cnt")
            ->value('cnt');

        $aiReviewedMrPercent = $totalMrCount > 0
            ? (float) round(($reviewedMrCount / $totalMrCount) * 100, 1)
            : null;

        // Chat active users — distinct users with conversations in enabled projects
        $chatActiveUsers = (int) Conversation::whereIn('project_id', $projectIds)
            ->selectRaw('COUNT(DISTINCT user_id) as cnt')
            ->value('cnt');

        // Tasks by type over time — monthly breakdown (last 12 months)
        $driver = DB::connection()->getDriverName();
        $monthExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "TO_CHAR(created_at, 'YYYY-MM')";

        /** @var \Illuminate\Support\Collection<int, object{month: string, type: TaskType, count: int}> $taskResults */
        $taskResults = Task::whereIn('project_id', $projectIds)
            ->whereIn('status', [TaskStatus::Completed, TaskStatus::Failed])
            ->where('created_at', '>=', now()->subMonths(12)->startOfMonth())
            ->select(
                DB::raw("{$monthExpr} as month"),
                'type',
                DB::raw('COUNT(*) as count')
            )
            ->groupBy(DB::raw($monthExpr), 'type')
            ->orderBy('month')
            ->get();

        $tasksByTypeOverTime = $taskResults
            ->groupBy('month')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [
                $row->type->value => (int) $row->count,
            ])->all())
            ->all();

        // @ai mentions/week — webhook-originated tasks grouped by ISO week (last 12 weeks)
        $weekExpr = $driver === 'sqlite'
            ? "strftime('%Y-W%W', created_at)"
            : "TO_CHAR(created_at, 'IYYY-\"W\"IW')";

        /** @var \Illuminate\Support\Collection<int, object{week: string, count: int}> $mentionResults */
        $mentionResults = Task::whereIn('project_id', $projectIds)
            ->where('origin', TaskOrigin::Webhook)
            ->where('created_at', '>=', now()->subWeeks(12)->startOfWeek())
            ->select(
                DB::raw("{$weekExpr} as week"),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy(DB::raw($weekExpr))
            ->orderBy('week')
            ->get();

        $aiMentionsPerWeek = $mentionResults
            ->map(fn ($row) => [
                'week' => $row->week,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'ai_reviewed_mr_percent' => $aiReviewedMrPercent,
                'reviewed_mr_count' => $reviewedMrCount,
                'total_mr_count' => $totalMrCount,
                'chat_active_users' => $chatActiveUsers,
                'tasks_by_type_over_time' => $tasksByTypeOverTime,
                'ai_mentions_per_week' => $aiMentionsPerWeek,
            ],
        ]);
    }
}

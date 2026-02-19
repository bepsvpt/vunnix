<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Services\MetricsQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardQualityController extends Controller
{
    public function __construct(
        private readonly MetricsQueryService $metricsQuery,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'prompt_version' => ['nullable', 'string', 'max:255'],
        ]);

        $promptVersion = $request->input('prompt_version');

        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        $projectIds = $user->projects()
            ->where('enabled', true)
            ->get()
            ->filter(fn (Project $project): bool => $user->hasPermission('review.view', $project))
            ->pluck('id');

        if ($projectIds->isEmpty()) {
            abort(403, 'Dashboard view access required.');
        }

        // Try materialized view first for pre-aggregated severity data
        // Skip materialized view when prompt_version filter is active (can't filter pre-aggregated data)
        $byType = $this->metricsQuery->byType($projectIds);
        $reviewMetrics = $byType->where('task_type', 'code_review')->first();

        if ($promptVersion === null && $reviewMetrics !== null && (int) $reviewMetrics->task_count > 0) {
            $severityTotals = [
                'critical' => (int) $reviewMetrics->total_severity_critical,
                'major' => (int) $reviewMetrics->total_severity_high,
                'minor' => (int) $reviewMetrics->total_severity_medium,
            ];
            $totalFindings = (int) $reviewMetrics->total_findings;
            $totalReviews = (int) $reviewMetrics->task_count;
        } else {
            // Fallback: aggregate directly from tasks when no task_metrics data exists
            $reviewQuery = Task::whereIn('project_id', $projectIds)
                ->where('type', TaskType::CodeReview)
                ->where('status', TaskStatus::Completed)
                ->whereNotNull('result');

            if ($promptVersion !== null) {
                $reviewQuery->whereJsonContains('prompt_version->skill', $promptVersion);
            }

            $reviewTasks = $reviewQuery->get();

            $severityTotals = ['critical' => 0, 'major' => 0, 'minor' => 0];
            $totalFindings = 0;
            $totalReviews = $reviewTasks->count();

            foreach ($reviewTasks as $task) {
                $result = $task->result;
                if (! is_array($result)) {
                    continue;
                }
                $bySeverity = $result['summary']['findings_by_severity'] ?? [];
                $severityTotals['critical'] += (int) ($bySeverity['critical'] ?? 0);
                $severityTotals['major'] += (int) ($bySeverity['major'] ?? 0);
                $severityTotals['minor'] += (int) ($bySeverity['minor'] ?? 0);
                $totalFindings += (int) ($result['summary']['total_findings'] ?? 0);
            }
        }

        // Acceptance rate — requires T86 (acceptance tracking), not yet implemented.
        $acceptanceRate = null;

        $avgFindingsPerReview = $totalReviews > 0
            ? round($totalFindings / $totalReviews, 1)
            : null;

        return response()->json([
            'data' => [
                'acceptance_rate' => $acceptanceRate,
                'severity_distribution' => $severityTotals,
                'total_findings' => $totalFindings,
                'total_reviews' => $totalReviews,
                'avg_findings_per_review' => $avgFindingsPerReview,
            ],
        ]);
    }
}

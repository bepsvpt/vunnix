<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardQualityController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $projectIds = $request->user()
            ->projects()
            ->where('enabled', true)
            ->pluck('projects.id');

        // Only completed code review tasks have quality data
        $reviewTasks = Task::whereIn('project_id', $projectIds)
            ->where('type', TaskType::CodeReview)
            ->where('status', TaskStatus::Completed)
            ->whereNotNull('result')
            ->get();

        // Aggregate severity distribution from result JSONB
        $severityTotals = ['critical' => 0, 'major' => 0, 'minor' => 0];
        $totalFindings = 0;
        $totalReviews = $reviewTasks->count();

        foreach ($reviewTasks as $task) {
            $result = $task->result;

            $bySeverity = $result['summary']['findings_by_severity'] ?? [];
            $severityTotals['critical'] += (int) ($bySeverity['critical'] ?? 0);
            $severityTotals['major'] += (int) ($bySeverity['major'] ?? 0);
            $severityTotals['minor'] += (int) ($bySeverity['minor'] ?? 0);

            $totalFindings += (int) ($result['summary']['total_findings'] ?? 0);
        }

        // Acceptance rate — requires T86 (acceptance tracking), not yet implemented.
        // Return null until acceptance data is available.
        $acceptanceRate = null;

        // Suggestions per 1K LOC — computed as total findings / total reviews
        // (LOC tracking not yet available; show average findings per review instead)
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

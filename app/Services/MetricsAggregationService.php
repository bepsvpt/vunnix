<?php

namespace App\Services;

use App\Events\MetricsUpdated;
use App\Models\TaskMetric;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MetricsAggregationService
{
    private const MATERIALIZED_VIEWS = [
        'mv_metrics_by_project',
        'mv_metrics_by_type',
        'mv_metrics_by_period',
    ];

    /**
     * Refresh all materialized views and broadcast updates.
     *
     * @return array{views_refreshed: int, duration_ms: int}
     */
    public function aggregate(): array
    {
        $start = hrtime(true);

        $viewsRefreshed = $this->refreshViews();

        $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);

        $this->broadcastUpdates();

        Log::info('Metrics aggregation completed', [
            'views_refreshed' => $viewsRefreshed,
            'duration_ms' => $durationMs,
        ]);

        return [
            'views_refreshed' => $viewsRefreshed,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * Refresh PostgreSQL materialized views concurrently.
     * Returns the number of views refreshed (0 on non-PostgreSQL).
     */
    private function refreshViews(): int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return 0;
        }

        $refreshed = 0;

        foreach (self::MATERIALIZED_VIEWS as $view) {
            try {
                DB::statement("REFRESH MATERIALIZED VIEW CONCURRENTLY {$view}");
                $refreshed++;
            } catch (\Throwable $e) {
                Log::error("Failed to refresh materialized view: {$view}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $refreshed;
    }

    /**
     * Broadcast MetricsUpdated event for each project that has metrics.
     */
    private function broadcastUpdates(): void
    {
        $projectIds = TaskMetric::distinct()->pluck('project_id');

        foreach ($projectIds as $projectId) {
            MetricsUpdated::dispatch($projectId, [
                'type' => 'aggregation_complete',
            ]);
        }
    }
}

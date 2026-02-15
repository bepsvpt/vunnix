<?php

namespace App\Console\Commands;

use App\Services\MetricsAggregationService;
use Illuminate\Console\Command;

class AggregateMetrics extends Command
{
    protected $signature = 'metrics:aggregate';

    protected $description = 'Refresh materialized views for dashboard metrics aggregation';

    public function handle(MetricsAggregationService $service): int
    {
        $this->info('Starting metrics aggregation...');

        $result = $service->aggregate();

        $this->info("Metrics aggregation completed: {$result['views_refreshed']} views refreshed in {$result['duration_ms']}ms.");

        return self::SUCCESS;
    }
}

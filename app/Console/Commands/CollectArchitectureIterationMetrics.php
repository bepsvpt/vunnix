<?php

namespace App\Console\Commands;

use App\Services\Architecture\IterationMetricsCollector;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CollectArchitectureIterationMetrics extends Command
{
    /**
     * @var string
     */
    protected $signature = 'architecture:collect-iteration-metrics {--date= : Snapshot date (YYYY-MM-DD)}';

    /**
     * @var string
     */
    protected $description = 'Collect weekly architecture iteration metrics snapshot';

    public function handle(IterationMetricsCollector $collector): int
    {
        $dateOption = $this->option('date');
        $asOf = null;

        if (is_string($dateOption) && $dateOption !== '') {
            $asOf = Carbon::parse($dateOption);
        }

        $metric = $collector->collect($asOf);

        $this->info('Architecture iteration metrics collected.');
        $this->line('snapshot_date='.$metric->snapshot_date->toDateString());
        $this->line('module_touch_breadth='.$metric->module_touch_breadth);
        $this->line('median_files_changed='.($metric->median_files_changed ?? 'null'));
        $this->line('reopened_regressions_count='.$metric->reopened_regressions_count);
        $this->line('lead_time_hours_p50='.($metric->lead_time_hours_p50 ?? 'null'));

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\CostAlertService;
use Illuminate\Console\Command;

class EvaluateCostAlerts extends Command
{
    protected $signature = 'cost-alerts:evaluate';

    protected $description = 'Evaluate aggregate cost alert rules (monthly anomaly, daily spike, projection)';

    public function handle(CostAlertService $service): int
    {
        $alerts = $service->evaluateAll();

        if (count($alerts) > 0) {
            $this->info(count($alerts) . ' cost alert(s) created.');
        } else {
            $this->info('No cost alerts triggered.');
        }

        return self::SUCCESS;
    }
}

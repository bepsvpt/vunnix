<?php

namespace App\Console\Commands;

use App\Services\AlertEventService;
use App\Services\CostAlertService;
use Illuminate\Console\Command;
use Throwable;

class EvaluateCostAlerts extends Command
{
    protected $signature = 'cost-alerts:evaluate';

    protected $description = 'Evaluate aggregate cost alert rules (monthly anomaly, daily spike, projection)';

    public function handle(CostAlertService $service, AlertEventService $alertEventService): int
    {
        $alerts = $service->evaluateAll();

        if (count($alerts) > 0) {
            $this->info(count($alerts).' cost alert(s) created.');

            // Route cost alerts to team chat (T99)
            foreach ($alerts as $alert) {
                try {
                    $alertEventService->notifyCostAlert($alert);
                } catch (Throwable $e) {
                    $this->warn("Failed to send team chat for {$alert->rule}: {$e->getMessage()}");
                }
            }
        } else {
            $this->info('No cost alerts triggered.');
        }

        return self::SUCCESS;
    }
}

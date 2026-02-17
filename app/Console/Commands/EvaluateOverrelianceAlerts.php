<?php

namespace App\Console\Commands;

use App\Services\OverrelianceDetectionService;
use Illuminate\Console\Command;

class EvaluateOverrelianceAlerts extends Command
{
    protected $signature = 'overreliance:evaluate';

    protected $description = 'Evaluate over-reliance detection rules (acceptance rate, bulk resolution, reactions)';

    public function handle(OverrelianceDetectionService $service): int
    {
        $alerts = $service->evaluateAll();

        if (count($alerts) > 0) {
            $this->info(count($alerts).' over-reliance alert(s) created.');
        } else {
            $this->info('No over-reliance alerts triggered.');
        }

        return self::SUCCESS;
    }
}

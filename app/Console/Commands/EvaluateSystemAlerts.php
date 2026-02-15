<?php

namespace App\Console\Commands;

use App\Services\AlertEventService;
use Illuminate\Console\Command;

class EvaluateSystemAlerts extends Command
{
    protected $signature = 'system-alerts:evaluate';

    protected $description = 'Evaluate system alert rules (API outage, failure rate, queue depth, auth, disk)';

    public function handle(AlertEventService $service): int
    {
        $events = $service->evaluateAll();

        if (count($events) > 0) {
            foreach ($events as $event) {
                $this->info("{$event->alert_type}: {$event->status} — {$event->message}");
            }
        } else {
            $this->info('No system alert changes.');
        }

        return self::SUCCESS;
    }
}

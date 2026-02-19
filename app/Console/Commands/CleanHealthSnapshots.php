<?php

namespace App\Console\Commands;

use App\Models\HealthSnapshot;
use Illuminate\Console\Command;

class CleanHealthSnapshots extends Command
{
    protected $signature = 'health:clean-snapshots';

    protected $description = 'Delete expired health snapshots based on retention policy';

    public function handle(): int
    {
        $retentionDays = (int) config('health.snapshot_retention_days', 180);
        $cutoff = now()->subDays($retentionDays);

        $deleted = HealthSnapshot::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Deleted {$deleted} health snapshot".($deleted === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}

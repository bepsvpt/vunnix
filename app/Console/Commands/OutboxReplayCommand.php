<?php

namespace App\Console\Commands;

use App\Jobs\DeliverOutboxEvents;
use App\Models\InternalOutboxEvent;
use Illuminate\Console\Command;

class OutboxReplayCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'outbox:replay
        {--event-id= : Replay a specific outbox row ID}
        {--failed-only : Replay only failed events}';

    /**
     * @var string
     */
    protected $description = 'Reset outbox events to pending and trigger delivery';

    public function handle(): int
    {
        $query = InternalOutboxEvent::query();

        $eventId = $this->option('event-id');
        if (is_string($eventId) && $eventId !== '') {
            $query->whereKey((int) $eventId);
        } elseif ($this->option('failed-only')) {
            $query->where('status', 'failed');
        }

        $updated = $query->update([
            'status' => 'pending',
            'available_at' => now(),
            'failed_at' => null,
        ]);

        $this->info("Queued {$updated} outbox event(s) for replay.");

        DeliverOutboxEvents::dispatch();

        return self::SUCCESS;
    }
}

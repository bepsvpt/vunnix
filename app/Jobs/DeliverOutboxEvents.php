<?php

namespace App\Jobs;

use App\Models\InternalOutboxEvent;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliverOutboxEvents implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $batchSize = 50,
    ) {
        $this->queue = QueueNames::SERVER;
    }

    public function handle(): void
    {
        $now = now();

        /** @var Collection<int, InternalOutboxEvent> $events */
        $events = InternalOutboxEvent::query()
            ->where('status', 'pending')
            ->where(function ($query) use ($now): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', $now);
            })
            ->orderBy('id')
            ->limit($this->batchSize)
            ->get();

        foreach ($events as $event) {
            $this->deliverEvent($event->id);
        }
    }

    private function deliverEvent(int $id): void
    {
        /** @var InternalOutboxEvent|null $event */
        $event = DB::transaction(function () use ($id): ?InternalOutboxEvent {
            /** @var InternalOutboxEvent|null $locked */
            $locked = InternalOutboxEvent::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof InternalOutboxEvent || $locked->status !== 'pending') {
                return null;
            }

            $locked->status = 'processing';
            $locked->attempts++;
            $locked->save();

            return $locked;
        });

        if (! $event instanceof InternalOutboxEvent) {
            return;
        }

        try {
            // Placeholder delivery hook: currently records delivery lifecycle and logs payload.
            // Concrete downstream consumers will subscribe by event_type in subsequent phases.
            Log::info('DeliverOutboxEvents: delivered outbox event', [
                'outbox_id' => $event->id,
                'event_id' => $event->event_id,
                'event_type' => $event->event_type,
            ]);

            DB::transaction(function () use ($event): void {
                /** @var InternalOutboxEvent|null $locked */
                $locked = InternalOutboxEvent::query()
                    ->whereKey($event->id)
                    ->lockForUpdate()
                    ->first();

                if ($locked instanceof InternalOutboxEvent && $locked->status === 'processing') {
                    $locked->markDispatched();
                }
            });
        } catch (Throwable $e) {
            DB::transaction(function () use ($event, $e): void {
                /** @var InternalOutboxEvent|null $locked */
                $locked = InternalOutboxEvent::query()
                    ->whereKey($event->id)
                    ->lockForUpdate()
                    ->first();

                if ($locked instanceof InternalOutboxEvent) {
                    $locked->markPendingRetry(
                        availableAt: now()->addMinute(),
                        error: $e->getMessage(),
                    );
                }
            });
        }
    }
}

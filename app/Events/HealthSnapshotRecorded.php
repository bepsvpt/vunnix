<?php

namespace App\Events;

use App\Models\HealthSnapshot;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HealthSnapshotRecorded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly HealthSnapshot $snapshot,
        public readonly string $trendDirection,
    ) {}

    /**
     * @return array<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("project.{$this->snapshot->project_id}.health"),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'project_id' => $this->snapshot->project_id,
            'dimension' => $this->snapshot->dimension,
            'score' => $this->snapshot->score,
            'trend_direction' => $this->trendDirection,
            'created_at' => $this->snapshot->created_at?->toIso8601String(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'health.snapshot.recorded';
    }

    public function broadcastQueue(): string
    {
        return 'vunnix-server';
    }
}

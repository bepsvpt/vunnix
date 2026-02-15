<?php

namespace App\Events;

use App\Models\Task;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Task $task,
    ) {}

    /**
     * @return array<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("task.{$this->task->id}"),
            new PrivateChannel("project.{$this->task->project_id}.activity"),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'task_id' => $this->task->id,
            'status' => $this->task->status->value,
            'type' => $this->task->type->value,
            'project_id' => $this->task->project_id,
            'pipeline_id' => $this->task->pipeline_id,
            'pipeline_status' => $this->task->pipeline_status,
            'mr_iid' => $this->task->mr_iid,
            'issue_iid' => $this->task->issue_iid,
            'title' => $this->task->result['title'] ?? null,
            'started_at' => $this->task->started_at?->toIso8601String(),
            'conversation_id' => $this->task->conversation_id,
            'result_summary' => $this->task->isTerminal() ? ($this->task->result['summary'] ?? null) : null,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'task.status.changed';
    }
}

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
        $payload = [
            'task_id' => $this->task->id,
            'status' => $this->task->status->value,
            'type' => $this->task->type->value,
            'project_id' => $this->task->project_id,
            'pipeline_id' => $this->task->pipeline_id,
            'pipeline_status' => $this->task->pipeline_status,
            'mr_iid' => $this->task->mr_iid,
            'issue_iid' => $this->task->issue_iid,
            'title' => $this->task->result['title'] ?? $this->task->result['mr_title'] ?? null,
            'started_at' => $this->task->started_at?->toIso8601String(),
            'conversation_id' => $this->task->conversation_id,
            'result_summary' => $this->task->isTerminal() ? ($this->task->result['summary'] ?? $this->task->result['notes'] ?? null) : null,
            'error_reason' => $this->task->isTerminal() ? $this->task->error_reason : null,
            'timestamp' => now()->toIso8601String(),
        ];

        // Add structured result data for terminal tasks (used by ResultCard)
        if ($this->task->isTerminal() && $this->task->result !== null) {
            $payload['result_data'] = $this->buildResultData();
        }

        return $payload;
    }

    public function broadcastAs(): string
    {
        return 'task.status.changed';
    }

    /**
     * Build result card data from the task result, based on task type.
     */
    private function buildResultData(): array
    {
        $result = $this->task->result;
        $data = [];

        if (isset($result['branch'])) {
            $data['branch'] = $result['branch'];
        }
        $data['target_branch'] = $result['target_branch'] ?? 'main';
        if (isset($result['files_changed'])) {
            $data['files_changed'] = $result['files_changed'];
        }

        // UI adjustment: include screenshot
        if ($this->task->type === \App\Enums\TaskType::UiAdjustment) {
            $data['screenshot'] = $result['screenshot'] ?? null;
        }

        return $data;
    }
}

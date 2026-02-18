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
        $result = $this->task->result ?? [];

        $payload = [
            'task_id' => $this->task->id,
            'status' => $this->task->status->value,
            'type' => $this->task->type->value,
            'project_id' => $this->task->project_id,
            'pipeline_id' => $this->task->pipeline_id,
            'pipeline_status' => $this->task->pipeline_status,
            'mr_iid' => $this->task->mr_iid,
            'issue_iid' => $this->task->issue_iid,
            'title' => $result['title'] ?? $result['mr_title'] ?? null,
            'started_at' => $this->task->started_at?->toIso8601String(),
            'conversation_id' => $this->task->conversation_id,
            'gitlab_url' => $this->task->project->gitlabWebUrl(),
            'result_summary' => $this->task->isTerminal() ? $this->buildResultSummary($result) : null,
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
     * Route the broadcast to the vunnix-server queue.
     *
     * Without this, broadcasts go to the 'default' queue which has no worker
     * in Docker (D134 queue topology uses vunnix-server and vunnix-runner-*).
     */
    public function broadcastQueue(): string
    {
        return 'vunnix-server';
    }

    /**
     * Build a short human-readable summary of the task result.
     *
     * Falls back through type-specific fields so each task type surfaces
     * something useful in the result card without truncating large fields.
     *
     * @param  array<string, mixed>  $result
     */
    private function buildResultSummary(array $result): ?string
    {
        // Preferred summary/notes fields (feature_dev, code_review, etc.)
        $text = $result['summary'] ?? $result['notes'] ?? null;
        if ($text !== null && $text !== '') {
            return $text;
        }

        // Issue discussion: use the response text (truncated)
        if (isset($result['response']) && $result['response'] !== '') {
            return mb_substr($result['response'], 0, 500);
        }

        // Deep analysis: use the first 500 chars of the analysis text
        if (isset($result['analysis']) && $result['analysis'] !== '') {
            return mb_substr($result['analysis'], 0, 500);
        }

        return null;
    }

    /**
     * Build result card data from the task result, based on task type.
     *
     * @return array<string, mixed>
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

        // FeatureDev / UiAdjustment: include implementation notes
        if (isset($result['notes'])) {
            $data['notes'] = $result['notes'];
        }

        // UI adjustment: include screenshot
        if ($this->task->type === \App\Enums\TaskType::UiAdjustment) {
            $data['screenshot'] = $result['screenshot'] ?? null;
        }

        // Deep analysis: include key findings and references.
        // The full analysis text is intentionally excluded — it can be 10–50KB+
        // and would exceed the Reverb max_message_size limit. The frontend
        // fetches the full result from the API when hydrating result cards.
        if ($this->task->type === \App\Enums\TaskType::DeepAnalysis) {
            if (isset($result['key_findings'])) {
                $data['key_findings'] = $result['key_findings'];
            }
            if (isset($result['references'])) {
                $data['references'] = $result['references'];
            }
        }

        // Issue discussion: include response and references
        if ($this->task->type === \App\Enums\TaskType::IssueDiscussion) {
            if (isset($result['response'])) {
                $data['response'] = $result['response'];
            }
            if (isset($result['references'])) {
                $data['references'] = $result['references'];
            }
        }

        // PRD creation: include created issue details
        if ($this->task->type === \App\Enums\TaskType::PrdCreation && isset($result['gitlab_issue_url'])) {
            $data['gitlab_issue_url'] = $result['gitlab_issue_url'];
        }

        return $data;
    }
}

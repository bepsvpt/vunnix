<?php

namespace App\Jobs;

use App\Models\Task;
use App\Services\GitLabClient;
use App\Services\LabelMapper;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Apply labels and set commit status (Layer 3) on a GitLab merge request.
 *
 * Adds ai::reviewed, ai::risk-{high|medium|low}, and optionally ai::security
 * labels. Sets commit status to 'success' or 'failed' based on whether any
 * critical findings are present.
 *
 * @see §4.5 3-Layer Comment Pattern — Layer 3
 * @see §4.6 Severity Classification & Labels
 */
class PostLabelsAndStatus implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $taskId,
    ) {
        $this->queue = QueueNames::SERVER;
    }

    public function handle(GitLabClient $gitLab): void
    {
        $task = Task::with('project')->find($this->taskId);

        if ($task === null) {
            Log::warning('PostLabelsAndStatus: task not found', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->mr_iid === null) {
            Log::info('PostLabelsAndStatus: task has no MR, skipping', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->result === null) {
            Log::info('PostLabelsAndStatus: task has no result, skipping', ['task_id' => $this->taskId]);

            return;
        }

        $mapper = new LabelMapper();
        $labels = $mapper->mapLabels($task->result);
        $commitStatus = $mapper->mapCommitStatus($task->result);

        $projectId = $task->project->gitlab_project_id;

        // Fetch MR to get the head commit SHA for commit status
        try {
            $mr = $gitLab->getMergeRequest($projectId, $task->mr_iid);
        } catch (\Throwable $e) {
            Log::warning('PostLabelsAndStatus: failed to fetch MR', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // Apply labels (additive — preserves existing non-AI labels)
        try {
            $gitLab->addMergeRequestLabels($projectId, $task->mr_iid, $labels);

            Log::info('PostLabelsAndStatus: labels applied', [
                'task_id' => $this->taskId,
                'labels' => $labels,
            ]);
        } catch (\Throwable $e) {
            Log::warning('PostLabelsAndStatus: failed to apply labels', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // Set commit status on the MR head SHA
        $commitSha = $mr['sha'] ?? '';

        try {
            $gitLab->setCommitStatus($projectId, $commitSha, $commitStatus, [
                'name' => 'vunnix-code-review',
                'description' => 'Vunnix AI Code Review',
            ]);

            Log::info('PostLabelsAndStatus: commit status set', [
                'task_id' => $this->taskId,
                'sha' => $commitSha,
                'status' => $commitStatus,
            ]);
        } catch (\Throwable $e) {
            Log::warning('PostLabelsAndStatus: failed to set commit status', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

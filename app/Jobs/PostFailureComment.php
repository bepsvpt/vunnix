<?php

namespace App\Jobs;

use App\Models\Task;
use App\Services\GitLabClient;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostFailureComment implements ShouldQueue
{
    use Queueable;

    private const REASON_MESSAGES = [
        'max_retries_exceeded' => 'The service encountered repeated errors and could not complete after multiple retries.',
        'invalid_request' => 'The request was invalid and could not be processed.',
        'context_exceeded' => 'The merge request may be too large for analysis. Consider splitting it into smaller MRs.',
        'scheduling_timeout' => 'The task could not be scheduled for execution within the time limit.',
        'expired' => 'The task expired while waiting in the queue due to service unavailability. Push a new commit to trigger a fresh review.',
        'pipeline_trigger_failed' => 'Failed to trigger the CI pipeline for execution.',
        'missing_trigger_token' => 'The CI trigger token is not configured for this project.',
    ];

    public int $tries = 1;

    public function __construct(
        public readonly int $taskId,
        public readonly string $failureReason,
        public readonly string $errorDetails,
    ) {
        $this->queue = QueueNames::SERVER;
    }

    public function handle(GitLabClient $gitLab): void
    {
        $task = Task::with('project')->find($this->taskId);

        if ($task === null) {
            Log::warning('PostFailureComment: task not found', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->mr_iid === null && $task->issue_iid === null) {
            Log::info('PostFailureComment: task has no MR or Issue, skipping', ['task_id' => $this->taskId]);

            return;
        }

        $body = $this->formatFailureComment();

        try {
            if ($task->mr_iid !== null) {
                $this->postToMergeRequest($gitLab, $task, $body);
            } else {
                $this->postToIssue($gitLab, $task, $body);
            }
        } catch (Throwable $e) {
            // Best-effort: log but don't re-throw.
            // The task is already in DLQ — we don't want the failure comment
            // itself to enter a retry loop.
            Log::warning('PostFailureComment: failed to post comment', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function postToMergeRequest(GitLabClient $gitLab, Task $task, string $body): void
    {
        $projectId = $task->project->gitlab_project_id;

        if ($task->comment_id !== null) {
            // Update placeholder in-place
            $gitLab->updateMergeRequestNote($projectId, $task->mr_iid, $task->comment_id, $body);

            Log::info('PostFailureComment: updated placeholder with failure', [
                'task_id' => $this->taskId,
                'note_id' => $task->comment_id,
            ]);
        } else {
            $note = $gitLab->createMergeRequestNote($projectId, $task->mr_iid, $body);

            Log::info('PostFailureComment: posted failure comment on MR', [
                'task_id' => $this->taskId,
                'note_id' => $note['id'],
            ]);
        }
    }

    private function postToIssue(GitLabClient $gitLab, Task $task, string $body): void
    {
        $projectId = $task->project->gitlab_project_id;

        $gitLab->createIssueNote($projectId, $task->issue_iid, $body);

        Log::info('PostFailureComment: posted failure comment on Issue', [
            'task_id' => $this->taskId,
            'issue_iid' => $task->issue_iid,
        ]);
    }

    private function formatFailureComment(): string
    {
        $reason = self::REASON_MESSAGES[$this->failureReason]
            ?? "An unexpected error occurred ({$this->failureReason}).";

        return "🤖 AI review failed — {$reason}\n\n"
            ."<details>\n<summary>Error details</summary>\n\n"
            ."```\n{$this->errorDetails}\n```\n\n"
            .'</details>';
    }
}

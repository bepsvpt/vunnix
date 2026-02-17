<?php

namespace App\Jobs;

use App\Jobs\Middleware\RetryWithBackoff;
use App\Models\Task;
use App\Services\GitLabClient;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Create a GitLab Issue for a PrdCreation task (server-side execution).
 *
 * For create-Issue actions dispatched from conversation, this job bypasses
 * the CI pipeline entirely. It calls GitLabClient::createIssue() directly
 * using the bot PAT, sets the PM as assignee, and stores the Issue IID
 * on the task record for result card display.
 *
 * @see T56: Server-side execution mode
 * @see §3.4 — Execution mode routing (D123)
 * @see §4.3 — Action Dispatch UX
 */
class CreateGitLabIssue implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(
        public readonly int $taskId,
    ) {
        $this->queue = QueueNames::SERVER;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RetryWithBackoff];
    }

    public function handle(GitLabClient $gitLab): void
    {
        $task = Task::with('project')->find($this->taskId);

        if ($task === null) {
            Log::warning('CreateGitLabIssue: task not found', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->result === null) {
            Log::info('CreateGitLabIssue: task has no result, skipping', ['task_id' => $this->taskId]);

            return;
        }

        $result = $task->result;
        $gitlabProjectId = $task->project->gitlab_project_id;

        // Build the Issue creation payload
        $issueData = [
            'title' => $result['title'] ?? 'Untitled Issue',
            'description' => $result['description'] ?? '',
        ];

        // Set PM as assignee if provided
        $assigneeId = $result['assignee_id'] ?? null;
        if ($assigneeId !== null && $assigneeId > 0) {
            $issueData['assignee_ids'] = [(int) $assigneeId];
        }

        // Apply labels if provided
        $labels = $result['labels'] ?? null;
        if (is_array($labels) && $labels !== []) {
            $issueData['labels'] = implode(',', $labels);
        }

        try {
            $issue = $gitLab->createIssue($gitlabProjectId, $issueData);

            $issueIid = (int) $issue['iid'];

            $task->issue_iid = $issueIid;
            $task->result = array_merge($task->result, [
                'gitlab_issue_url' => $issue['web_url'] ?? null,
            ]);
            $task->save();

            Log::info('CreateGitLabIssue: issue created', [
                'task_id' => $this->taskId,
                'issue_iid' => $issueIid,
                'project_id' => $gitlabProjectId,
            ]);
        } catch (Throwable $e) {
            Log::warning('CreateGitLabIssue: failed to create issue', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

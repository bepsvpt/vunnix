<?php

namespace App\Jobs;

use App\Jobs\Middleware\RetryWithBackoff;
use App\Models\Task;
use App\Services\GitLabClient;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Post an issue discussion response on a GitLab Issue.
 *
 * The executor returns a JSON response with a "response" field containing
 * the markdown-formatted answer. This job posts that response as an Issue
 * note via the bot account.
 *
 * @see T43: Issue discussion — @ai on Issue
 */
class PostIssueComment implements ShouldQueue
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
            Log::warning('PostIssueComment: task not found', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->issue_iid === null) {
            Log::info('PostIssueComment: task has no Issue, skipping', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->result === null) {
            Log::info('PostIssueComment: task has no result, skipping', ['task_id' => $this->taskId]);

            return;
        }

        $markdown = $this->formatResponse($task->result);

        try {
            $note = $gitLab->createIssueNote(
                $task->project->gitlab_project_id,
                $task->issue_iid,
                $markdown,
            );

            $task->comment_id = $note['id'];
            $task->save();

            Log::info('PostIssueComment: posted issue comment', [
                'task_id' => $this->taskId,
                'issue_iid' => $task->issue_iid,
                'note_id' => $note['id'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('PostIssueComment: failed to post comment', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Format the executor's response as a markdown Issue comment.
     *
     * The executor returns a JSON object with a "response" field containing
     * pre-formatted markdown. We wrap it with a bot header.
     */
    private function formatResponse(array $result): string
    {
        $response = $result['response'] ?? 'No response available.';

        return "### 🤖 AI Response\n\n" . $response;
    }
}

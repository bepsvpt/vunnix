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
 * Post a placeholder comment on a GitLab merge request when a task is dispatched.
 *
 * Immediately posts "🤖 AI Review in progress…" so the MR author knows
 * Vunnix is working. The returned note ID is stored on the task's comment_id
 * so PostSummaryComment can later update it in-place with the full review.
 *
 * @see §4.5 Placeholder-then-update pattern (T36)
 */
class PostPlaceholderComment implements ShouldQueue
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
            Log::warning('PostPlaceholderComment: task not found', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->mr_iid === null) {
            Log::info('PostPlaceholderComment: task has no MR, skipping', ['task_id' => $this->taskId]);

            return;
        }

        // Don't overwrite an existing comment_id (e.g., from a previous placeholder)
        if ($task->comment_id !== null) {
            Log::info('PostPlaceholderComment: task already has comment_id, skipping', [
                'task_id' => $this->taskId,
                'comment_id' => $task->comment_id,
            ]);

            return;
        }

        try {
            $note = $gitLab->createMergeRequestNote(
                $task->project->gitlab_project_id,
                $task->mr_iid,
                '🤖 AI Review in progress…',
            );

            $task->comment_id = $note['id'];
            $task->save();

            Log::info('PostPlaceholderComment: posted', [
                'task_id' => $this->taskId,
                'note_id' => $note['id'],
            ]);
        } catch (\Throwable $e) {
            // Best-effort: log the failure but don't re-throw.
            // The review should still proceed even if the placeholder fails.
            Log::warning('PostPlaceholderComment: failed to post placeholder', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

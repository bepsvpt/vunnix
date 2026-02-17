<?php

namespace App\Jobs;

use App\Enums\TaskType;
use App\Jobs\Middleware\RetryWithBackoff;
use App\Models\Task;
use App\Services\GitLabClient;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

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

        // T40: Check for a previous completed review on the same MR
        // to reuse its summary comment (update in-place instead of creating new)
        $previousCommentId = $this->findPreviousCommentId($task);

        if ($previousCommentId !== null) {
            try {
                $gitLab->updateMergeRequestNote(
                    $task->project->gitlab_project_id,
                    $task->mr_iid,
                    $previousCommentId,
                    '🤖 AI Review in progress… (re-reviewing after new commits)',
                );

                $task->comment_id = $previousCommentId;
                $task->save();

                Log::info('PostPlaceholderComment: reusing previous review comment (T40)', [
                    'task_id' => $this->taskId,
                    'previous_comment_id' => $previousCommentId,
                ]);

                return;
            } catch (Throwable $e) {
                Log::warning('PostPlaceholderComment: failed to update previous comment, creating new', [
                    'task_id' => $this->taskId,
                    'error' => $e->getMessage(),
                ]);
                // Fall through to create a new comment
            }
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
        } catch (Throwable $e) {
            // Best-effort: log the failure but don't re-throw.
            // The review should still proceed even if the placeholder fails.
            Log::warning('PostPlaceholderComment: failed to post placeholder', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Find the comment_id from the most recent review on the same MR.
     *
     * Reuses comments from failed/superseded tasks too - we want to update
     * the same comment instead of cluttering the MR with multiple placeholders.
     */
    private function findPreviousCommentId(Task $task): ?int
    {
        return Task::where('project_id', $task->project_id)
            ->where('mr_iid', $task->mr_iid)
            ->where('type', TaskType::CodeReview)
            ->where('id', '<', $task->id)  // Only earlier tasks
            ->whereNotNull('comment_id')
            ->orderByDesc('id')  // Most recent task by ID
            ->value('comment_id');
    }
}

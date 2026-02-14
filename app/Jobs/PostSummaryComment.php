<?php

namespace App\Jobs;

use App\Jobs\Middleware\RetryWithBackoff;
use App\Models\Task;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Services\GitLabClient;
use App\Services\SummaryCommentFormatter;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Post a summary comment (Layer 1) on a GitLab merge request.
 *
 * Formats the validated code review result as markdown and posts it
 * as an MR-level note via the bot account. Stores the returned note ID
 * on the task's comment_id for the placeholder-then-update pattern (T36).
 *
 * @see §4.5 3-Layer Comment Pattern — Layer 1
 */
class PostSummaryComment implements ShouldQueue
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
            Log::warning('PostSummaryComment: task not found', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->mr_iid === null) {
            Log::info('PostSummaryComment: task has no MR, skipping', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->result === null) {
            Log::info('PostSummaryComment: task has no result, skipping', ['task_id' => $this->taskId]);

            return;
        }

        $formatter = new SummaryCommentFormatter();

        // T40: Detect incremental review — if this task's comment_id was inherited
        // from a previous task, include an "Updated" timestamp in the summary.
        $updatedAt = $this->isIncrementalReview($task) ? now() : null;
        $markdown = $formatter->format($task->result, $updatedAt);

        try {
            if ($task->comment_id !== null) {
                // T36: Update the placeholder comment in-place
                $gitLab->updateMergeRequestNote(
                    $task->project->gitlab_project_id,
                    $task->mr_iid,
                    $task->comment_id,
                    $markdown,
                );

                Log::info('PostSummaryComment: updated placeholder in-place', [
                    'task_id' => $this->taskId,
                    'note_id' => $task->comment_id,
                ]);
            } else {
                // Fallback: create a new comment (no placeholder was posted)
                $note = $gitLab->createMergeRequestNote(
                    $task->project->gitlab_project_id,
                    $task->mr_iid,
                    $markdown,
                );

                $task->comment_id = $note['id'];
                $task->save();

                Log::info('PostSummaryComment: posted new comment', [
                    'task_id' => $this->taskId,
                    'note_id' => $note['id'],
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('PostSummaryComment: failed to post comment', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Check if this task is an incremental review (reusing a previous task's comment).
     *
     * An incremental review is detected when a completed CodeReview task for the
     * same MR exists with the same comment_id — meaning we inherited the comment.
     */
    private function isIncrementalReview(Task $task): bool
    {
        if ($task->comment_id === null) {
            return false;
        }

        return Task::where('project_id', $task->project_id)
            ->where('mr_iid', $task->mr_iid)
            ->where('type', TaskType::CodeReview)
            ->where('status', TaskStatus::Completed)
            ->where('id', '!=', $task->id)
            ->where('comment_id', $task->comment_id)
            ->exists();
    }
}

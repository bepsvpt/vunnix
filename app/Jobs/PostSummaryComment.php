<?php

namespace App\Jobs;

use App\Models\Task;
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

    public function __construct(
        public readonly int $taskId,
    ) {
        $this->queue = QueueNames::SERVER;
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
        $markdown = $formatter->format($task->result);

        try {
            $note = $gitLab->createMergeRequestNote(
                $task->project->gitlab_project_id,
                $task->mr_iid,
                $markdown,
            );

            $task->comment_id = $note['id'];
            $task->save();

            Log::info('PostSummaryComment: posted', [
                'task_id' => $this->taskId,
                'note_id' => $note['id'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('PostSummaryComment: failed to post comment', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

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
 * Post an answer comment on a GitLab merge request for @ai ask commands.
 *
 * The executor returns a free-form text answer (no structured schema).
 * This job formats the answer with the original question and posts it
 * as an MR note via the bot account.
 *
 * @see T42: @ai improve + @ai ask commands
 */
class PostAnswerComment implements ShouldQueue
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
            Log::warning('PostAnswerComment: task not found', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->mr_iid === null) {
            Log::info('PostAnswerComment: task has no MR, skipping', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->result === null) {
            Log::info('PostAnswerComment: task has no result, skipping', ['task_id' => $this->taskId]);

            return;
        }

        $markdown = $this->formatAnswer($task->result);

        try {
            $note = $gitLab->createMergeRequestNote(
                $task->project->gitlab_project_id,
                $task->mr_iid,
                $markdown,
            );

            $task->comment_id = $note['id'];
            $task->save();

            Log::info('PostAnswerComment: posted answer comment', [
                'task_id' => $this->taskId,
                'note_id' => $note['id'],
            ]);
        } catch (Throwable $e) {
            Log::warning('PostAnswerComment: failed to post comment', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Format the answer as a markdown comment with the original question.
     */
    private function formatAnswer(array $result): string
    {
        $question = $result['question'] ?? 'Unknown question';
        $answer = $result['answer'] ?? 'No answer available.';

        return "### 🤖 Answer\n\n"
            ."> {$question}\n\n"
            .$answer;
    }
}

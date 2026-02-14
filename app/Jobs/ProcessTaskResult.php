<?php

namespace App\Jobs;

use App\Enums\TaskType;
use App\Jobs\Middleware\RetryWithBackoff;
use App\Models\Task;
use App\Services\ResultProcessor;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Process a completed task result through the Result Processor.
 *
 * Dispatched by TaskResultController after storing the raw result.
 * Validates the result against the task type's schema and transitions
 * the task to Completed or Failed.
 *
 * Runs on the server queue since it's I/O-bound (schema validation +
 * GitLab API calls in downstream layers).
 */
class ProcessTaskResult implements ShouldQueue
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

    public function handle(ResultProcessor $processor): void
    {
        $task = Task::find($this->taskId);

        if ($task === null) {
            Log::warning('ProcessTaskResult: task not found', ['task_id' => $this->taskId]);

            return;
        }

        // Only process tasks that are still in Running state
        // (another process may have superseded or failed it)
        if ($task->status !== \App\Enums\TaskStatus::Running) {
            Log::info('ProcessTaskResult: task no longer running, skipping', [
                'task_id' => $this->taskId,
                'status' => $task->status->value,
            ]);

            return;
        }

        $result = $processor->process($task);

        if (! $result['success']) {
            Log::warning('ProcessTaskResult: processing failed', [
                'task_id' => $this->taskId,
                'errors' => $result['errors'],
            ]);

            return;
        }

        if ($this->shouldPostSummaryComment($task)) {
            PostSummaryComment::dispatch($task->id);
        }

        if ($this->shouldPostInlineThreads($task)) {
            PostInlineThreads::dispatch($task->id);
        }

        if ($this->shouldPostLabelsAndStatus($task)) {
            PostLabelsAndStatus::dispatch($task->id);
        }
    }

    private function shouldPostSummaryComment(Task $task): bool
    {
        return $task->mr_iid !== null
            && in_array($task->type, [TaskType::CodeReview, TaskType::SecurityAudit], true);
    }

    private function shouldPostInlineThreads(Task $task): bool
    {
        return $task->mr_iid !== null
            && in_array($task->type, [TaskType::CodeReview, TaskType::SecurityAudit], true);
    }

    private function shouldPostLabelsAndStatus(Task $task): bool
    {
        return $task->mr_iid !== null
            && in_array($task->type, [TaskType::CodeReview, TaskType::SecurityAudit], true);
    }
}

<?php

namespace App\Jobs;

use App\Enums\TaskType;
use App\Exceptions\GitLabApiException;
use App\Jobs\Middleware\RetryWithBackoff;
use App\Models\Task;
use App\Services\FailureHandler;
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

        // T42: Post answer comment for @ai ask commands
        if ($this->shouldPostAnswerComment($task)) {
            PostAnswerComment::dispatch($task->id);
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

    /**
     * T42: Post an answer comment for @ai ask commands.
     *
     * Only fires for IssueDiscussion tasks with an MR (ask_command on MR note)
     * and an ask_command intent in the result metadata.
     */
    private function shouldPostAnswerComment(Task $task): bool
    {
        return $task->mr_iid !== null
            && $task->type === TaskType::IssueDiscussion
            && ($task->result['intent'] ?? null) === 'ask_command';
    }

    /**
     * Handle permanent job failure.
     *
     * Called by Laravel when $job->fail() is invoked (by RetryWithBackoff middleware
     * after max retries, or on non-retryable errors).
     */
    public function failed(?\Throwable $exception): void
    {
        $task = Task::find($this->taskId);

        if ($task === null) {
            Log::warning('ProcessTaskResult::failed: task not found', ['task_id' => $this->taskId]);

            return;
        }

        $failureReason = 'max_retries_exceeded';
        $errorDetails = $exception?->getMessage() ?? 'Unknown error';

        if ($exception instanceof GitLabApiException) {
            $errorDetails = "HTTP {$exception->statusCode}: {$exception->responseBody}";

            if ($exception->isInvalidRequest()) {
                $failureReason = 'invalid_request';
            }
        }

        app(FailureHandler::class)->handlePermanentFailure(
            task: $task,
            failureReason: $failureReason,
            errorDetails: $errorDetails,
            attempts: [],
        );
    }
}

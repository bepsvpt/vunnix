<?php

namespace App\Jobs;

use App\Exceptions\GitLabApiException;
use App\Jobs\Middleware\RetryWithBackoff;
use App\Models\Task;
use App\Services\FailureHandler;
use App\Services\TaskDispatcher;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessTask implements ShouldQueue
{
    use Queueable;

    /**
     * 1 initial + 3 retries = 4 total attempts.
     */
    public int $tries = 4;

    /**
     * Accumulated by RetryWithBackoff middleware on each transient error.
     * Passed to FailureHandler → DLQ entry on permanent failure.
     *
     * @var array<int, array{attempt: int, timestamp: string, error: string}>
     */
    public array $attemptHistory = [];

    public function __construct(
        public readonly int $taskId,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RetryWithBackoff];
    }

    /**
     * Resolve the correct queue name based on task type and priority.
     *
     * Called before dispatch to set $this->queue.
     */
    public function resolveQueue(Task $task): void
    {
        if ($task->type->executionMode() === 'server') {
            $this->queue = QueueNames::SERVER;
        } else {
            $this->queue = $task->priority->runnerQueueName();
        }
    }

    /**
     * Execute the job.
     *
     * Delegates to TaskDispatcher for strategy selection and execution mode routing.
     */
    public function handle(TaskDispatcher $dispatcher): void
    {
        $task = Task::find($this->taskId);

        if ($task === null) {
            Log::warning('ProcessTask: task not found', ['task_id' => $this->taskId]);

            return;
        }

        if ($task->isTerminal()) {
            Log::info('ProcessTask: task already in terminal state, skipping', [
                'task_id' => $this->taskId,
                'status' => $task->status->value,
            ]);

            return;
        }

        Log::info('ProcessTask: dispatching task', [
            'task_id' => $this->taskId,
            'type' => $task->type->value,
            'priority' => $task->priority->value,
            'execution_mode' => $task->type->executionMode(),
        ]);

        $dispatcher->dispatch($task);
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
            Log::warning('ProcessTask::failed: task not found', ['task_id' => $this->taskId]);

            return;
        }

        $failureReason = $this->classifyFailureReason($exception);
        $errorDetails = $exception?->getMessage() ?? 'Unknown error';

        if ($exception instanceof GitLabApiException) {
            $errorDetails = "HTTP {$exception->statusCode}: {$exception->responseBody}";
        }

        app(FailureHandler::class)->handlePermanentFailure(
            task: $task,
            failureReason: $failureReason,
            errorDetails: $errorDetails,
            attempts: $this->attemptHistory,
        );
    }

    private function classifyFailureReason(?\Throwable $exception): string
    {
        if ($exception instanceof GitLabApiException) {
            if ($exception->isInvalidRequest()) {
                return 'invalid_request';
            }
        }

        return 'max_retries_exceeded';
    }
}

<?php

namespace App\Jobs;

use App\Exceptions\GitLabApiException;
use App\Jobs\Middleware\RetryWithBackoff;
use App\Models\Task;
use App\Modules\Shared\Domain\InternalEvent;
use App\Modules\TaskOrchestration\Application\Registries\ResultPublisherRegistry;
use App\Modules\TaskOrchestration\Infrastructure\Outbox\OutboxWriter;
use App\Services\FailureHandler;
use App\Services\ResultProcessor;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /**
     * Accumulated by RetryWithBackoff middleware on each transient error.
     * Passed to FailureHandler → DLQ entry on permanent failure.
     *
     * @var array<int, array{attempt: int, timestamp: string, error: string}>
     */
    public array $attemptHistory = [];

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

    public function handle(ResultProcessor $processor, ?ResultPublisherRegistry $resultPublisherRegistry = null): void
    {
        $resultPublisherRegistry ??= app(ResultPublisherRegistry::class);

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

        $outboxEnabled = (bool) config('vunnix.events.outbox_enabled', false);
        $shadowMode = (bool) config('vunnix.events.outbox_shadow_mode', false);

        if ($outboxEnabled && ! $shadowMode) {
            $this->mirrorToOutbox($task, true);

            return;
        }

        $resultPublisherRegistry->publish($task);

        if ($outboxEnabled || $shadowMode) {
            $this->mirrorToOutbox($task, $outboxEnabled);
        }
    }

    /**
     * Handle permanent job failure.
     *
     * Called by Laravel when $job->fail() is invoked (by RetryWithBackoff middleware
     * after max retries, or on non-retryable errors).
     */
    public function failed(?Throwable $exception): void
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
            attempts: $this->attemptHistory,
        );
    }

    private function mirrorToOutbox(Task $task, bool $dispatchDelivery): void
    {
        /** @var OutboxWriter $outboxWriter */
        $outboxWriter = app(OutboxWriter::class);

        $event = InternalEvent::make(
            eventType: 'task.result.processed',
            aggregateType: 'task',
            aggregateId: (string) $task->id,
            payload: [
                'task_id' => $task->id,
                'task_type' => $task->type->value,
                'task_status' => $task->status->value,
            ],
        );

        $outboxWriter->write(
            event: $event,
            idempotencyKey: 'task-result-processed:'.$task->id,
        );

        if ($dispatchDelivery) {
            DeliverOutboxEvents::dispatch();
        }
    }
}

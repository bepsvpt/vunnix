<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Jobs\PostFailureComment;
use App\Models\DeadLetterEntry;
use App\Models\Task;
use Illuminate\Support\Facades\Log;

class FailureHandler
{
    /**
     * Handle a task that has permanently failed.
     *
     * @param  Task  $task  The task that failed
     * @param  string  $failureReason  Classification: max_retries_exceeded, invalid_request, context_exceeded, scheduling_timeout, expired
     * @param  string  $errorDetails  Last error message / HTTP status / response body
     * @param  array<int, array{attempt: int, timestamp: string, error: string}>  $attempts  Retry attempt history
     */
    public function handlePermanentFailure(
        Task $task,
        string $failureReason,
        string $errorDetails,
        array $attempts,
    ): void {
        // Don't process tasks already in terminal state
        if ($task->isTerminal()) {
            Log::info('FailureHandler: task already terminal, skipping', [
                'task_id' => $task->id,
                'status' => $task->status->value,
            ]);

            return;
        }

        // 1. Transition task to Failed
        $task->transitionTo(TaskStatus::Failed, $failureReason);

        Log::error('FailureHandler: task permanently failed', [
            'task_id' => $task->id,
            'failure_reason' => $failureReason,
            'error_details' => $errorDetails,
        ]);

        // 2. Create DLQ entry
        DeadLetterEntry::create([
            'task_record' => $task->toArray(),
            'failure_reason' => $failureReason,
            'error_details' => $errorDetails,
            'attempts' => $attempts,
            'originally_queued_at' => $task->created_at,
            'dead_lettered_at' => now(),
        ]);

        // 3. Dispatch failure comment (only for tasks with MR or Issue)
        if ($task->mr_iid !== null || $task->issue_iid !== null) {
            PostFailureComment::dispatch(
                taskId: $task->id,
                failureReason: $failureReason,
                errorDetails: $errorDetails,
            );
        }
    }
}

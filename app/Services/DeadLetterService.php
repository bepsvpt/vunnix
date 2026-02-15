<?php

namespace App\Services;

use App\Enums\TaskOrigin;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\ProcessTask;
use App\Models\DeadLetterEntry;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class DeadLetterService
{
    /**
     * Retry a DLQ entry by creating a new task and dispatching it.
     *
     * Creates a fresh task from the original task's snapshot data, transitions it
     * to Queued, and dispatches a ProcessTask job. The DLQ entry is marked
     * as retried with a reference to the new task.
     *
     * @throws \LogicException if the entry has already been retried or dismissed
     */
    public function retry(DeadLetterEntry $entry, User $admin): Task
    {
        if ($entry->retried) {
            throw new \LogicException('This DLQ entry has already been retried.');
        }

        if ($entry->dismissed) {
            throw new \LogicException('Cannot retry a dismissed DLQ entry.');
        }

        $taskData = $entry->task_record;

        // Create a new task from the original's snapshot data
        $newTask = Task::create([
            'type' => $taskData['type'],
            'origin' => $taskData['origin'] ?? TaskOrigin::Webhook->value,
            'user_id' => $taskData['user_id'] ?? null,
            'project_id' => $taskData['project_id'],
            'priority' => $taskData['priority'] ?? TaskPriority::Normal->value,
            'status' => TaskStatus::Received,
            'mr_iid' => $taskData['mr_iid'] ?? null,
            'issue_iid' => $taskData['issue_iid'] ?? null,
            'commit_sha' => $taskData['commit_sha'] ?? null,
            'conversation_id' => $taskData['conversation_id'] ?? null,
        ]);

        $newTask->transitionTo(TaskStatus::Queued);

        // Mark DLQ entry as retried
        $entry->update([
            'retried' => true,
            'retried_at' => now(),
            'retried_by' => $admin->id,
            'retried_task_id' => $newTask->id,
        ]);

        // Dispatch the processing job
        $job = new ProcessTask($newTask->id);
        $job->resolveQueue($newTask);
        dispatch($job);

        Log::info('DeadLetterService: retried DLQ entry', [
            'dlq_id' => $entry->id,
            'original_task_id' => $entry->task_id,
            'new_task_id' => $newTask->id,
            'admin_id' => $admin->id,
        ]);

        return $newTask;
    }

    /**
     * Dismiss (acknowledge) a DLQ entry.
     *
     * Removes it from the active DLQ view but retains in database
     * per D96 indefinite retention.
     *
     * @throws \LogicException if the entry is already dismissed or retried
     */
    public function dismiss(DeadLetterEntry $entry, User $admin): void
    {
        if ($entry->dismissed) {
            throw new \LogicException('This DLQ entry has already been dismissed.');
        }

        if ($entry->retried) {
            throw new \LogicException('Cannot dismiss a retried DLQ entry.');
        }

        $entry->update([
            'dismissed' => true,
            'dismissed_at' => now(),
            'dismissed_by' => $admin->id,
        ]);

        Log::info('DeadLetterService: dismissed DLQ entry', [
            'dlq_id' => $entry->id,
            'task_id' => $entry->task_id,
            'admin_id' => $admin->id,
        ]);
    }
}

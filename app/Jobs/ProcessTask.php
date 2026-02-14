<?php

namespace App\Jobs;

use App\Models\Task;
use App\Services\TaskDispatcher;
use App\Support\QueueNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessTask implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $taskId,
    ) {}

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
}

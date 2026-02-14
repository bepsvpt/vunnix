<?php

namespace App\Observers;

use App\Models\Task;
use Illuminate\Support\Facades\DB;

class TaskObserver
{
    /**
     * Log state transitions whenever a task is updated.
     */
    public function updated(Task $task): void
    {
        if (! $task->wasChanged('status')) {
            return;
        }

        DB::table('task_transition_logs')->insert([
            'task_id' => $task->id,
            'from_status' => $task->getOriginal('status') instanceof \App\Enums\TaskStatus
                ? $task->getOriginal('status')->value
                : $task->getOriginal('status'),
            'to_status' => $task->status->value,
            'transitioned_at' => now(),
        ]);
    }
}

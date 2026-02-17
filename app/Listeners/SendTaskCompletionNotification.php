<?php

namespace App\Listeners;

use App\Enums\TaskStatus;
use App\Events\TaskStatusChanged;
use App\Services\AlertEventService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendTaskCompletionNotification
{
    public function __construct(
        private readonly AlertEventService $alertEventService,
    ) {}

    public function handle(TaskStatusChanged $event): void
    {
        $task = $event->task;

        // Only notify for terminal states: Completed and Failed
        // Superseded tasks are silent — they were replaced by a newer version
        if (! in_array($task->status, [TaskStatus::Completed, TaskStatus::Failed], true)) {
            return;
        }

        // Idempotent: only send once per task ID
        $cacheKey = "task_chat_notified:{$task->id}";
        if (Cache::has($cacheKey)) {
            return;
        }

        try {
            $this->alertEventService->notifyTaskCompletion($task);

            // Mark as notified — cache for 24 hours (beyond any retry window)
            Cache::put($cacheKey, true, now()->addHours(24));
        } catch (Throwable $e) {
            Log::warning('SendTaskCompletionNotification: failed to send', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

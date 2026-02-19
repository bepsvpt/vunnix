<?php

namespace App\Modules\TaskOrchestration\Application\Publishers;

use App\Enums\TaskType;
use App\Jobs\PostFeatureDevResult;
use App\Models\Task;
use App\Modules\TaskOrchestration\Application\Contracts\ResultPublisher;

class FeatureDevelopmentResultPublisher implements ResultPublisher
{
    public function priority(): int
    {
        return 70;
    }

    public function supports(Task $task): bool
    {
        return in_array($task->type, [TaskType::FeatureDev, TaskType::UiAdjustment], true)
            && ($task->issue_iid !== null || $task->conversation_id !== null);
    }

    public function publish(Task $task): void
    {
        PostFeatureDevResult::dispatch($task->id);
    }
}

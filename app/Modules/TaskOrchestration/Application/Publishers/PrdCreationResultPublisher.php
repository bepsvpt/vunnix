<?php

namespace App\Modules\TaskOrchestration\Application\Publishers;

use App\Enums\TaskType;
use App\Jobs\CreateGitLabIssue;
use App\Models\Task;
use App\Modules\TaskOrchestration\Application\Contracts\ResultPublisher;

class PrdCreationResultPublisher implements ResultPublisher
{
    public function priority(): int
    {
        return 60;
    }

    public function supports(Task $task): bool
    {
        return $task->type === TaskType::PrdCreation;
    }

    public function publish(Task $task): void
    {
        CreateGitLabIssue::dispatch($task->id);
    }
}

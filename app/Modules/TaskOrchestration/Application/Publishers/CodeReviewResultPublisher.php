<?php

namespace App\Modules\TaskOrchestration\Application\Publishers;

use App\Enums\TaskType;
use App\Jobs\PostInlineThreads;
use App\Jobs\PostLabelsAndStatus;
use App\Jobs\PostSummaryComment;
use App\Models\Task;
use App\Modules\TaskOrchestration\Application\Contracts\ResultPublisher;

class CodeReviewResultPublisher implements ResultPublisher
{
    public function priority(): int
    {
        return 100;
    }

    public function supports(Task $task): bool
    {
        return $task->mr_iid !== null
            && in_array($task->type, [TaskType::CodeReview, TaskType::SecurityAudit], true);
    }

    public function publish(Task $task): void
    {
        PostSummaryComment::dispatch($task->id);
        PostInlineThreads::dispatch($task->id);
        PostLabelsAndStatus::dispatch($task->id);
    }
}

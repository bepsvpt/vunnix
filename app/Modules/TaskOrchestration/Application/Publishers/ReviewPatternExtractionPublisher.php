<?php

namespace App\Modules\TaskOrchestration\Application\Publishers;

use App\Enums\TaskType;
use App\Jobs\ExtractReviewPatterns;
use App\Models\Task;
use App\Modules\TaskOrchestration\Application\Contracts\ResultPublisher;

class ReviewPatternExtractionPublisher implements ResultPublisher
{
    public function priority(): int
    {
        return 50;
    }

    public function supports(Task $task): bool
    {
        if (! (bool) config('vunnix.memory.enabled', true) || ! (bool) config('vunnix.memory.review_learning', true)) {
            return false;
        }

        if (! in_array($task->type, [TaskType::CodeReview, TaskType::SecurityAudit], true)) {
            return false;
        }

        $findings = $task->result['findings'] ?? [];

        return is_array($findings) && count($findings) > 0;
    }

    public function publish(Task $task): void
    {
        ExtractReviewPatterns::dispatch($task->id);
    }
}

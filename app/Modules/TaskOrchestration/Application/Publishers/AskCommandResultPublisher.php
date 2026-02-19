<?php

namespace App\Modules\TaskOrchestration\Application\Publishers;

use App\Enums\TaskType;
use App\Jobs\PostAnswerComment;
use App\Models\Task;
use App\Modules\TaskOrchestration\Application\Contracts\ResultPublisher;

class AskCommandResultPublisher implements ResultPublisher
{
    public function priority(): int
    {
        return 90;
    }

    public function supports(Task $task): bool
    {
        return $task->mr_iid !== null
            && $task->type === TaskType::IssueDiscussion
            && ($task->result['intent'] ?? null) === 'ask_command';
    }

    public function publish(Task $task): void
    {
        PostAnswerComment::dispatch($task->id);
    }
}

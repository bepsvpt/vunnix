<?php

namespace App\Modules\TaskOrchestration\Application\Publishers;

use App\Enums\TaskType;
use App\Jobs\PostIssueComment;
use App\Models\Task;
use App\Modules\TaskOrchestration\Application\Contracts\ResultPublisher;

class IssueDiscussionResultPublisher implements ResultPublisher
{
    public function priority(): int
    {
        return 80;
    }

    public function supports(Task $task): bool
    {
        return $task->issue_iid !== null
            && $task->type === TaskType::IssueDiscussion
            && ($task->result['intent'] ?? null) === 'issue_discussion';
    }

    public function publish(Task $task): void
    {
        PostIssueComment::dispatch($task->id);
    }
}

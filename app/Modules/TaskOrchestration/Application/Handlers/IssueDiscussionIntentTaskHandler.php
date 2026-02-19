<?php

namespace App\Modules\TaskOrchestration\Application\Handlers;

use App\Enums\TaskType;
use App\Modules\TaskOrchestration\Application\Contracts\TaskHandler;
use App\Services\RoutingResult;

class IssueDiscussionIntentTaskHandler implements TaskHandler
{
    /**
     * @var array<int, string>
     */
    private const SUPPORTED_INTENTS = [
        'ask_command',
        'issue_discussion',
    ];

    public function priority(): int
    {
        return 90;
    }

    public function supports(RoutingResult $routingResult): bool
    {
        return in_array($routingResult->intent, self::SUPPORTED_INTENTS, true);
    }

    public function resolveTaskType(RoutingResult $routingResult): ?TaskType
    {
        if (! $this->supports($routingResult)) {
            return null;
        }

        return TaskType::IssueDiscussion;
    }
}

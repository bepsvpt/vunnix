<?php

namespace App\Modules\TaskOrchestration\Application\Handlers;

use App\Enums\TaskType;
use App\Modules\TaskOrchestration\Application\Contracts\TaskHandler;
use App\Services\RoutingResult;

class FeatureDevelopmentIntentTaskHandler implements TaskHandler
{
    public function priority(): int
    {
        return 80;
    }

    public function supports(RoutingResult $routingResult): bool
    {
        return $routingResult->intent === 'feature_dev';
    }

    public function resolveTaskType(RoutingResult $routingResult): ?TaskType
    {
        if (! $this->supports($routingResult)) {
            return null;
        }

        return TaskType::FeatureDev;
    }
}

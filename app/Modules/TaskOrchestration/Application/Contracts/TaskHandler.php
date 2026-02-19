<?php

namespace App\Modules\TaskOrchestration\Application\Contracts;

use App\Enums\TaskType;
use App\Services\RoutingResult;

interface TaskHandler
{
    /**
     * Higher value means earlier evaluation.
     */
    public function priority(): int;

    public function supports(RoutingResult $routingResult): bool;

    public function resolveTaskType(RoutingResult $routingResult): ?TaskType;
}

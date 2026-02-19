<?php

namespace App\Modules\TaskOrchestration\Application\Registries;

use App\Enums\TaskType;
use App\Modules\TaskOrchestration\Application\Contracts\TaskHandler;
use App\Services\RoutingResult;

class TaskHandlerRegistry
{
    /**
     * @var array<int, TaskHandler>|null
     */
    private ?array $ordered = null;

    /**
     * @param  iterable<TaskHandler>  $handlers
     */
    public function __construct(
        private readonly iterable $handlers,
    ) {}

    /**
     * @return array<int, TaskHandler>
     */
    public function all(): array
    {
        if ($this->ordered !== null) {
            return $this->ordered;
        }

        $handlers = is_array($this->handlers)
            ? $this->handlers
            : iterator_to_array($this->handlers, false);

        usort($handlers, static function (TaskHandler $a, TaskHandler $b): int {
            if ($a->priority() !== $b->priority()) {
                return $b->priority() <=> $a->priority();
            }

            return $a::class <=> $b::class;
        });

        return $this->ordered = $handlers;
    }

    public function resolve(RoutingResult $routingResult): ?TaskHandler
    {
        foreach ($this->all() as $handler) {
            if ($handler->supports($routingResult)) {
                return $handler;
            }
        }

        return null;
    }

    public function resolveTaskType(RoutingResult $routingResult): ?TaskType
    {
        $handler = $this->resolve($routingResult);

        if (! $handler instanceof TaskHandler) {
            return null;
        }

        return $handler->resolveTaskType($routingResult);
    }
}

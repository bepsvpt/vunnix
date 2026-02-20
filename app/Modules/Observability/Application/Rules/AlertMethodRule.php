<?php

namespace App\Modules\Observability\Application\Rules;

use App\Models\AlertEvent;
use App\Modules\Observability\Application\Contracts\AlertRule;
use App\Services\AlertEventService;
use Carbon\Carbon;

class AlertMethodRule implements AlertRule
{
    public function __construct(
        private readonly string $key,
        private readonly string $method,
        private readonly int $priority,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function evaluate(AlertEventService $service, Carbon $now): ?AlertEvent
    {
        /** @var callable(Carbon): ?AlertEvent $callable */
        $callable = [$service, $this->method];

        return $callable($now);
    }
}

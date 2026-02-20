<?php

namespace App\Modules\Shared\Infrastructure\Adapters;

use App\Modules\Shared\Application\Contracts\RealtimePort;
use Illuminate\Contracts\Events\Dispatcher;

class RealtimePortAdapter implements RealtimePort
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
    ) {}

    public function dispatch(object $event): void
    {
        $this->dispatcher->dispatch($event);
    }
}

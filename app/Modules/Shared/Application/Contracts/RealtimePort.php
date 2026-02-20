<?php

namespace App\Modules\Shared\Application\Contracts;

interface RealtimePort
{
    public function dispatch(object $event): void;
}

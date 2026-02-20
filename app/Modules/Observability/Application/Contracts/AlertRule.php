<?php

namespace App\Modules\Observability\Application\Contracts;

use App\Models\AlertEvent;
use App\Services\AlertEventService;
use Carbon\Carbon;

interface AlertRule
{
    public function key(): string;

    public function priority(): int;

    public function evaluate(AlertEventService $service, Carbon $now): ?AlertEvent;
}

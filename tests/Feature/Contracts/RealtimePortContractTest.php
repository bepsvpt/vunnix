<?php

use App\Modules\Shared\Application\Contracts\RealtimePort;
use App\Modules\Shared\Infrastructure\Adapters\RealtimePortAdapter;

it('resolves RealtimePort contract to compatibility adapter', function (): void {
    $resolved = app(RealtimePort::class);

    expect($resolved)->toBeInstanceOf(RealtimePortAdapter::class);
});

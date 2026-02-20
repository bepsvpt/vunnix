<?php

use App\Modules\Shared\Application\Contracts\NotificationPort;
use App\Modules\Shared\Infrastructure\Adapters\NotificationPortAdapter;

it('resolves NotificationPort contract to compatibility adapter', function (): void {
    $resolved = app(NotificationPort::class);

    expect($resolved)->toBeInstanceOf(NotificationPortAdapter::class);
});

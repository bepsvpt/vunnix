<?php

use App\Modules\Shared\Infrastructure\Adapters\NotificationPortAdapter;
use App\Services\TeamChat\TeamChatNotificationService;

it('delegates notification send operations to team chat service', function (): void {
    $service = Mockery::mock(TeamChatNotificationService::class);
    $service->shouldReceive('send')
        ->once()
        ->with('alert', 'Disk usage high', ['urgency' => 'high'])
        ->andReturn(true);

    $adapter = new NotificationPortAdapter($service);

    expect($adapter->send('alert', 'Disk usage high', ['urgency' => 'high']))->toBeTrue();
});

it('delegates sendTest operations to team chat service', function (): void {
    $service = Mockery::mock(TeamChatNotificationService::class);
    $service->shouldReceive('sendTest')
        ->once()
        ->with('https://example.test/webhook', 'slack')
        ->andReturn(true);

    $adapter = new NotificationPortAdapter($service);

    expect($adapter->sendTest('https://example.test/webhook', 'slack'))->toBeTrue();
});

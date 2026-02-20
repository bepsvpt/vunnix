<?php

use App\Modules\Shared\Infrastructure\Adapters\RealtimePortAdapter;
use Illuminate\Contracts\Events\Dispatcher;

it('delegates realtime dispatch to laravel dispatcher', function (): void {
    $dispatcher = Mockery::mock(Dispatcher::class);
    $event = new stdClass;

    $dispatcher->shouldReceive('dispatch')
        ->once()
        ->with($event);

    $adapter = new RealtimePortAdapter($dispatcher);
    $adapter->dispatch($event);

    expect(true)->toBeTrue();
});

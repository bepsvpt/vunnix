<?php

use App\Agents\VunnixAgent;
use App\Modules\Shared\Infrastructure\Adapters\AiProviderAdapter;

it('delegates provider lookup to vunnix agent', function (): void {
    $agent = Mockery::mock(VunnixAgent::class);
    $agent->shouldReceive('provider')
        ->once()
        ->andReturn('anthropic');

    $adapter = new AiProviderAdapter($agent);

    expect($adapter->provider())->toBe('anthropic');
});

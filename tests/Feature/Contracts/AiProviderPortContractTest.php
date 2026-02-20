<?php

use App\Modules\Shared\Application\Contracts\AiProviderPort;
use App\Modules\Shared\Infrastructure\Adapters\AiProviderAdapter;

it('resolves AiProviderPort contract to compatibility adapter', function (): void {
    $resolved = app(AiProviderPort::class);

    expect($resolved)->toBeInstanceOf(AiProviderAdapter::class);
});

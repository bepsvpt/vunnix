<?php

namespace App\Modules\Shared\Infrastructure\Adapters;

use App\Agents\VunnixAgent;
use App\Modules\Shared\Application\Contracts\AiProviderPort;

class AiProviderAdapter implements AiProviderPort
{
    public function __construct(
        private readonly VunnixAgent $agent,
    ) {}

    public function provider(): string
    {
        return $this->agent->provider();
    }
}

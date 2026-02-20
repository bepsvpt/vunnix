<?php

namespace App\Modules\Chat\Application\Providers;

use App\Agents\VunnixAgent;
use App\Modules\Chat\Application\Contracts\ChatPromptProvider;

class DefaultChatPromptProvider implements ChatPromptProvider
{
    public function build(VunnixAgent $agent): string
    {
        return $agent->buildSystemPromptForProvider();
    }
}

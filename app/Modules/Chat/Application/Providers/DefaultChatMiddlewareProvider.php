<?php

namespace App\Modules\Chat\Application\Providers;

use App\Agents\Middleware\PruneConversationHistory;
use App\Modules\Chat\Application\Contracts\ChatMiddlewareProvider;

class DefaultChatMiddlewareProvider implements ChatMiddlewareProvider
{
    public function middleware(): array
    {
        return [
            app(PruneConversationHistory::class),
        ];
    }
}

<?php

namespace App\Modules\TaskOrchestration\Application\Contracts;

use App\Events\Webhook\WebhookEvent;
use App\Services\RoutingResult;

interface IntentClassifier
{
    /**
     * Higher value means earlier evaluation.
     */
    public function priority(): int;

    public function supports(WebhookEvent $event): bool;

    public function classify(WebhookEvent $event): ?RoutingResult;
}

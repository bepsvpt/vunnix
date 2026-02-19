<?php

namespace App\Modules\WebhookIntake\Application\Classifiers;

use App\Events\Webhook\PushToMRBranch;
use App\Events\Webhook\WebhookEvent;
use App\Modules\TaskOrchestration\Application\Contracts\IntentClassifier;
use App\Services\RoutingResult;

class PushToMergeRequestClassifier implements IntentClassifier
{
    public function priority(): int
    {
        return 60;
    }

    public function supports(WebhookEvent $event): bool
    {
        return $event instanceof PushToMRBranch;
    }

    public function classify(WebhookEvent $event): ?RoutingResult
    {
        if (! $event instanceof PushToMRBranch) {
            return null;
        }

        return new RoutingResult('incremental_review', 'normal', $event);
    }
}

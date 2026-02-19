<?php

namespace App\Modules\WebhookIntake\Application\Classifiers;

use App\Events\Webhook\MergeRequestMerged;
use App\Events\Webhook\MergeRequestOpened;
use App\Events\Webhook\MergeRequestUpdated;
use App\Events\Webhook\WebhookEvent;
use App\Modules\TaskOrchestration\Application\Contracts\IntentClassifier;
use App\Services\RoutingResult;

class MergeRequestLifecycleClassifier implements IntentClassifier
{
    public function priority(): int
    {
        return 100;
    }

    public function supports(WebhookEvent $event): bool
    {
        return $event instanceof MergeRequestOpened
            || $event instanceof MergeRequestUpdated
            || $event instanceof MergeRequestMerged;
    }

    public function classify(WebhookEvent $event): ?RoutingResult
    {
        if ($event instanceof MergeRequestOpened || $event instanceof MergeRequestUpdated) {
            return new RoutingResult('auto_review', 'normal', $event);
        }

        if ($event instanceof MergeRequestMerged) {
            return new RoutingResult('acceptance_tracking', 'normal', $event);
        }

        return null;
    }
}

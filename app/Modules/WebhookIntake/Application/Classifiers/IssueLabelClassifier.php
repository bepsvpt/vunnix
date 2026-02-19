<?php

namespace App\Modules\WebhookIntake\Application\Classifiers;

use App\Events\Webhook\IssueLabelChanged;
use App\Events\Webhook\WebhookEvent;
use App\Modules\TaskOrchestration\Application\Contracts\IntentClassifier;
use App\Services\RoutingResult;

class IssueLabelClassifier implements IntentClassifier
{
    public function priority(): int
    {
        return 70;
    }

    public function supports(WebhookEvent $event): bool
    {
        return $event instanceof IssueLabelChanged;
    }

    public function classify(WebhookEvent $event): ?RoutingResult
    {
        if (! $event instanceof IssueLabelChanged) {
            return null;
        }

        if ($event->hasLabel('ai::develop')) {
            return new RoutingResult('feature_dev', 'low', $event);
        }

        return null;
    }
}

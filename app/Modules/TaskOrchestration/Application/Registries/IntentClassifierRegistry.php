<?php

namespace App\Modules\TaskOrchestration\Application\Registries;

use App\Events\Webhook\WebhookEvent;
use App\Modules\TaskOrchestration\Application\Contracts\IntentClassifier;
use App\Services\RoutingResult;

class IntentClassifierRegistry
{
    /**
     * @var array<int, IntentClassifier>|null
     */
    private ?array $ordered = null;

    /**
     * @param  iterable<IntentClassifier>  $classifiers
     */
    public function __construct(
        private readonly iterable $classifiers,
    ) {}

    /**
     * @return array<int, IntentClassifier>
     */
    public function all(): array
    {
        if ($this->ordered !== null) {
            return $this->ordered;
        }

        $classifiers = is_array($this->classifiers)
            ? $this->classifiers
            : iterator_to_array($this->classifiers, false);

        usort($classifiers, static function (IntentClassifier $a, IntentClassifier $b): int {
            if ($a->priority() !== $b->priority()) {
                return $b->priority() <=> $a->priority();
            }

            return $a::class <=> $b::class;
        });

        return $this->ordered = $classifiers;
    }

    public function resolve(WebhookEvent $event): ?IntentClassifier
    {
        foreach ($this->all() as $classifier) {
            if ($classifier->supports($event)) {
                return $classifier;
            }
        }

        return null;
    }

    public function classify(WebhookEvent $event): ?RoutingResult
    {
        $classifier = $this->resolve($event);

        if (! $classifier instanceof IntentClassifier) {
            return null;
        }

        return $classifier->classify($event);
    }
}

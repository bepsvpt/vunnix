<?php

namespace App\Services;

use App\Events\Webhook\WebhookEvent;

/**
 * Represents the outcome of the EventRouter's intent classification.
 *
 * Each webhook event is routed to an intent (what AI action to perform)
 * with a priority level. Downstream consumers (T15-T17) use this to
 * create Task models and dispatch to the appropriate queue.
 */
class RoutingResult
{
    /**
     * @param  string  $intent  The classified AI action: auto_review, on_demand_review, improve, ask_command,
     *                          issue_discussion, feature_dev, incremental_review, acceptance_tracking, help_response
     * @param  string  $priority  normal, high, or low
     * @param  WebhookEvent  $event  The parsed webhook event
     * @param  array<string, mixed>  $metadata  Extra context for the intent (e.g., extracted question for ask_command)
     */
    public function __construct(
        public readonly string $intent,
        public readonly string $priority,
        public readonly WebhookEvent $event,
        public readonly array $metadata = [],
    ) {}
}

<?php

namespace App\Events\Webhook;

/**
 * Base class for all webhook-derived events.
 *
 * These are simple DTOs (not Laravel Events) that carry normalized,
 * typed data from the raw GitLab webhook payload. The EventRouter
 * creates the appropriate subclass from the webhook context array.
 */
abstract class WebhookEvent
{
    public function __construct(
        public readonly int $projectId,
        public readonly int $gitlabProjectId,
        public readonly array $payload,
    ) {}

    /**
     * The internal event type identifier used for routing.
     */
    abstract public function type(): string;
}

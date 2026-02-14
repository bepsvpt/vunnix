<?php

namespace App\Services;

/**
 * Result of the EventDeduplicator's processing.
 *
 * Contains the outcome (accept/reject) and metadata about any
 * superseding that occurred.
 */
class DeduplicationResult
{
    public function __construct(
        public readonly string $outcome,
        public readonly int $supersededCount = 0,
    ) {}

    /**
     * Whether the event should be processed (not a duplicate).
     */
    public function accepted(): bool
    {
        return $this->outcome === EventDeduplicator::ACCEPT;
    }

    /**
     * Whether the event was rejected as a duplicate.
     */
    public function rejected(): bool
    {
        return ! $this->accepted();
    }

    /**
     * Whether any existing tasks were superseded by this event.
     */
    public function didSupersede(): bool
    {
        return $this->supersededCount > 0;
    }
}

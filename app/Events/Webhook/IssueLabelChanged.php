<?php

namespace App\Events\Webhook;

class IssueLabelChanged extends WebhookEvent
{
    /**
     * @param  array<int, string>  $labels  Current label titles on the issue.
     */
    public function __construct(
        int $projectId,
        int $gitlabProjectId,
        array $payload,
        public readonly int $issueIid,
        public readonly string $action,
        public readonly int $authorId,
        public readonly array $labels,
    ) {
        parent::__construct($projectId, $gitlabProjectId, $payload);
    }

    public function type(): string
    {
        return 'issue_label_changed';
    }

    /**
     * Check if a specific label is present on the issue.
     */
    public function hasLabel(string $label): bool
    {
        return in_array($label, $this->labels, true);
    }
}

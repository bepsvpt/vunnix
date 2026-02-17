<?php

namespace App\Events\Webhook;

class NoteOnIssue extends WebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        int $projectId,
        int $gitlabProjectId,
        array $payload,
        public readonly int $issueIid,
        public readonly string $note,
        public readonly int $authorId,
    ) {
        parent::__construct($projectId, $gitlabProjectId, $payload);
    }

    public function type(): string
    {
        return 'note_on_issue';
    }
}

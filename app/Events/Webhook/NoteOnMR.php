<?php

namespace App\Events\Webhook;

class NoteOnMR extends WebhookEvent
{
    public function __construct(
        int $projectId,
        int $gitlabProjectId,
        array $payload,
        public readonly int $mergeRequestIid,
        public readonly string $note,
        public readonly int $authorId,
    ) {
        parent::__construct($projectId, $gitlabProjectId, $payload);
    }

    public function type(): string
    {
        return 'note_on_mr';
    }
}

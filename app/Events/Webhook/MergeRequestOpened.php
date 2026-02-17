<?php

namespace App\Events\Webhook;

class MergeRequestOpened extends WebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        int $projectId,
        int $gitlabProjectId,
        array $payload,
        public readonly int $mergeRequestIid,
        public readonly string $sourceBranch,
        public readonly string $targetBranch,
        public readonly int $authorId,
        public readonly ?string $lastCommitSha,
    ) {
        parent::__construct($projectId, $gitlabProjectId, $payload);
    }

    public function type(): string
    {
        return 'merge_request_opened';
    }
}

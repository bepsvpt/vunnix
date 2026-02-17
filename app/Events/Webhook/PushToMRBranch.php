<?php

namespace App\Events\Webhook;

class PushToMRBranch extends WebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $commits  List of commit objects from the push payload.
     */
    public function __construct(
        int $projectId,
        int $gitlabProjectId,
        array $payload,
        public readonly string $ref,
        public readonly string $beforeSha,
        public readonly string $afterSha,
        public readonly int $userId,
        public readonly array $commits,
        public readonly int $totalCommitsCount,
    ) {
        parent::__construct($projectId, $gitlabProjectId, $payload);
    }

    public function type(): string
    {
        return 'push_to_mr_branch';
    }

    /**
     * Extract the branch name from the full ref (refs/heads/feature/login → feature/login).
     */
    public function branchName(): string
    {
        return preg_replace('#^refs/heads/#', '', $this->ref);
    }
}

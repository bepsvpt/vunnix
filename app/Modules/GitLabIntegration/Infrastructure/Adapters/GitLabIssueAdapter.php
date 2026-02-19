<?php

namespace App\Modules\GitLabIntegration\Infrastructure\Adapters;

use App\Modules\GitLabIntegration\Application\Contracts\GitLabIssuePort;
use App\Services\GitLabClient;

class GitLabIssueAdapter implements GitLabIssuePort
{
    public function __construct(
        private readonly GitLabClient $client,
    ) {}

    public function listIssues(int $projectId, array $params = []): array
    {
        return $this->client->listIssues($projectId, $params);
    }

    public function getIssue(int $projectId, int $issueIid): array
    {
        return $this->client->getIssue($projectId, $issueIid);
    }

    public function createIssue(int $projectId, array $data): array
    {
        return $this->client->createIssue($projectId, $data);
    }

    public function createIssueNote(int $projectId, int $issueIid, string $body): array
    {
        return $this->client->createIssueNote($projectId, $issueIid, $body);
    }
}

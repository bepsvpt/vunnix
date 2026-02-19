<?php

namespace App\Modules\GitLabIntegration\Infrastructure\Adapters;

use App\Modules\GitLabIntegration\Application\Contracts\GitLabMergeRequestPort;
use App\Services\GitLabClient;

class GitLabMergeRequestAdapter implements GitLabMergeRequestPort
{
    public function __construct(
        private readonly GitLabClient $client,
    ) {}

    public function listMergeRequests(int $projectId, array $params = []): array
    {
        return $this->client->listMergeRequests($projectId, $params);
    }

    public function getMergeRequest(int $projectId, int $mrIid): array
    {
        return $this->client->getMergeRequest($projectId, $mrIid);
    }

    public function getMergeRequestChanges(int $projectId, int $mrIid): array
    {
        return $this->client->getMergeRequestChanges($projectId, $mrIid);
    }

    public function createMergeRequest(int $projectId, array $data): array
    {
        return $this->client->createMergeRequest($projectId, $data);
    }

    public function updateMergeRequest(int $projectId, int $mrIid, array $data): array
    {
        return $this->client->updateMergeRequest($projectId, $mrIid, $data);
    }

    public function findOpenMergeRequestForBranch(int $projectId, string $sourceBranch): ?array
    {
        return $this->client->findOpenMergeRequestForBranch($projectId, $sourceBranch);
    }

    public function createMergeRequestNote(int $projectId, int $mrIid, string $body): array
    {
        return $this->client->createMergeRequestNote($projectId, $mrIid, $body);
    }

    public function updateMergeRequestNote(int $projectId, int $mrIid, int $noteId, string $body): array
    {
        return $this->client->updateMergeRequestNote($projectId, $mrIid, $noteId, $body);
    }

    public function listMergeRequestDiscussions(int $projectId, int $mrIid, array $params = []): array
    {
        return $this->client->listMergeRequestDiscussions($projectId, $mrIid, $params);
    }

    public function createMergeRequestDiscussion(int $projectId, int $mrIid, string $body, array $position = []): array
    {
        return $this->client->createMergeRequestDiscussion($projectId, $mrIid, $body, $position);
    }
}

<?php

namespace App\Modules\GitLabIntegration\Infrastructure\Adapters;

use App\Modules\GitLabIntegration\Application\Contracts\GitLabPort;

class GitLabPortAdapter implements GitLabPort
{
    public function __construct(
        private readonly GitLabRepoAdapter $repo,
        private readonly GitLabIssueAdapter $issue,
        private readonly GitLabMergeRequestAdapter $mergeRequest,
        private readonly GitLabPipelineAdapter $pipeline,
    ) {}

    public function getFile(int $projectId, string $filePath, string $ref = 'main'): array
    {
        return $this->repo->getFile($projectId, $filePath, $ref);
    }

    public function listTree(int $projectId, string $path = '', string $ref = 'main', bool $recursive = false): array
    {
        return $this->repo->listTree($projectId, $path, $ref, $recursive);
    }

    public function searchCode(int $projectId, string $query): array
    {
        return $this->repo->searchCode($projectId, $query);
    }

    public function listIssues(int $projectId, array $params = []): array
    {
        return $this->issue->listIssues($projectId, $params);
    }

    public function getIssue(int $projectId, int $issueIid): array
    {
        return $this->issue->getIssue($projectId, $issueIid);
    }

    public function createIssue(int $projectId, array $data): array
    {
        return $this->issue->createIssue($projectId, $data);
    }

    public function createIssueNote(int $projectId, int $issueIid, string $body): array
    {
        return $this->issue->createIssueNote($projectId, $issueIid, $body);
    }

    public function listMergeRequests(int $projectId, array $params = []): array
    {
        return $this->mergeRequest->listMergeRequests($projectId, $params);
    }

    public function getMergeRequest(int $projectId, int $mrIid): array
    {
        return $this->mergeRequest->getMergeRequest($projectId, $mrIid);
    }

    public function getMergeRequestChanges(int $projectId, int $mrIid): array
    {
        return $this->mergeRequest->getMergeRequestChanges($projectId, $mrIid);
    }

    public function createMergeRequest(int $projectId, array $data): array
    {
        return $this->mergeRequest->createMergeRequest($projectId, $data);
    }

    public function updateMergeRequest(int $projectId, int $mrIid, array $data): array
    {
        return $this->mergeRequest->updateMergeRequest($projectId, $mrIid, $data);
    }

    public function findOpenMergeRequestForBranch(int $projectId, string $sourceBranch): ?array
    {
        return $this->mergeRequest->findOpenMergeRequestForBranch($projectId, $sourceBranch);
    }

    public function createMergeRequestNote(int $projectId, int $mrIid, string $body): array
    {
        return $this->mergeRequest->createMergeRequestNote($projectId, $mrIid, $body);
    }

    public function updateMergeRequestNote(int $projectId, int $mrIid, int $noteId, string $body): array
    {
        return $this->mergeRequest->updateMergeRequestNote($projectId, $mrIid, $noteId, $body);
    }

    public function listMergeRequestDiscussions(int $projectId, int $mrIid, array $params = []): array
    {
        return $this->mergeRequest->listMergeRequestDiscussions($projectId, $mrIid, $params);
    }

    public function createMergeRequestDiscussion(int $projectId, int $mrIid, string $body, array $position = []): array
    {
        return $this->mergeRequest->createMergeRequestDiscussion($projectId, $mrIid, $body, $position);
    }

    public function listPipelines(int $projectId, array $params = []): array
    {
        return $this->pipeline->listPipelines($projectId, $params);
    }

    public function createPipelineTrigger(int $projectId, string $description): array
    {
        return $this->pipeline->createPipelineTrigger($projectId, $description);
    }

    public function triggerPipeline(int $projectId, string $ref, string $triggerToken, array $variables = []): array
    {
        return $this->pipeline->triggerPipeline($projectId, $ref, $triggerToken, $variables);
    }

    public function cancelPipeline(int $projectId, int $pipelineId): void
    {
        $this->pipeline->cancelPipeline($projectId, $pipelineId);
    }
}

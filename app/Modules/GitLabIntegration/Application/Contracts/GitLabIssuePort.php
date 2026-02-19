<?php

namespace App\Modules\GitLabIntegration\Application\Contracts;

interface GitLabIssuePort
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     */
    public function listIssues(int $projectId, array $params = []): array;

    /**
     * @return array<string, mixed>
     */
    public function getIssue(int $projectId, int $issueIid): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createIssue(int $projectId, array $data): array;

    /**
     * @return array<string, mixed>
     */
    public function createIssueNote(int $projectId, int $issueIid, string $body): array;
}

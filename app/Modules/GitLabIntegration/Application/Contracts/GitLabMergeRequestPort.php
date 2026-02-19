<?php

namespace App\Modules\GitLabIntegration\Application\Contracts;

interface GitLabMergeRequestPort
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     */
    public function listMergeRequests(int $projectId, array $params = []): array;

    /**
     * @return array<string, mixed>
     */
    public function getMergeRequest(int $projectId, int $mrIid): array;

    /**
     * @return array<string, mixed>
     */
    public function getMergeRequestChanges(int $projectId, int $mrIid): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createMergeRequest(int $projectId, array $data): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateMergeRequest(int $projectId, int $mrIid, array $data): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findOpenMergeRequestForBranch(int $projectId, string $sourceBranch): ?array;

    /**
     * @return array<string, mixed>
     */
    public function createMergeRequestNote(int $projectId, int $mrIid, string $body): array;

    /**
     * @return array<string, mixed>
     */
    public function updateMergeRequestNote(int $projectId, int $mrIid, int $noteId, string $body): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     */
    public function listMergeRequestDiscussions(int $projectId, int $mrIid, array $params = []): array;

    /**
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>
     */
    public function createMergeRequestDiscussion(int $projectId, int $mrIid, string $body, array $position = []): array;
}

<?php

namespace App\Modules\GitLabIntegration\Infrastructure\Adapters;

use App\Modules\GitLabIntegration\Application\Contracts\GitLabRepoPort;
use App\Services\GitLabClient;

class GitLabRepoAdapter implements GitLabRepoPort
{
    public function __construct(
        private readonly GitLabClient $client,
    ) {}

    public function getFile(int $projectId, string $filePath, string $ref = 'main'): array
    {
        return $this->client->getFile($projectId, $filePath, $ref);
    }

    public function listTree(int $projectId, string $path = '', string $ref = 'main', bool $recursive = false): array
    {
        return $this->client->listTree($projectId, $path, $ref, $recursive);
    }

    public function searchCode(int $projectId, string $query): array
    {
        return $this->client->searchCode($projectId, $query);
    }
}

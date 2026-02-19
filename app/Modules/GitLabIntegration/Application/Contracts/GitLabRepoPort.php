<?php

namespace App\Modules\GitLabIntegration\Application\Contracts;

interface GitLabRepoPort
{
    /**
     * @return array<string, mixed>
     */
    public function getFile(int $projectId, string $filePath, string $ref = 'main'): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTree(int $projectId, string $path = '', string $ref = 'main', bool $recursive = false): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchCode(int $projectId, string $query): array;
}

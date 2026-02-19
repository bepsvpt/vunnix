<?php

namespace App\Modules\GitLabIntegration\Infrastructure\Adapters;

use App\Modules\GitLabIntegration\Application\Contracts\GitLabPipelinePort;
use App\Services\GitLabClient;

class GitLabPipelineAdapter implements GitLabPipelinePort
{
    public function __construct(
        private readonly GitLabClient $client,
    ) {}

    public function listPipelines(int $projectId, array $params = []): array
    {
        return $this->client->listPipelines($projectId, $params);
    }

    public function createPipelineTrigger(int $projectId, string $description): array
    {
        return $this->client->createPipelineTrigger($projectId, $description);
    }

    public function triggerPipeline(int $projectId, string $ref, string $triggerToken, array $variables = []): array
    {
        return $this->client->triggerPipeline($projectId, $ref, $triggerToken, $variables);
    }

    public function cancelPipeline(int $projectId, int $pipelineId): void
    {
        $this->client->cancelPipeline($projectId, $pipelineId);
    }
}

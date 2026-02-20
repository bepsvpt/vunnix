<?php

namespace App\Modules\Shared\Infrastructure\Adapters;

use App\Modules\Shared\Application\Contracts\PipelineExecutorPort;
use App\Services\GitLabClient;

class PipelineExecutorAdapter implements PipelineExecutorPort
{
    public function __construct(
        private readonly GitLabClient $gitLabClient,
    ) {}

    public function triggerPipeline(int $projectId, string $ref, string $triggerToken, array $variables = []): array
    {
        return $this->gitLabClient->triggerPipeline(
            projectId: $projectId,
            ref: $ref,
            triggerToken: $triggerToken,
            variables: $variables,
        );
    }

    public function listPipelines(int $projectId, array $params = []): array
    {
        return $this->gitLabClient->listPipelines($projectId, $params);
    }

    public function cancelPipeline(int $projectId, int $pipelineId): void
    {
        $this->gitLabClient->cancelPipeline($projectId, $pipelineId);
    }
}

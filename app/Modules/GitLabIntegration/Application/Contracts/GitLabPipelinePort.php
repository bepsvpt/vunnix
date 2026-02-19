<?php

namespace App\Modules\GitLabIntegration\Application\Contracts;

interface GitLabPipelinePort
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     */
    public function listPipelines(int $projectId, array $params = []): array;

    /**
     * @return array<string, mixed>
     */
    public function createPipelineTrigger(int $projectId, string $description): array;

    /**
     * @param  array<string, string>  $variables
     * @return array<string, mixed>
     */
    public function triggerPipeline(int $projectId, string $ref, string $triggerToken, array $variables = []): array;

    public function cancelPipeline(int $projectId, int $pipelineId): void;
}

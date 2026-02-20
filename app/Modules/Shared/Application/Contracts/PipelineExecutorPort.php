<?php

namespace App\Modules\Shared\Application\Contracts;

interface PipelineExecutorPort
{
    /**
     * @param  array<string, string>  $variables
     * @return array<string, mixed>
     */
    public function triggerPipeline(int $projectId, string $ref, string $triggerToken, array $variables = []): array;

    /**
     * @param  array<string, scalar|array<int|string, scalar>>  $params
     * @return array<int, array<string, mixed>>
     */
    public function listPipelines(int $projectId, array $params = []): array;

    public function cancelPipeline(int $projectId, int $pipelineId): void;
}

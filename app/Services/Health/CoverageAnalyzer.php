<?php

namespace App\Services\Health;

use App\Contracts\HealthAnalyzerContract;
use App\DTOs\HealthAnalysisResult;
use App\Enums\HealthDimension;
use App\Models\HealthSnapshot;
use App\Models\Project;
use App\Services\GitLabClient;
use Throwable;

class CoverageAnalyzer implements HealthAnalyzerContract
{
    public function __construct(
        private readonly GitLabClient $gitLab,
    ) {}

    public function dimension(): HealthDimension
    {
        return HealthDimension::Coverage;
    }

    public function analyze(Project $project): ?HealthAnalysisResult
    {
        $defaultBranch = $this->resolveDefaultBranch($project->gitlab_project_id);
        $pipelines = $this->gitLab->listPipelines($project->gitlab_project_id, [
            'ref' => $defaultBranch,
            'status' => 'success',
            'per_page' => 1,
        ]);

        $pipeline = $pipelines[0] ?? null;
        if (! is_array($pipeline)) {
            return null;
        }

        $coverageRaw = $pipeline['coverage'] ?? null;
        if ($coverageRaw === null || $coverageRaw === '' || ! is_numeric((string) $coverageRaw)) {
            return null;
        }

        $coverage = (float) $coverageRaw;
        $previous = HealthSnapshot::query()
            ->forProject($project->id)
            ->ofDimension($this->dimension()->value)
            ->orderByDesc('created_at')
            ->first();

        $comparedToPrevious = $previous instanceof HealthSnapshot
            ? round($coverage - $previous->score, 2)
            : null;

        return new HealthAnalysisResult(
            dimension: $this->dimension(),
            score: $coverage,
            details: [
                'coverage_percent' => $coverage,
                'pipeline_id' => $pipeline['id'] ?? null,
                'pipeline_url' => $pipeline['web_url'] ?? null,
                'compared_to_previous' => $comparedToPrevious,
            ],
            sourceRef: isset($pipeline['id']) ? (string) $pipeline['id'] : null,
        );
    }

    private function resolveDefaultBranch(int $gitlabProjectId): string
    {
        try {
            $project = $this->gitLab->getProject($gitlabProjectId);
            $defaultBranch = $project['default_branch'] ?? null;
            if (is_string($defaultBranch) && $defaultBranch !== '') {
                return $defaultBranch;
            }
        } catch (Throwable) {
            // Fallback to main when project metadata cannot be loaded.
        }

        return 'main';
    }
}

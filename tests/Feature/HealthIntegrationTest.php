<?php

use App\Contracts\HealthAnalyzerContract;
use App\DTOs\HealthAnalysisResult;
use App\Enums\HealthDimension;
use App\Models\AlertEvent;
use App\Models\HealthSnapshot;
use App\Models\MemoryEntry;
use App\Models\Project;
use App\Services\GitLabClient;
use App\Services\Health\HealthAlertService;
use App\Services\Health\HealthAnalysisService;
use App\Services\MemoryInjectionService;
use App\Services\ProjectConfigService;
use App\Services\ProjectMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'health.enabled' => true,
        'vunnix.memory.enabled' => true,
        'vunnix.memory.review_learning' => true,
    ]);
});

/**
 * @param  array<array-key, mixed>  $details
 */
function staticAnalyzer(HealthDimension $dimension, float $score, array $details): HealthAnalyzerContract
{
    return new class($dimension, $score, $details) implements HealthAnalyzerContract
    {
        /**
         * @param  array<array-key, mixed>  $details
         */
        public function __construct(
            private readonly HealthDimension $dimensionValue,
            private readonly float $score,
            private readonly array $details,
        ) {}

        public function dimension(): HealthDimension
        {
            return $this->dimensionValue;
        }

        public function analyze(Project $project): \App\DTOs\HealthAnalysisResult
        {
            return new HealthAnalysisResult(
                dimension: $this->dimensionValue,
                score: $this->score,
                details: $this->details,
                sourceRef: 'integration',
            );
        }
    };
}

it('creates snapshots, alerts, memory signals, and injects health guidance', function (): void {
    $project = Project::factory()->enabled()->create(['gitlab_project_id' => 777]);

    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldReceive('createIssue')->twice()->andReturn(
        ['iid' => 1, 'web_url' => 'https://gitlab.example.com/issues/1'],
        ['iid' => 2, 'web_url' => 'https://gitlab.example.com/issues/2'],
    );

    $alertService = new HealthAlertService(app(ProjectConfigService::class), $gitLab);
    $analysisService = new HealthAnalysisService(
        analyzers: [
            staticAnalyzer(HealthDimension::Coverage, 65.0, ['coverage_percent' => 65.0, 'compared_to_previous' => -8]),
            staticAnalyzer(HealthDimension::Dependency, 85.0, ['total_count' => 2, 'php_vulnerabilities' => [['severity' => 'high']]]),
            staticAnalyzer(HealthDimension::Complexity, 78.0, ['files_analyzed' => 15]),
        ],
        alertService: $alertService,
        projectConfigService: app(ProjectConfigService::class),
        projectMemoryService: app(ProjectMemoryService::class),
    );

    $analysisService->analyzeProject($project);

    expect(HealthSnapshot::query()->where('project_id', $project->id)->count())->toBe(3);
    expect(AlertEvent::query()->where('status', 'active')->count())->toBe(2);
    expect(MemoryEntry::query()->where('project_id', $project->id)->where('type', 'health_signal')->count())->toBeGreaterThan(0);

    $guidance = app(MemoryInjectionService::class)->buildReviewGuidance($project);

    expect($guidance)->toContain('Test coverage');
    expect(strtolower($guidance))->toContain('vulnerabilities');
});

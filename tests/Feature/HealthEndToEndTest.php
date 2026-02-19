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
        'health.snapshot_retention_days' => 180,
    ]);
});

/**
 * @param  array<array-key, mixed>  $details
 */
function e2eAnalyzer(HealthDimension $dimension, float $score, array $details): HealthAnalyzerContract
{
    return new class($dimension, $score, $details) implements HealthAnalyzerContract
    {
        public function __construct(
            private readonly HealthDimension $dimensionValue,
            private readonly float $scoreValue,
            private readonly array $detailsValue,
        ) {}

        public function dimension(): HealthDimension
        {
            return $this->dimensionValue;
        }

        public function analyze(Project $project): \App\DTOs\HealthAnalysisResult
        {
            return new HealthAnalysisResult(
                dimension: $this->dimensionValue,
                score: $this->scoreValue,
                details: $this->detailsValue,
                sourceRef: 'e2e-ref',
            );
        }
    };
}

it('executes the complete health intelligence loop', function (): void {
    $project = Project::factory()->enabled()->create(['gitlab_project_id' => 333]);

    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldReceive('createIssue')->twice()->andReturn(
        ['iid' => 20, 'web_url' => 'https://gitlab.example.com/issues/20'],
        ['iid' => 21, 'web_url' => 'https://gitlab.example.com/issues/21'],
    );

    $analysisService = new HealthAnalysisService(
        analyzers: [
            e2eAnalyzer(HealthDimension::Coverage, 65.0, ['coverage_percent' => 65.0, 'compared_to_previous' => -5]),
            e2eAnalyzer(HealthDimension::Dependency, 88.0, ['total_count' => 1, 'php_vulnerabilities' => [['severity' => 'critical']]]),
            e2eAnalyzer(HealthDimension::Complexity, 82.0, ['files_analyzed' => 10]),
        ],
        alertService: new HealthAlertService(app(ProjectConfigService::class), $gitLab),
        projectConfigService: app(ProjectConfigService::class),
        projectMemoryService: app(ProjectMemoryService::class),
    );

    $analysisService->analyzeProject($project);

    expect(HealthSnapshot::query()->where('project_id', $project->id)->count())->toBe(3);
    expect(AlertEvent::query()->where('alert_type', 'health_coverage_decline')->exists())->toBeTrue();
    expect(AlertEvent::query()->where('alert_type', 'health_vulnerability_found')->exists())->toBeTrue();
    expect(MemoryEntry::query()->where('project_id', $project->id)->where('type', 'health_signal')->count())->toBeGreaterThan(0);

    $guidance = app(MemoryInjectionService::class)->buildReviewGuidance($project);
    expect($guidance)->toContain('Test coverage');
    expect(strtolower($guidance))->toContain('vulnerabilities');

    $old = HealthSnapshot::factory()->create([
        'project_id' => $project->id,
        'dimension' => 'coverage',
        'score' => 10,
        'created_at' => now()->subDays(30),
    ]);

    config(['health.snapshot_retention_days' => 0]);
    $before = HealthSnapshot::query()->count();

    $this->artisan('health:clean-snapshots')
        ->assertSuccessful();

    expect(HealthSnapshot::query()->count())->toBeLessThan($before);
    expect(HealthSnapshot::query()->whereKey($old->id)->exists())->toBeFalse();
});

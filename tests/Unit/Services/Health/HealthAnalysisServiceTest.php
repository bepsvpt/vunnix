<?php

use App\Contracts\HealthAnalyzerContract;
use App\DTOs\HealthAnalysisResult;
use App\Enums\HealthDimension;
use App\Models\HealthSnapshot;
use App\Models\Project;
use App\Services\Health\HealthAlertService;
use App\Services\Health\HealthAnalysisService;
use App\Services\ProjectConfigService;
use App\Services\ProjectMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'health.enabled' => true,
        'health.coverage_tracking' => true,
        'health.dependency_scanning' => true,
        'health.complexity_tracking' => true,
        'vunnix.memory.enabled' => true,
    ]);
});

/**
 * @param  array<array-key, mixed>  $details
 */
function makeAnalyzer(HealthDimension $dimension, ?float $score, array $details = []): HealthAnalyzerContract
{
    return new class($dimension, $score, $details) implements HealthAnalyzerContract
    {
        /**
         * @param  array<array-key, mixed>  $details
         */
        public function __construct(
            private readonly HealthDimension $dimensionValue,
            private readonly ?float $score,
            private readonly array $details,
        ) {}

        public function dimension(): HealthDimension
        {
            return $this->dimensionValue;
        }

        public function analyze(Project $project): ?HealthAnalysisResult
        {
            if ($this->score === null) {
                return null;
            }

            return new HealthAnalysisResult(
                dimension: $this->dimensionValue,
                score: $this->score,
                details: $this->details,
                sourceRef: 'test-ref',
            );
        }
    };
}

it('creates snapshots for all analyzers that return data', function (): void {
    $project = Project::factory()->create();
    $analyzers = [
        makeAnalyzer(HealthDimension::Coverage, 91.0, ['coverage_percent' => 91]),
        makeAnalyzer(HealthDimension::Dependency, 95.0, ['total_count' => 0]),
        makeAnalyzer(HealthDimension::Complexity, 83.0, ['files_analyzed' => 8]),
    ];

    $alertService = Mockery::mock(HealthAlertService::class);
    $alertService->shouldReceive('evaluateThresholds')
        ->once()
        ->withArgs(fn (Project $arg, Collection $snapshots): bool => $arg->is($project) && $snapshots->count() === 3);

    $service = new HealthAnalysisService(
        analyzers: $analyzers,
        alertService: $alertService,
        projectConfigService: app(ProjectConfigService::class),
        projectMemoryService: app(ProjectMemoryService::class),
    );

    $snapshots = $service->analyzeProject($project);

    expect($snapshots)->toHaveCount(3);
    expect(HealthSnapshot::query()->where('project_id', $project->id)->count())->toBe(3);
});

it('skips analyzers that return null', function (): void {
    $project = Project::factory()->create();
    $analyzers = [
        makeAnalyzer(HealthDimension::Coverage, 88.0, ['coverage_percent' => 88]),
        makeAnalyzer(HealthDimension::Dependency, null),
        makeAnalyzer(HealthDimension::Complexity, 79.0, ['files_analyzed' => 5]),
    ];

    $alertService = Mockery::mock(HealthAlertService::class);
    $alertService->shouldReceive('evaluateThresholds')
        ->once()
        ->withArgs(fn (Project $arg, Collection $snapshots): bool => $arg->is($project) && $snapshots->count() === 2);

    $service = new HealthAnalysisService(
        analyzers: $analyzers,
        alertService: $alertService,
        projectConfigService: app(ProjectConfigService::class),
        projectMemoryService: app(ProjectMemoryService::class),
    );

    $snapshots = $service->analyzeProject($project);

    expect($snapshots)->toHaveCount(2);
});

it('does not call disabled dimension analyzer', function (): void {
    $project = Project::factory()->create();
    $project->projectConfig()->create([
        'settings' => [
            'health' => [
                'coverage_tracking' => false,
            ],
        ],
    ]);

    $coverageAnalyzer = Mockery::mock(HealthAnalyzerContract::class);
    $coverageAnalyzer->shouldReceive('dimension')->andReturn(HealthDimension::Coverage);
    $coverageAnalyzer->shouldNotReceive('analyze');

    $dependencyAnalyzer = Mockery::mock(HealthAnalyzerContract::class);
    $dependencyAnalyzer->shouldReceive('dimension')->andReturn(HealthDimension::Dependency);
    $dependencyAnalyzer->shouldReceive('analyze')->once()->andReturn(new HealthAnalysisResult(
        dimension: HealthDimension::Dependency,
        score: 92.0,
        details: ['total_count' => 0],
        sourceRef: 'test-ref',
    ));

    $alertService = Mockery::mock(HealthAlertService::class);
    $alertService->shouldReceive('evaluateThresholds')
        ->once()
        ->withArgs(fn (Project $arg, Collection $snapshots): bool => $arg->is($project) && $snapshots->count() === 1);

    $service = new HealthAnalysisService(
        analyzers: [$coverageAnalyzer, $dependencyAnalyzer],
        alertService: $alertService,
        projectConfigService: app(ProjectConfigService::class),
        projectMemoryService: app(ProjectMemoryService::class),
    );

    $snapshots = $service->analyzeProject($project);

    expect($snapshots)->toHaveCount(1);
});

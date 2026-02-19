<?php

use App\Contracts\HealthAnalyzerContract;
use App\DTOs\HealthAnalysisResult;
use App\Enums\HealthDimension;
use App\Jobs\AnalyzeProjectHealth;
use App\Jobs\Middleware\RetryWithBackoff;
use App\Models\HealthSnapshot;
use App\Models\Project;
use App\Services\Health\HealthAlertService;
use App\Services\Health\HealthAnalysisService;
use App\Services\ProjectConfigService;
use App\Services\ProjectMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'health.enabled' => true,
        'vunnix.memory.enabled' => true,
    ]);
});

function fakeCoverageAnalyzer(float $score): HealthAnalyzerContract
{
    return new class($score) implements HealthAnalyzerContract
    {
        public function __construct(private readonly float $score) {}

        public function dimension(): HealthDimension
        {
            return HealthDimension::Coverage;
        }

        public function analyze(Project $project): \App\DTOs\HealthAnalysisResult
        {
            return new HealthAnalysisResult(
                dimension: HealthDimension::Coverage,
                score: $this->score,
                details: ['coverage_percent' => $this->score],
                sourceRef: 'pipeline-1',
            );
        }
    };
}

it('creates health snapshots when job runs successfully', function (): void {
    $project = Project::factory()->enabled()->create();

    $alertService = Mockery::mock(HealthAlertService::class);
    $alertService->shouldReceive('evaluateThresholds')->once();

    $analysis = new HealthAnalysisService(
        analyzers: [fakeCoverageAnalyzer(82.0)],
        alertService: $alertService,
        projectConfigService: app(ProjectConfigService::class),
        projectMemoryService: app(ProjectMemoryService::class),
    );

    app()->instance(HealthAnalysisService::class, $analysis);

    $job = new AnalyzeProjectHealth($project->id);
    $job->handle(app(HealthAnalysisService::class));

    expect(HealthSnapshot::query()->where('project_id', $project->id)->count())->toBe(1);
});

it('logs and rethrows when analysis fails', function (): void {
    $project = Project::factory()->enabled()->create();

    $analysis = Mockery::mock(HealthAnalysisService::class);
    $analysis->shouldReceive('analyzeProject')
        ->once()
        ->andThrow(new RuntimeException('analysis failure'));
    app()->instance(HealthAnalysisService::class, $analysis);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'AnalyzeProjectHealth: analysis failed'
            && $context['project_id'] === $project->id);

    $job = new AnalyzeProjectHealth($project->id);

    expect(fn () => $job->handle(app(HealthAnalysisService::class)))
        ->toThrow(RuntimeException::class);
});

it('logs and returns when project does not exist', function (): void {
    $analysis = Mockery::mock(HealthAnalysisService::class);
    $analysis->shouldNotReceive('analyzeProject');
    app()->instance(HealthAnalysisService::class, $analysis);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'AnalyzeProjectHealth: project not found'
            && $context['project_id'] === 987654);

    $job = new AnalyzeProjectHealth(987654);
    $job->handle(app(HealthAnalysisService::class));

    expect(true)->toBeTrue();
});

it('registers retry middleware', function (): void {
    $job = new AnalyzeProjectHealth(1);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(RetryWithBackoff::class);
});

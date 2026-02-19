<?php

use App\Models\HealthSnapshot;
use App\Models\Project;
use App\Services\GitLabClient;
use App\Services\Health\CoverageAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('returns analysis result when pipeline has coverage', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 123]);
    HealthSnapshot::factory()->create([
        'project_id' => $project->id,
        'dimension' => 'coverage',
        'score' => 80.0,
    ]);

    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldReceive('getProject')
        ->once()
        ->with(123)
        ->andReturn(['default_branch' => 'main']);
    $gitLab->shouldReceive('listPipelines')
        ->once()
        ->with(123, [
            'ref' => 'main',
            'status' => 'success',
            'per_page' => 1,
        ])
        ->andReturn([[
            'id' => 456,
            'coverage' => '75.5',
            'web_url' => 'https://gitlab.example.com/pipelines/456',
        ]]);

    $analyzer = new CoverageAnalyzer($gitLab);
    $result = $analyzer->analyze($project);

    expect($result)->not->toBeNull();
    expect($result?->score)->toBe(75.5);
    expect($result?->details['pipeline_id'])->toBe(456);
    expect($result?->details['compared_to_previous'])->toBe(-4.5);
});

it('returns null when coverage is not configured in pipeline', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 123]);

    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldReceive('getProject')
        ->once()
        ->with(123)
        ->andReturn(['default_branch' => 'main']);
    $gitLab->shouldReceive('listPipelines')
        ->once()
        ->andReturn([[
            'id' => 456,
            'coverage' => null,
        ]]);

    $analyzer = new CoverageAnalyzer($gitLab);
    $result = $analyzer->analyze($project);

    expect($result)->toBeNull();
});

it('returns null when pipeline list is empty', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 123]);

    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldReceive('getProject')
        ->once()
        ->with(123)
        ->andReturn(['default_branch' => 'main']);
    $gitLab->shouldReceive('listPipelines')
        ->once()
        ->with(123, [
            'ref' => 'main',
            'status' => 'success',
            'per_page' => 1,
        ])
        ->andReturn([]);

    $analyzer = new CoverageAnalyzer($gitLab);
    $result = $analyzer->analyze($project);

    expect($result)->toBeNull();
});

it('returns null when coverage is non numeric', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 123]);

    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldReceive('getProject')
        ->once()
        ->with(123)
        ->andReturn(['default_branch' => 'main']);
    $gitLab->shouldReceive('listPipelines')
        ->once()
        ->with(123, [
            'ref' => 'main',
            'status' => 'success',
            'per_page' => 1,
        ])
        ->andReturn([[
            'id' => 456,
            'coverage' => 'n/a',
        ]]);

    $analyzer = new CoverageAnalyzer($gitLab);
    $result = $analyzer->analyze($project);

    expect($result)->toBeNull();
});

it('falls back to main branch when project metadata lookup fails', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 123]);

    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldReceive('getProject')
        ->once()
        ->with(123)
        ->andThrow(new RuntimeException('metadata failed'));
    $gitLab->shouldReceive('listPipelines')
        ->once()
        ->with(123, [
            'ref' => 'main',
            'status' => 'success',
            'per_page' => 1,
        ])
        ->andReturn([[
            'id' => 456,
            'coverage' => '88.0',
        ]]);

    $analyzer = new CoverageAnalyzer($gitLab);
    $result = $analyzer->analyze($project);

    expect($result)->not->toBeNull()
        ->and($result?->sourceRef)->toBe('456')
        ->and($result?->score)->toBe(88.0);
});

<?php

use App\Models\Project;
use App\Services\GitLabClient;
use App\Services\Health\DependencyAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('returns vulnerability analysis from composer lock and advisories api', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 55]);
    $composerLock = json_encode([
        'packages' => [
            ['name' => 'symfony/http-foundation', 'version' => 'v6.4.0'],
        ],
        'packages-dev' => [],
    ], JSON_THROW_ON_ERROR);

    Http::fake([
        'packagist.org/api/security-advisories/*' => Http::response([
            'advisories' => [
                'symfony/http-foundation' => [
                    ['title' => 'Known issue', 'severity' => 'high', 'cve' => 'CVE-2026-1234'],
                ],
            ],
        ], 200),
    ]);

    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldReceive('getProject')->once()->with(55)->andReturn(['default_branch' => 'main']);
    $gitLab->shouldReceive('getFile')->once()->with(55, 'composer.lock', 'main')->andReturn([
        'content' => base64_encode($composerLock),
        'encoding' => 'base64',
    ]);
    $gitLab->shouldReceive('getFile')->once()->with(55, 'package-lock.json', 'main')->andThrow(new RuntimeException('not found'));

    $analyzer = new DependencyAnalyzer($gitLab);
    $result = $analyzer->analyze($project);

    expect($result)->not->toBeNull();
    expect($result?->details['total_count'])->toBe(1);
    expect($result?->details['php_vulnerabilities'][0]['package'])->toBe('symfony/http-foundation');
    expect($result?->score)->toBe(85.0);
});

it('returns null when no lock files are available', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 55]);

    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldReceive('getProject')->once()->with(55)->andReturn(['default_branch' => 'main']);
    $gitLab->shouldReceive('getFile')->once()->with(55, 'composer.lock', 'main')->andThrow(new RuntimeException('not found'));
    $gitLab->shouldReceive('getFile')->once()->with(55, 'package-lock.json', 'main')->andThrow(new RuntimeException('not found'));

    $analyzer = new DependencyAnalyzer($gitLab);
    $result = $analyzer->analyze($project);

    expect($result)->toBeNull();
});

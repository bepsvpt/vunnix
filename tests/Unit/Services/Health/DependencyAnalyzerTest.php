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

it('falls back to main branch and handles raw lock content', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 55]);
    $composerLock = json_encode([
        'packages' => [
            ['name' => 'laravel/framework'],
            'invalid-item',
        ],
        'packages-dev' => 'invalid-group',
    ], JSON_THROW_ON_ERROR);
    $packageLock = json_encode([
        'dependencies' => [
            ['name' => 'vue'],
        ],
    ], JSON_THROW_ON_ERROR);

    Http::fake([
        'packagist.org/api/security-advisories/*' => Http::response([], 500),
    ]);

    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldReceive('getProject')->once()->with(55)->andThrow(new RuntimeException('metadata failed'));
    $gitLab->shouldReceive('getFile')->once()->with(55, 'composer.lock', 'main')->andReturn([
        'content' => $composerLock,
        'encoding' => 'text',
    ]);
    $gitLab->shouldReceive('getFile')->once()->with(55, 'package-lock.json', 'main')->andReturn([
        'content' => $packageLock,
        'encoding' => 'text',
    ]);

    $analyzer = new DependencyAnalyzer($gitLab);
    $result = $analyzer->analyze($project);

    expect($result)->not->toBeNull()
        ->and($result?->sourceRef)->toBe('main')
        ->and($result?->details['packages_scanned'])->toBe(2)
        ->and($result?->details['total_count'])->toBe(0)
        ->and($result?->score)->toBe(100.0);
});

it('ignores malformed advisories and extracts CVE from identifiers', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 55]);
    $composerLock = json_encode([
        'packages' => [
            ['name' => 'pkg/valid'],
        ],
    ], JSON_THROW_ON_ERROR);

    Http::fake([
        'packagist.org/api/security-advisories/*' => Http::response([
            'advisories' => [
                'pkg/not-array' => 'invalid',
                'pkg/valid' => [
                    'invalid-item',
                    [
                        'title' => 'Critical issue',
                        'cvss_severity' => 'critical',
                        'identifiers' => ['GHSA-1', 'CVE-2026-0001'],
                    ],
                    [
                        'title' => 'Medium issue',
                        'severity' => 'medium',
                        'cve' => '',
                    ],
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
    $gitLab->shouldReceive('getFile')->once()->with(55, 'package-lock.json', 'main')->andReturn([
        'content' => '',
        'encoding' => 'base64',
    ]);

    $analyzer = new DependencyAnalyzer($gitLab);
    $result = $analyzer->analyze($project);

    expect($result)->not->toBeNull()
        ->and($result?->details['total_count'])->toBe(2)
        ->and($result?->details['php_vulnerabilities'][0]['cve'])->toBe('CVE-2026-0001')
        ->and($result?->details['php_vulnerabilities'][1]['cve'])->toBeNull()
        ->and($result?->score)->toBe(70.0);
});

it('returns no vulnerabilities when advisories payload is not an array', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 55]);
    $composerLock = json_encode([
        'packages' => [
            ['name' => 'pkg/one'],
        ],
    ], JSON_THROW_ON_ERROR);

    Http::fake([
        'packagist.org/api/security-advisories/*' => Http::response('"malformed"', 200),
    ]);

    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldReceive('getProject')->once()->with(55)->andReturn(['default_branch' => 'main']);
    $gitLab->shouldReceive('getFile')->once()->with(55, 'composer.lock', 'main')->andReturn([
        'content' => base64_encode($composerLock),
        'encoding' => 'base64',
    ]);
    $gitLab->shouldReceive('getFile')->once()->with(55, 'package-lock.json', 'main')->andThrow(new RuntimeException('missing'));

    $analyzer = new DependencyAnalyzer($gitLab);
    $result = $analyzer->analyze($project);

    expect($result)->not->toBeNull()
        ->and($result?->details['total_count'])->toBe(0)
        ->and($result?->score)->toBe(100.0);
});

it('returns no vulnerabilities when advisories field is malformed', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 55]);
    $composerLock = json_encode([
        'packages' => [
            ['name' => 'pkg/one'],
        ],
    ], JSON_THROW_ON_ERROR);

    Http::fake([
        'packagist.org/api/security-advisories/*' => Http::response([
            'advisories' => 'invalid',
        ], 200),
    ]);

    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldReceive('getProject')->once()->with(55)->andReturn(['default_branch' => 'main']);
    $gitLab->shouldReceive('getFile')->once()->with(55, 'composer.lock', 'main')->andReturn([
        'content' => base64_encode($composerLock),
        'encoding' => 'base64',
    ]);
    $gitLab->shouldReceive('getFile')->once()->with(55, 'package-lock.json', 'main')->andThrow(new RuntimeException('missing'));

    $analyzer = new DependencyAnalyzer($gitLab);
    $result = $analyzer->analyze($project);

    expect($result)->not->toBeNull()
        ->and($result?->details['total_count'])->toBe(0);
});

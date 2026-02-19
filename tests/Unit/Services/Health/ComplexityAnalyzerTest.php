<?php

use App\Models\Project;
use App\Services\GitLabClient;
use App\Services\Health\ComplexityAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('identifies complexity hotspots from file heuristics', function (): void {
    $project = Project::factory()->create(['gitlab_project_id' => 99]);

    $largeContent = implode("\n", array_merge(
        array_fill(0, 360, '$x = 1;'),
        array_map(static fn (int $i): string => "function fn{$i}() {}", range(1, 25)),
    ));

    $smallContent = implode("\n", array_fill(0, 20, '$x = 1;'));

    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldReceive('getProject')->once()->with(99)->andReturn(['default_branch' => 'main']);
    $gitLab->shouldReceive('listTree')->andReturn(
        [
            ['type' => 'blob', 'path' => 'app/BigService.php', 'size' => 5000],
            ['type' => 'blob', 'path' => 'app/SmallService.php', 'size' => 1000],
        ],
        [],
    );
    $gitLab->shouldReceive('getFile')->with(99, 'app/BigService.php', 'main')->andReturn([
        'content' => base64_encode($largeContent),
        'encoding' => 'base64',
    ]);
    $gitLab->shouldReceive('getFile')->with(99, 'app/SmallService.php', 'main')->andReturn([
        'content' => base64_encode($smallContent),
        'encoding' => 'base64',
    ]);

    $analyzer = new ComplexityAnalyzer($gitLab);
    $result = $analyzer->analyze($project);

    expect($result)->not->toBeNull();
    expect($result?->details['files_analyzed'])->toBe(2);
    expect($result?->details['hotspot_files'])->not->toBeEmpty();
    expect($result?->details['hotspot_files'][0]['path'])->toBe('app/BigService.php');
    expect($result?->score)->toBeLessThan(100.0);
});

it('returns null when no files can be analyzed', function (): void {
    config([
        'health.analysis_directories' => ['', 'app/'],
        'health.max_file_reads' => 5,
    ]);

    $project = Project::factory()->create(['gitlab_project_id' => 99]);

    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldReceive('getProject')->once()->with(99)->andReturn(['default_branch' => 'main']);
    $gitLab->shouldReceive('listTree')
        ->once()
        ->with(99, 'app', 'main', true)
        ->andThrow(new RuntimeException('tree failed'));

    $analyzer = new ComplexityAnalyzer($gitLab);
    $result = $analyzer->analyze($project);

    expect($result)->toBeNull();
});

it('falls back to main branch and supports js arrow function detection', function (): void {
    config([
        'health.analysis_directories' => ['resources/js/'],
        'health.max_file_reads' => 5,
    ]);

    $project = Project::factory()->create(['gitlab_project_id' => 99]);
    $jsContent = implode("\n", array_merge(
        array_fill(0, 320, 'const x = 1;'),
        array_map(static fn (int $i): string => "const fn{$i} = () => {$i};", range(1, 22)),
    ));

    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldReceive('getProject')->once()->with(99)->andThrow(new RuntimeException('project failed'));
    $gitLab->shouldReceive('listTree')->once()->with(99, 'resources/js', 'main', true)->andReturn([
        ['type' => 'blob', 'path' => 'resources/js/hotspot.js', 'size' => 5000],
    ]);
    $gitLab->shouldReceive('getFile')->once()->with(99, 'resources/js/hotspot.js', 'main')->andReturn([
        'content' => $jsContent,
        'encoding' => 'text',
    ]);

    $analyzer = new ComplexityAnalyzer($gitLab);
    $result = $analyzer->analyze($project);

    expect($result)->not->toBeNull()
        ->and($result?->sourceRef)->toBe('main')
        ->and($result?->details['files_analyzed'])->toBe(1)
        ->and($result?->details['hotspot_files'])->toHaveCount(1)
        ->and($result?->score)->toBe(94.0);
});

it('skips files with unreadable or empty content', function (): void {
    config([
        'health.analysis_directories' => ['app/'],
        'health.max_file_reads' => 10,
    ]);

    $project = Project::factory()->create(['gitlab_project_id' => 99]);

    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldReceive('getProject')->once()->with(99)->andReturn(['default_branch' => 'main']);
    $gitLab->shouldReceive('listTree')->once()->with(99, 'app', 'main', true)->andReturn([
        ['type' => 'blob', 'path' => 'app/Unreadable.php', 'size' => 100],
        ['type' => 'blob', 'path' => 'app/Empty.php', 'size' => 100],
        ['type' => 'blob', 'path' => 'app/Valid.php', 'size' => 100],
    ]);
    $gitLab->shouldReceive('getFile')->once()->with(99, 'app/Unreadable.php', 'main')->andThrow(new RuntimeException('read failed'));
    $gitLab->shouldReceive('getFile')->once()->with(99, 'app/Empty.php', 'main')->andReturn([
        'content' => '',
        'encoding' => 'base64',
    ]);
    $gitLab->shouldReceive('getFile')->once()->with(99, 'app/Valid.php', 'main')->andReturn([
        'content' => base64_encode("<?php\nfunction ok() {}\n"),
        'encoding' => 'base64',
    ]);

    $analyzer = new ComplexityAnalyzer($gitLab);
    $result = $analyzer->analyze($project);

    expect($result)->not->toBeNull()
        ->and($result?->details['files_analyzed'])->toBe(1)
        ->and($result?->details['hotspot_files'])->toBe([]);
});

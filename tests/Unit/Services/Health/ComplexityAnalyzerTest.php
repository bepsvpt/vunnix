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

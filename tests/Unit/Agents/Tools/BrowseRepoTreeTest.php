<?php

use App\Agents\Tools\BrowseRepoTree;
use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use App\Services\ProjectAccessChecker;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    $this->gitLab = Mockery::mock(GitLabClient::class);
    $this->accessChecker = Mockery::mock(ProjectAccessChecker::class);
    $this->accessChecker->shouldReceive('check')->andReturn(null);
    $this->tool = new BrowseRepoTree($this->gitLab, $this->accessChecker);
});

// ─── Description ────────────────────────────────────────────────

it('has a description', function (): void {
    expect($this->tool->description())->toBeString()->not->toBeEmpty();
});

// ─── Schema ─────────────────────────────────────────────────────

it('defines the expected schema parameters', function (): void {
    $schema = new JsonSchemaTypeFactory;
    $result = $this->tool->schema($schema);

    expect($result)->toHaveKeys(['project_id', 'path', 'ref', 'recursive']);
});

// ─── Handle — success ───────────────────────────────────────────

it('returns formatted file and directory list', function (): void {
    $this->gitLab
        ->shouldReceive('listTree')
        ->with(42, '', 'main', false)
        ->once()
        ->andReturn([
            ['type' => 'tree', 'path' => 'src', 'name' => 'src', 'id' => 'a1', 'mode' => '040000'],
            ['type' => 'blob', 'path' => 'README.md', 'name' => 'README.md', 'id' => 'b2', 'mode' => '100644'],
            ['type' => 'blob', 'path' => 'composer.json', 'name' => 'composer.json', 'id' => 'c3', 'mode' => '100644'],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
    ]));

    expect($result)
        ->toContain('[dir] src')
        ->toContain('[file] README.md')
        ->toContain('[file] composer.json');
});

it('passes path and ref parameters to GitLab client', function (): void {
    $this->gitLab
        ->shouldReceive('listTree')
        ->with(42, 'src/services', 'develop', false)
        ->once()
        ->andReturn([
            ['type' => 'blob', 'path' => 'src/services/AuthService.php', 'name' => 'AuthService.php', 'id' => 'x1', 'mode' => '100644'],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'path' => 'src/services',
        'ref' => 'develop',
    ]));

    expect($result)->toContain('[file] src/services/AuthService.php');
});

it('passes recursive flag to GitLab client', function (): void {
    $this->gitLab
        ->shouldReceive('listTree')
        ->with(42, '', 'main', true)
        ->once()
        ->andReturn([
            ['type' => 'tree', 'path' => 'src', 'name' => 'src', 'id' => 'a1', 'mode' => '040000'],
            ['type' => 'blob', 'path' => 'src/index.ts', 'name' => 'index.ts', 'id' => 'b1', 'mode' => '100644'],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'recursive' => true,
    ]));

    expect($result)
        ->toContain('[dir] src')
        ->toContain('[file] src/index.ts');
});

// ─── Handle — empty ─────────────────────────────────────────────

it('returns a message when no files are found', function (): void {
    $this->gitLab
        ->shouldReceive('listTree')
        ->once()
        ->andReturn([]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
    ]));

    expect($result)->toContain('No files or directories found');
});

// ─── Handle — error ─────────────────────────────────────────────

it('returns error message instead of throwing on GitLab API failure', function (): void {
    $this->gitLab
        ->shouldReceive('listTree')
        ->once()
        ->andThrow(new GitLabApiException(
            message: 'GitLab API error (listTree ): 404 {"message":"404 Project Not Found"}',
            statusCode: 404,
            responseBody: '{"message":"404 Project Not Found"}',
            context: 'listTree ',
        ));

    $result = $this->tool->handle(new Request([
        'project_id' => 999,
    ]));

    expect($result)->toContain('Error browsing repository');
});

// ─── Handle — access denied ────────────────────────────────────

it('returns rejection when access checker denies access', function (): void {
    $checker = Mockery::mock(ProjectAccessChecker::class);
    $checker->shouldReceive('check')
        ->with(999)
        ->once()
        ->andReturn('Access denied: you do not have access to this project.');

    $tool = new BrowseRepoTree($this->gitLab, $checker);

    $result = $tool->handle(new Request([
        'project_id' => 999,
    ]));

    expect($result)->toContain('Access denied');
    $this->gitLab->shouldNotHaveReceived('listTree');
});

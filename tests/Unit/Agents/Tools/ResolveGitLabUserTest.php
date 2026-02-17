<?php

use App\Agents\Tools\ResolveGitLabUser;
use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use App\Services\ProjectAccessChecker;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    $this->gitLab = Mockery::mock(GitLabClient::class);
    $this->accessChecker = Mockery::mock(ProjectAccessChecker::class);
    $this->tool = new ResolveGitLabUser($this->gitLab, $this->accessChecker);
});

afterEach(fn () => Mockery::close());

// ─── Description ────────────────────────────────────────────────

it('has a description', function (): void {
    expect($this->tool->description())->toBeString()->not->toBeEmpty();
});

// ─── Schema ─────────────────────────────────────────────────────

it('defines project_id and query as schema parameters', function (): void {
    $schema = new JsonSchemaTypeFactory;
    $params = $this->tool->schema($schema);

    expect($params)->toHaveKeys(['project_id', 'query']);
});

// ─── Handle — access denied ────────────────────────────────────

it('returns rejection when access checker denies access', function (): void {
    $this->accessChecker
        ->shouldReceive('check')
        ->with(42)
        ->andReturn('Access denied: project not registered.');

    $request = new Request([
        'project_id' => 42,
        'query' => 'john',
    ]);

    $result = $this->tool->handle($request);

    expect($result)->toContain('Access denied');
    $this->gitLab->shouldNotHaveReceived('listProjectMembers');
});

// ─── Handle — empty query ──────────────────────────────────────

it('returns error when query is empty', function (): void {
    $this->accessChecker
        ->shouldReceive('check')
        ->with(42)
        ->andReturnNull();

    $request = new Request([
        'project_id' => 42,
        'query' => '',
    ]);

    $result = $this->tool->handle($request);

    expect($result)->toContain('query parameter is required');
});

// ─── Handle — no results ───────────────────────────────────────

it('returns no-match message when no members found', function (): void {
    $this->accessChecker
        ->shouldReceive('check')
        ->with(42)
        ->andReturnNull();

    $this->gitLab
        ->shouldReceive('listProjectMembers')
        ->with(42, ['query' => 'nonexistent', 'per_page' => 10])
        ->andReturn([]);

    $request = new Request([
        'project_id' => 42,
        'query' => 'nonexistent',
    ]);

    $result = $this->tool->handle($request);

    expect($result)->toContain('No project members found matching "nonexistent"');
});

// ─── Handle — successful resolution ────────────────────────────

it('returns formatted member list on successful search', function (): void {
    $this->accessChecker
        ->shouldReceive('check')
        ->with(42)
        ->andReturnNull();

    $this->gitLab
        ->shouldReceive('listProjectMembers')
        ->with(42, ['query' => 'john', 'per_page' => 10])
        ->andReturn([
            ['id' => 15, 'username' => 'john.doe', 'name' => 'John Doe'],
            ['id' => 28, 'username' => 'johnny', 'name' => 'Johnny Smith'],
        ]);

    $request = new Request([
        'project_id' => 42,
        'query' => 'john',
    ]);

    $result = $this->tool->handle($request);

    expect($result)->toContain('Found 2 member(s)');
    expect($result)->toContain('@john.doe (ID: 15)');
    expect($result)->toContain('@johnny (ID: 28)');
});

// ─── Handle — GitLab API error ─────────────────────────────────

it('returns error message on GitLab API failure', function (): void {
    $this->accessChecker
        ->shouldReceive('check')
        ->with(42)
        ->andReturnNull();

    $this->gitLab
        ->shouldReceive('listProjectMembers')
        ->andThrow(new GitLabApiException('Connection refused', 500, '', 'listProjectMembers'));

    $request = new Request([
        'project_id' => 42,
        'query' => 'john',
    ]);

    $result = $this->tool->handle($request);

    expect($result)->toContain('Error searching project members');
    expect($result)->toContain('Connection refused');
});

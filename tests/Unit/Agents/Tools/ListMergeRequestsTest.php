<?php

use App\Agents\Tools\ListMergeRequests;
use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use App\Services\ProjectAccessChecker;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->gitLab = Mockery::mock(GitLabClient::class);
    $this->accessChecker = Mockery::mock(ProjectAccessChecker::class);
    $this->accessChecker->shouldReceive('check')->andReturn(null);
    $this->tool = new ListMergeRequests($this->gitLab, $this->accessChecker);
});

// ─── Description ────────────────────────────────────────────────

it('has a description', function () {
    expect($this->tool->description())->toBeString()->not->toBeEmpty();
});

// ─── Schema ─────────────────────────────────────────────────────

it('defines the expected schema parameters', function () {
    $schema = new JsonSchemaTypeFactory;
    $result = $this->tool->schema($schema);

    expect($result)->toHaveKeys(['project_id', 'state', 'labels', 'search', 'per_page']);
});

// ─── Handle — success ───────────────────────────────────────────

it('returns formatted merge request list', function () {
    $this->gitLab
        ->shouldReceive('listMergeRequests')
        ->with(42, [])
        ->once()
        ->andReturn([
            [
                'iid' => 1,
                'title' => 'Add authentication module',
                'state' => 'opened',
                'source_branch' => 'feature/auth',
                'target_branch' => 'main',
                'labels' => ['feature', 'backend'],
                'author' => ['username' => 'alice'],
            ],
            [
                'iid' => 2,
                'title' => 'Fix CI pipeline',
                'state' => 'merged',
                'source_branch' => 'fix/ci',
                'target_branch' => 'main',
                'labels' => [],
                'author' => null,
            ],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
    ]));

    expect($result)
        ->toContain('Found 2 merge request(s)')
        ->toContain('!1 [opened] Add authentication module (feature/auth → main) [feature, backend] (@alice)')
        ->toContain('!2 [merged] Fix CI pipeline (fix/ci → main)');
});

it('passes state filter to GitLab client', function () {
    $this->gitLab
        ->shouldReceive('listMergeRequests')
        ->with(42, ['state' => 'merged'])
        ->once()
        ->andReturn([
            [
                'iid' => 5,
                'title' => 'Completed feature',
                'state' => 'merged',
                'source_branch' => 'feature/done',
                'target_branch' => 'main',
                'labels' => [],
                'author' => ['username' => 'bob'],
            ],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'state' => 'merged',
    ]));

    expect($result)
        ->toContain('!5 [merged] Completed feature');
});

it('passes labels filter to GitLab client', function () {
    $this->gitLab
        ->shouldReceive('listMergeRequests')
        ->with(42, ['labels' => 'bug,hotfix'])
        ->once()
        ->andReturn([
            [
                'iid' => 3,
                'title' => 'Hotfix for login',
                'state' => 'opened',
                'source_branch' => 'hotfix/login',
                'target_branch' => 'main',
                'labels' => ['bug', 'hotfix'],
                'author' => ['username' => 'alice'],
            ],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'labels' => 'bug,hotfix',
    ]));

    expect($result)->toContain('!3 [opened] Hotfix for login (hotfix/login → main) [bug, hotfix]');
});

it('passes search query to GitLab client', function () {
    $this->gitLab
        ->shouldReceive('listMergeRequests')
        ->with(42, ['search' => 'refactor'])
        ->once()
        ->andReturn([
            [
                'iid' => 10,
                'title' => 'Refactor payment service',
                'state' => 'opened',
                'source_branch' => 'refactor/payment',
                'target_branch' => 'main',
                'labels' => [],
                'author' => ['username' => 'charlie'],
            ],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'search' => 'refactor',
    ]));

    expect($result)->toContain('!10 [opened] Refactor payment service');
});

it('passes per_page to GitLab client', function () {
    $this->gitLab
        ->shouldReceive('listMergeRequests')
        ->with(42, ['per_page' => 5])
        ->once()
        ->andReturn([
            [
                'iid' => 1,
                'title' => 'MR one',
                'state' => 'opened',
                'source_branch' => 'feature/one',
                'target_branch' => 'main',
                'labels' => [],
                'author' => null,
            ],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'per_page' => 5,
    ]));

    expect($result)->toContain('Found 1 merge request(s)');
});

it('combines multiple filters', function () {
    $this->gitLab
        ->shouldReceive('listMergeRequests')
        ->with(42, ['state' => 'opened', 'labels' => 'feature', 'search' => 'auth', 'per_page' => 10])
        ->once()
        ->andReturn([]);

    $this->tool->handle(new Request([
        'project_id' => 42,
        'state' => 'opened',
        'labels' => 'feature',
        'search' => 'auth',
        'per_page' => 10,
    ]));

    // If we get here without exception, the parameters were passed correctly
    expect(true)->toBeTrue();
});

// ─── Handle — empty ─────────────────────────────────────────────

it('returns a message when no merge requests are found', function () {
    $this->gitLab
        ->shouldReceive('listMergeRequests')
        ->once()
        ->andReturn([]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
    ]));

    expect($result)->toContain('No merge requests found');
});

// ─── Handle — error ─────────────────────────────────────────────

it('returns error message instead of throwing on GitLab API failure', function () {
    $this->gitLab
        ->shouldReceive('listMergeRequests')
        ->once()
        ->andThrow(new GitLabApiException(
            message: 'GitLab API error (listMergeRequests): 404 {"message":"404 Project Not Found"}',
            statusCode: 404,
            responseBody: '{"message":"404 Project Not Found"}',
            context: 'listMergeRequests',
        ));

    $result = $this->tool->handle(new Request([
        'project_id' => 999,
    ]));

    expect($result)->toContain('Error listing merge requests');
});

// ─── Handle — access denied ────────────────────────────────────

it('returns rejection when access checker denies access', function () {
    $checker = Mockery::mock(ProjectAccessChecker::class);
    $checker->shouldReceive('check')
        ->with(999)
        ->once()
        ->andReturn('Access denied: you do not have access to this project.');

    $tool = new ListMergeRequests($this->gitLab, $checker);

    $result = $tool->handle(new Request([
        'project_id' => 999,
    ]));

    expect($result)->toContain('Access denied');
    $this->gitLab->shouldNotHaveReceived('listMergeRequests');
});

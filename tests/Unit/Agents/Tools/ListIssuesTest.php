<?php

use App\Agents\Tools\ListIssues;
use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use App\Services\ProjectAccessChecker;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->gitLab = Mockery::mock(GitLabClient::class);
    $this->accessChecker = Mockery::mock(ProjectAccessChecker::class);
    $this->accessChecker->shouldReceive('check')->andReturn(null);
    $this->tool = new ListIssues($this->gitLab, $this->accessChecker);
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

it('returns formatted issue list', function () {
    $this->gitLab
        ->shouldReceive('listIssues')
        ->with(42, [])
        ->once()
        ->andReturn([
            [
                'iid' => 1,
                'title' => 'Fix login bug',
                'state' => 'opened',
                'labels' => ['bug', 'critical'],
                'assignee' => ['username' => 'alice'],
                'assignees' => [['username' => 'alice']],
            ],
            [
                'iid' => 2,
                'title' => 'Add dark mode',
                'state' => 'opened',
                'labels' => [],
                'assignee' => null,
                'assignees' => [],
            ],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
    ]));

    expect($result)
        ->toContain('Found 2 issue(s)')
        ->toContain('#1 [opened] Fix login bug [bug, critical] (@alice)')
        ->toContain('#2 [opened] Add dark mode');
});

it('passes state filter to GitLab client', function () {
    $this->gitLab
        ->shouldReceive('listIssues')
        ->with(42, ['state' => 'closed'])
        ->once()
        ->andReturn([
            [
                'iid' => 5,
                'title' => 'Old feature request',
                'state' => 'closed',
                'labels' => [],
                'assignee' => null,
                'assignees' => [],
            ],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'state' => 'closed',
    ]));

    expect($result)
        ->toContain('#5 [closed] Old feature request');
});

it('passes labels filter to GitLab client', function () {
    $this->gitLab
        ->shouldReceive('listIssues')
        ->with(42, ['labels' => 'bug,critical'])
        ->once()
        ->andReturn([
            [
                'iid' => 1,
                'title' => 'Fix login bug',
                'state' => 'opened',
                'labels' => ['bug', 'critical'],
                'assignee' => null,
                'assignees' => [],
            ],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'labels' => 'bug,critical',
    ]));

    expect($result)->toContain('#1 [opened] Fix login bug [bug, critical]');
});

it('passes search query to GitLab client', function () {
    $this->gitLab
        ->shouldReceive('listIssues')
        ->with(42, ['search' => 'authentication'])
        ->once()
        ->andReturn([
            [
                'iid' => 3,
                'title' => 'Authentication timeout',
                'state' => 'opened',
                'labels' => [],
                'assignee' => null,
                'assignees' => [],
            ],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'search' => 'authentication',
    ]));

    expect($result)->toContain('#3 [opened] Authentication timeout');
});

it('passes per_page to GitLab client', function () {
    $this->gitLab
        ->shouldReceive('listIssues')
        ->with(42, ['per_page' => 5])
        ->once()
        ->andReturn([
            [
                'iid' => 1,
                'title' => 'Issue one',
                'state' => 'opened',
                'labels' => [],
                'assignee' => null,
                'assignees' => [],
            ],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'per_page' => 5,
    ]));

    expect($result)->toContain('Found 1 issue(s)');
});

it('combines multiple filters', function () {
    $this->gitLab
        ->shouldReceive('listIssues')
        ->with(42, ['state' => 'opened', 'labels' => 'bug', 'search' => 'login', 'per_page' => 10])
        ->once()
        ->andReturn([]);

    $this->tool->handle(new Request([
        'project_id' => 42,
        'state' => 'opened',
        'labels' => 'bug',
        'search' => 'login',
        'per_page' => 10,
    ]));

    // If we get here without exception, the parameters were passed correctly
    expect(true)->toBeTrue();
});

// ─── Handle — empty ─────────────────────────────────────────────

it('returns a message when no issues are found', function () {
    $this->gitLab
        ->shouldReceive('listIssues')
        ->once()
        ->andReturn([]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
    ]));

    expect($result)->toContain('No issues found');
});

// ─── Handle — error ─────────────────────────────────────────────

it('returns error message instead of throwing on GitLab API failure', function () {
    $this->gitLab
        ->shouldReceive('listIssues')
        ->once()
        ->andThrow(new GitLabApiException(
            message: 'GitLab API error (listIssues): 404 {"message":"404 Project Not Found"}',
            statusCode: 404,
            responseBody: '{"message":"404 Project Not Found"}',
            context: 'listIssues',
        ));

    $result = $this->tool->handle(new Request([
        'project_id' => 999,
    ]));

    expect($result)->toContain('Error listing issues');
});

// ─── Handle — access denied ────────────────────────────────────

it('returns rejection when access checker denies access', function () {
    $checker = Mockery::mock(ProjectAccessChecker::class);
    $checker->shouldReceive('check')
        ->with(999)
        ->once()
        ->andReturn('Access denied: you do not have access to this project.');

    $tool = new ListIssues($this->gitLab, $checker);

    $result = $tool->handle(new Request([
        'project_id' => 999,
    ]));

    expect($result)->toContain('Access denied');
    $this->gitLab->shouldNotHaveReceived('listIssues');
});

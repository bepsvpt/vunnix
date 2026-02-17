<?php

use App\Agents\Tools\ReadIssue;
use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use App\Services\ProjectAccessChecker;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    $this->gitLab = Mockery::mock(GitLabClient::class);
    $this->accessChecker = Mockery::mock(ProjectAccessChecker::class);
    $this->accessChecker->shouldReceive('check')->andReturn(null);
    $this->tool = new ReadIssue($this->gitLab, $this->accessChecker);
});

// ─── Description ────────────────────────────────────────────────

it('has a description', function (): void {
    expect($this->tool->description())->toBeString()->not->toBeEmpty();
});

// ─── Schema ─────────────────────────────────────────────────────

it('defines the expected schema parameters', function (): void {
    $schema = new JsonSchemaTypeFactory;
    $result = $this->tool->schema($schema);

    expect($result)->toHaveKeys(['project_id', 'issue_iid']);
});

// ─── Handle — success ───────────────────────────────────────────

it('returns formatted issue details', function (): void {
    $this->gitLab
        ->shouldReceive('getIssue')
        ->with(42, 7)
        ->once()
        ->andReturn([
            'iid' => 7,
            'title' => 'Fix authentication timeout',
            'state' => 'opened',
            'author' => ['username' => 'bob'],
            'assignees' => [
                ['username' => 'alice'],
                ['username' => 'charlie'],
            ],
            'labels' => ['bug', 'high-priority'],
            'description' => "## Problem\n\nUsers are experiencing login timeouts after 30 seconds.",
            'created_at' => '2025-12-01T10:00:00Z',
            'updated_at' => '2025-12-05T14:30:00Z',
            'web_url' => 'https://gitlab.example.com/project/issues/7',
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'issue_iid' => 7,
    ]));

    expect($result)
        ->toContain('Issue #7: Fix authentication timeout')
        ->toContain('State: opened')
        ->toContain('Author: @bob')
        ->toContain('Assignees: @alice, @charlie')
        ->toContain('Labels: bug, high-priority')
        ->toContain('Created: 2025-12-01T10:00:00Z')
        ->toContain('Updated: 2025-12-05T14:30:00Z')
        ->toContain('URL: https://gitlab.example.com/project/issues/7')
        ->toContain('--- Description ---')
        ->toContain('Users are experiencing login timeouts');
});

it('handles issue with no assignees', function (): void {
    $this->gitLab
        ->shouldReceive('getIssue')
        ->with(42, 3)
        ->once()
        ->andReturn([
            'iid' => 3,
            'title' => 'Unassigned task',
            'state' => 'opened',
            'author' => ['username' => 'bob'],
            'assignees' => [],
            'labels' => [],
            'description' => '',
            'created_at' => '2025-12-01T10:00:00Z',
            'updated_at' => '2025-12-01T10:00:00Z',
            'web_url' => 'https://gitlab.example.com/project/issues/3',
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'issue_iid' => 3,
    ]));

    expect($result)
        ->toContain('Assignees: none')
        ->toContain('Labels: none')
        ->not->toContain('--- Description ---');
});

it('handles issue with empty description', function (): void {
    $this->gitLab
        ->shouldReceive('getIssue')
        ->with(42, 5)
        ->once()
        ->andReturn([
            'iid' => 5,
            'title' => 'No description issue',
            'state' => 'closed',
            'author' => ['username' => 'alice'],
            'assignees' => [],
            'labels' => ['documentation'],
            'description' => '',
            'created_at' => '2025-11-01T08:00:00Z',
            'updated_at' => '2025-11-15T12:00:00Z',
            'web_url' => 'https://gitlab.example.com/project/issues/5',
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'issue_iid' => 5,
    ]));

    expect($result)
        ->toContain('Issue #5: No description issue')
        ->toContain('State: closed')
        ->not->toContain('--- Description ---');
});

// ─── Handle — error ─────────────────────────────────────────────

it('returns error message instead of throwing on GitLab API failure', function (): void {
    $this->gitLab
        ->shouldReceive('getIssue')
        ->once()
        ->andThrow(new GitLabApiException(
            message: 'GitLab API error (getIssue #999): 404 {"message":"404 Issue Not Found"}',
            statusCode: 404,
            responseBody: '{"message":"404 Issue Not Found"}',
            context: 'getIssue #999',
        ));

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'issue_iid' => 999,
    ]));

    expect($result)->toContain('Error reading issue');
});

// ─── Handle — access denied ────────────────────────────────────

it('returns rejection when access checker denies access', function (): void {
    $checker = Mockery::mock(ProjectAccessChecker::class);
    $checker->shouldReceive('check')
        ->with(999)
        ->once()
        ->andReturn('Access denied: you do not have access to this project.');

    $tool = new ReadIssue($this->gitLab, $checker);

    $result = $tool->handle(new Request([
        'project_id' => 999,
        'issue_iid' => 1,
    ]));

    expect($result)->toContain('Access denied');
    $this->gitLab->shouldNotHaveReceived('getIssue');
});

<?php

use App\Agents\Tools\ReadMergeRequest;
use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use App\Services\ProjectAccessChecker;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->gitLab = Mockery::mock(GitLabClient::class);
    $this->accessChecker = Mockery::mock(ProjectAccessChecker::class);
    $this->accessChecker->shouldReceive('check')->andReturn(null);
    $this->tool = new ReadMergeRequest($this->gitLab, $this->accessChecker);
});

// ─── Description ────────────────────────────────────────────────

it('has a description', function () {
    expect($this->tool->description())->toBeString()->not->toBeEmpty();
});

// ─── Schema ─────────────────────────────────────────────────────

it('defines the expected schema parameters', function () {
    $schema = new JsonSchemaTypeFactory;
    $result = $this->tool->schema($schema);

    expect($result)->toHaveKeys(['project_id', 'mr_iid']);
});

// ─── Handle — success ───────────────────────────────────────────

it('returns formatted merge request details', function () {
    $this->gitLab
        ->shouldReceive('getMergeRequest')
        ->with(42, 7)
        ->once()
        ->andReturn([
            'iid' => 7,
            'title' => 'Add user authentication',
            'state' => 'opened',
            'author' => ['username' => 'bob'],
            'source_branch' => 'feature/auth',
            'target_branch' => 'main',
            'merge_status' => 'can_be_merged',
            'assignees' => [
                ['username' => 'alice'],
                ['username' => 'charlie'],
            ],
            'reviewers' => [
                ['username' => 'dave'],
            ],
            'labels' => ['feature', 'backend'],
            'description' => "## Changes\n\nAdded JWT authentication with refresh tokens.",
            'created_at' => '2025-12-01T10:00:00Z',
            'updated_at' => '2025-12-05T14:30:00Z',
            'web_url' => 'https://gitlab.example.com/project/-/merge_requests/7',
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'mr_iid' => 7,
    ]));

    expect($result)
        ->toContain('MR !7: Add user authentication')
        ->toContain('State: opened')
        ->toContain('Author: @bob')
        ->toContain('Branches: feature/auth → main')
        ->toContain('Merge status: can_be_merged')
        ->toContain('Assignees: @alice, @charlie')
        ->toContain('Reviewers: @dave')
        ->toContain('Labels: feature, backend')
        ->toContain('Created: 2025-12-01T10:00:00Z')
        ->toContain('Updated: 2025-12-05T14:30:00Z')
        ->toContain('URL: https://gitlab.example.com/project/-/merge_requests/7')
        ->toContain('--- Description ---')
        ->toContain('Added JWT authentication with refresh tokens');
});

it('handles merge request with no assignees or reviewers', function () {
    $this->gitLab
        ->shouldReceive('getMergeRequest')
        ->with(42, 3)
        ->once()
        ->andReturn([
            'iid' => 3,
            'title' => 'Draft: WIP changes',
            'state' => 'opened',
            'author' => ['username' => 'bob'],
            'source_branch' => 'draft/wip',
            'target_branch' => 'develop',
            'merge_status' => 'checking',
            'assignees' => [],
            'reviewers' => [],
            'labels' => [],
            'description' => '',
            'created_at' => '2025-12-01T10:00:00Z',
            'updated_at' => '2025-12-01T10:00:00Z',
            'web_url' => 'https://gitlab.example.com/project/-/merge_requests/3',
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'mr_iid' => 3,
    ]));

    expect($result)
        ->toContain('Assignees: none')
        ->toContain('Reviewers: none')
        ->toContain('Labels: none')
        ->not->toContain('--- Description ---');
});

it('handles merge request with empty description', function () {
    $this->gitLab
        ->shouldReceive('getMergeRequest')
        ->with(42, 5)
        ->once()
        ->andReturn([
            'iid' => 5,
            'title' => 'Quick fix',
            'state' => 'merged',
            'author' => ['username' => 'alice'],
            'source_branch' => 'fix/quick',
            'target_branch' => 'main',
            'merge_status' => 'can_be_merged',
            'assignees' => [],
            'reviewers' => [],
            'labels' => ['hotfix'],
            'description' => '',
            'created_at' => '2025-11-01T08:00:00Z',
            'updated_at' => '2025-11-15T12:00:00Z',
            'web_url' => 'https://gitlab.example.com/project/-/merge_requests/5',
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'mr_iid' => 5,
    ]));

    expect($result)
        ->toContain('MR !5: Quick fix')
        ->toContain('State: merged')
        ->not->toContain('--- Description ---');
});

// ─── Handle — error ─────────────────────────────────────────────

it('returns error message instead of throwing on GitLab API failure', function () {
    $this->gitLab
        ->shouldReceive('getMergeRequest')
        ->once()
        ->andThrow(new GitLabApiException(
            message: 'GitLab API error (getMergeRequest !999): 404 {"message":"404 Not Found"}',
            statusCode: 404,
            responseBody: '{"message":"404 Not Found"}',
            context: 'getMergeRequest !999',
        ));

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'mr_iid' => 999,
    ]));

    expect($result)->toContain('Error reading merge request');
});

// ─── Handle — access denied ────────────────────────────────────

it('returns rejection when access checker denies access', function () {
    $checker = Mockery::mock(ProjectAccessChecker::class);
    $checker->shouldReceive('check')
        ->with(999)
        ->once()
        ->andReturn('Access denied: you do not have access to this project.');

    $tool = new ReadMergeRequest($this->gitLab, $checker);

    $result = $tool->handle(new Request([
        'project_id' => 999,
        'mr_iid' => 1,
    ]));

    expect($result)->toContain('Access denied');
    $this->gitLab->shouldNotHaveReceived('getMergeRequest');
});

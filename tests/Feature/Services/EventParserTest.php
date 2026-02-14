<?php

use App\Events\Webhook\IssueLabelChanged;
use App\Events\Webhook\MergeRequestMerged;
use App\Events\Webhook\MergeRequestOpened;
use App\Events\Webhook\MergeRequestUpdated;
use App\Events\Webhook\NoteOnIssue;
use App\Events\Webhook\NoteOnMR;
use App\Events\Webhook\PushToMRBranch;
use App\Services\EventRouter;

/**
 * Helper: build a minimal event context array.
 */
function eventContext(string $eventType, array $overrides = []): array
{
    return array_merge([
        'event_type' => $eventType,
        'project_id' => 1,
        'gitlab_project_id' => 100,
        'payload' => [],
    ], $overrides);
}

// ------------------------------------------------------------------
//  Merge Request events
// ------------------------------------------------------------------

it('parses MR open payload into MergeRequestOpened', function () {
    $router = new EventRouter;

    $event = $router->parseEvent(eventContext('merge_request', [
        'action' => 'open',
        'merge_request_iid' => 42,
        'source_branch' => 'feature/login',
        'target_branch' => 'main',
        'author_id' => 7,
        'last_commit_sha' => 'abc123',
    ]));

    expect($event)->toBeInstanceOf(MergeRequestOpened::class)
        ->and($event->type())->toBe('merge_request_opened')
        ->and($event->mergeRequestIid)->toBe(42)
        ->and($event->sourceBranch)->toBe('feature/login')
        ->and($event->targetBranch)->toBe('main')
        ->and($event->authorId)->toBe(7)
        ->and($event->lastCommitSha)->toBe('abc123')
        ->and($event->projectId)->toBe(1)
        ->and($event->gitlabProjectId)->toBe(100);
});

it('parses MR update payload into MergeRequestUpdated', function () {
    $router = new EventRouter;

    $event = $router->parseEvent(eventContext('merge_request', [
        'action' => 'update',
        'merge_request_iid' => 42,
        'source_branch' => 'feature/login',
        'target_branch' => 'main',
        'author_id' => 7,
        'last_commit_sha' => 'def456',
    ]));

    expect($event)->toBeInstanceOf(MergeRequestUpdated::class)
        ->and($event->type())->toBe('merge_request_updated');
});

it('parses MR merge payload into MergeRequestMerged', function () {
    $router = new EventRouter;

    $event = $router->parseEvent(eventContext('merge_request', [
        'action' => 'merge',
        'merge_request_iid' => 42,
        'source_branch' => 'feature/login',
        'target_branch' => 'main',
        'author_id' => 7,
        'last_commit_sha' => 'ghi789',
    ]));

    expect($event)->toBeInstanceOf(MergeRequestMerged::class)
        ->and($event->type())->toBe('merge_request_merged');
});

it('returns null for unknown MR actions', function () {
    $router = new EventRouter;

    $event = $router->parseEvent(eventContext('merge_request', [
        'action' => 'close',
        'merge_request_iid' => 42,
    ]));

    expect($event)->toBeNull();
});

it('returns null for MR event without iid', function () {
    $router = new EventRouter;

    $event = $router->parseEvent(eventContext('merge_request', [
        'action' => 'open',
    ]));

    expect($event)->toBeNull();
});

// ------------------------------------------------------------------
//  Note events
// ------------------------------------------------------------------

it('parses Note on MR payload into NoteOnMR', function () {
    $router = new EventRouter;

    $event = $router->parseEvent(eventContext('note', [
        'noteable_type' => 'MergeRequest',
        'note' => '@ai review',
        'author_id' => 5,
        'merge_request_iid' => 42,
    ]));

    expect($event)->toBeInstanceOf(NoteOnMR::class)
        ->and($event->type())->toBe('note_on_mr')
        ->and($event->mergeRequestIid)->toBe(42)
        ->and($event->note)->toBe('@ai review')
        ->and($event->authorId)->toBe(5);
});

it('parses Note on Issue payload into NoteOnIssue', function () {
    $router = new EventRouter;

    $event = $router->parseEvent(eventContext('note', [
        'noteable_type' => 'Issue',
        'note' => '@ai explain the auth flow',
        'author_id' => 3,
        'issue_iid' => 17,
    ]));

    expect($event)->toBeInstanceOf(NoteOnIssue::class)
        ->and($event->type())->toBe('note_on_issue')
        ->and($event->issueIid)->toBe(17)
        ->and($event->note)->toBe('@ai explain the auth flow')
        ->and($event->authorId)->toBe(3);
});

it('returns null for Note on unsupported noteable type', function () {
    $router = new EventRouter;

    $event = $router->parseEvent(eventContext('note', [
        'noteable_type' => 'Snippet',
        'note' => 'some comment',
        'author_id' => 1,
    ]));

    expect($event)->toBeNull();
});

// ------------------------------------------------------------------
//  Issue events
// ------------------------------------------------------------------

it('parses Issue payload into IssueLabelChanged', function () {
    $router = new EventRouter;

    $event = $router->parseEvent(eventContext('issue', [
        'issue_iid' => 99,
        'action' => 'update',
        'author_id' => 12,
        'labels' => ['ai::develop', 'backend'],
    ]));

    expect($event)->toBeInstanceOf(IssueLabelChanged::class)
        ->and($event->type())->toBe('issue_label_changed')
        ->and($event->issueIid)->toBe(99)
        ->and($event->action)->toBe('update')
        ->and($event->authorId)->toBe(12)
        ->and($event->labels)->toBe(['ai::develop', 'backend'])
        ->and($event->hasLabel('ai::develop'))->toBeTrue()
        ->and($event->hasLabel('frontend'))->toBeFalse();
});

it('returns null for Issue event without iid', function () {
    $router = new EventRouter;

    $event = $router->parseEvent(eventContext('issue', [
        'action' => 'open',
    ]));

    expect($event)->toBeNull();
});

// ------------------------------------------------------------------
//  Push events
// ------------------------------------------------------------------

it('parses Push payload into PushToMRBranch', function () {
    $router = new EventRouter;

    $event = $router->parseEvent(eventContext('push', [
        'ref' => 'refs/heads/feature/login',
        'before' => '0000000000000000000000000000000000000000',
        'after' => 'abc123def456',
        'user_id' => 7,
        'commits' => [['id' => 'abc123', 'message' => 'Add login']],
        'total_commits_count' => 1,
    ]));

    expect($event)->toBeInstanceOf(PushToMRBranch::class)
        ->and($event->type())->toBe('push_to_mr_branch')
        ->and($event->ref)->toBe('refs/heads/feature/login')
        ->and($event->branchName())->toBe('feature/login')
        ->and($event->beforeSha)->toBe('0000000000000000000000000000000000000000')
        ->and($event->afterSha)->toBe('abc123def456')
        ->and($event->userId)->toBe(7)
        ->and($event->commits)->toHaveCount(1)
        ->and($event->totalCommitsCount)->toBe(1);
});

it('returns null for Push event without ref', function () {
    $router = new EventRouter;

    $event = $router->parseEvent(eventContext('push', []));

    expect($event)->toBeNull();
});

// ------------------------------------------------------------------
//  Unknown event types
// ------------------------------------------------------------------

it('returns null for unknown event types', function () {
    $router = new EventRouter;

    $event = $router->parseEvent(eventContext('pipeline', []));

    expect($event)->toBeNull();
});

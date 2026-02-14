<?php

use App\Events\Webhook\IssueLabelChanged;
use App\Events\Webhook\MergeRequestMerged;
use App\Events\Webhook\MergeRequestOpened;
use App\Events\Webhook\MergeRequestUpdated;
use App\Events\Webhook\NoteOnIssue;
use App\Events\Webhook\NoteOnMR;
use App\Events\Webhook\PushToMRBranch;
use App\Jobs\PostHelpResponse;
use App\Services\EventRouter;
use Illuminate\Support\Facades\Queue;

/**
 * Helper: build a minimal event context array for routing tests.
 */
function routerContext(string $eventType, array $overrides = []): array
{
    return array_merge([
        'event_type' => $eventType,
        'project_id' => 1,
        'gitlab_project_id' => 100,
        'payload' => [],
    ], $overrides);
}

// ------------------------------------------------------------------
//  MR → auto_review
// ------------------------------------------------------------------

it('routes MR opened to auto_review with normal priority', function () {
    $router = new EventRouter;

    $result = $router->route(routerContext('merge_request', [
        'action' => 'open',
        'merge_request_iid' => 42,
        'source_branch' => 'feature/login',
        'target_branch' => 'main',
        'author_id' => 7,
        'last_commit_sha' => 'abc123',
    ]));

    expect($result)->not->toBeNull()
        ->and($result->intent)->toBe('auto_review')
        ->and($result->priority)->toBe('normal')
        ->and($result->event)->toBeInstanceOf(MergeRequestOpened::class);
});

it('routes MR updated to auto_review with normal priority', function () {
    $router = new EventRouter;

    $result = $router->route(routerContext('merge_request', [
        'action' => 'update',
        'merge_request_iid' => 42,
        'source_branch' => 'feature/login',
        'target_branch' => 'main',
        'author_id' => 7,
        'last_commit_sha' => 'def456',
    ]));

    expect($result)->not->toBeNull()
        ->and($result->intent)->toBe('auto_review')
        ->and($result->priority)->toBe('normal')
        ->and($result->event)->toBeInstanceOf(MergeRequestUpdated::class);
});

// ------------------------------------------------------------------
//  MR merged → acceptance_tracking
// ------------------------------------------------------------------

it('routes MR merged to acceptance_tracking', function () {
    $router = new EventRouter;

    $result = $router->route(routerContext('merge_request', [
        'action' => 'merge',
        'merge_request_iid' => 42,
        'source_branch' => 'feature/login',
        'target_branch' => 'main',
        'author_id' => 7,
        'last_commit_sha' => 'ghi789',
    ]));

    expect($result)->not->toBeNull()
        ->and($result->intent)->toBe('acceptance_tracking')
        ->and($result->event)->toBeInstanceOf(MergeRequestMerged::class);
});

// ------------------------------------------------------------------
//  Note on MR — @ai commands
// ------------------------------------------------------------------

it('routes @ai review to on_demand_review with high priority', function () {
    $router = new EventRouter;

    $result = $router->route(routerContext('note', [
        'noteable_type' => 'MergeRequest',
        'note' => '@ai review',
        'author_id' => 5,
        'merge_request_iid' => 42,
    ]));

    expect($result)->not->toBeNull()
        ->and($result->intent)->toBe('on_demand_review')
        ->and($result->priority)->toBe('high')
        ->and($result->event)->toBeInstanceOf(NoteOnMR::class);
});

it('routes @ai improve to improve with normal priority', function () {
    $router = new EventRouter;

    $result = $router->route(routerContext('note', [
        'noteable_type' => 'MergeRequest',
        'note' => '@ai improve',
        'author_id' => 5,
        'merge_request_iid' => 42,
    ]));

    expect($result)->not->toBeNull()
        ->and($result->intent)->toBe('improve')
        ->and($result->priority)->toBe('normal');
});

it('routes @ai ask "question" to ask_command with normal priority', function () {
    $router = new EventRouter;

    $result = $router->route(routerContext('note', [
        'noteable_type' => 'MergeRequest',
        'note' => '@ai ask "why is this function here?"',
        'author_id' => 5,
        'merge_request_iid' => 42,
    ]));

    expect($result)->not->toBeNull()
        ->and($result->intent)->toBe('ask_command')
        ->and($result->priority)->toBe('normal');
});

it('routes @ai review with surrounding text', function () {
    $router = new EventRouter;

    $result = $router->route(routerContext('note', [
        'noteable_type' => 'MergeRequest',
        'note' => 'Hey, can you please @ai review this MR? Thanks!',
        'author_id' => 5,
        'merge_request_iid' => 42,
    ]));

    expect($result)->not->toBeNull()
        ->and($result->intent)->toBe('on_demand_review');
});

it('ignores MR note without @ai mention', function () {
    $router = new EventRouter;

    $result = $router->route(routerContext('note', [
        'noteable_type' => 'MergeRequest',
        'note' => 'Looks good to me, nice work!',
        'author_id' => 5,
        'merge_request_iid' => 42,
    ]));

    expect($result)->toBeNull();
});

// ------------------------------------------------------------------
//  D155: Unrecognized @ai command → help response
// ------------------------------------------------------------------

it('dispatches help response for unrecognized @ai command', function () {
    Queue::fake();

    $router = new EventRouter;

    $result = $router->route(routerContext('note', [
        'noteable_type' => 'MergeRequest',
        'note' => '@ai summarize',
        'author_id' => 5,
        'merge_request_iid' => 42,
    ]));

    expect($result)->not->toBeNull()
        ->and($result->intent)->toBe('help_response');

    Queue::assertPushed(PostHelpResponse::class, function ($job) {
        return $job->gitlabProjectId === 100
            && $job->mergeRequestIid === 42
            && $job->unrecognizedCommand === '@ai summarize';
    });
});

it('dispatches help response for bare @ai mention on MR', function () {
    Queue::fake();

    $router = new EventRouter;

    $result = $router->route(routerContext('note', [
        'noteable_type' => 'MergeRequest',
        'note' => 'Hey @ai',
        'author_id' => 5,
        'merge_request_iid' => 42,
    ]));

    expect($result)->not->toBeNull()
        ->and($result->intent)->toBe('help_response');

    Queue::assertPushed(PostHelpResponse::class);
});

// ------------------------------------------------------------------
//  D154: Bot event filtering
// ------------------------------------------------------------------

it('discards Note on MR from bot account (D154)', function () {
    $router = new EventRouter(botAccountId: 999);

    $result = $router->route(routerContext('note', [
        'noteable_type' => 'MergeRequest',
        'note' => '@ai review',
        'author_id' => 999,
        'merge_request_iid' => 42,
    ]));

    expect($result)->toBeNull();
});

it('discards Note on Issue from bot account (D154)', function () {
    $router = new EventRouter(botAccountId: 999);

    $result = $router->route(routerContext('note', [
        'noteable_type' => 'Issue',
        'note' => '@ai explain this',
        'author_id' => 999,
        'issue_iid' => 17,
    ]));

    expect($result)->toBeNull();
});

it('does NOT filter MR open events from bot account (D154/D100)', function () {
    $router = new EventRouter(botAccountId: 999);

    $result = $router->route(routerContext('merge_request', [
        'action' => 'open',
        'merge_request_iid' => 42,
        'source_branch' => 'ai/feature-x',
        'target_branch' => 'main',
        'author_id' => 999,
        'last_commit_sha' => 'bot123',
    ]));

    expect($result)->not->toBeNull()
        ->and($result->intent)->toBe('auto_review');
});

it('does NOT filter MR update events from bot account (D154/D100)', function () {
    $router = new EventRouter(botAccountId: 999);

    $result = $router->route(routerContext('merge_request', [
        'action' => 'update',
        'merge_request_iid' => 42,
        'source_branch' => 'ai/feature-x',
        'target_branch' => 'main',
        'author_id' => 999,
        'last_commit_sha' => 'bot456',
    ]));

    expect($result)->not->toBeNull()
        ->and($result->intent)->toBe('auto_review');
});

it('passes Note events when bot_account_id is not configured', function () {
    $router = new EventRouter;

    $result = $router->route(routerContext('note', [
        'noteable_type' => 'MergeRequest',
        'note' => '@ai review',
        'author_id' => 999,
        'merge_request_iid' => 42,
    ]));

    expect($result)->not->toBeNull()
        ->and($result->intent)->toBe('on_demand_review');
});

// ------------------------------------------------------------------
//  Note on Issue → issue_discussion
// ------------------------------------------------------------------

it('routes @ai mention on Issue to issue_discussion', function () {
    $router = new EventRouter;

    $result = $router->route(routerContext('note', [
        'noteable_type' => 'Issue',
        'note' => '@ai explain the auth flow',
        'author_id' => 3,
        'issue_iid' => 17,
    ]));

    expect($result)->not->toBeNull()
        ->and($result->intent)->toBe('issue_discussion')
        ->and($result->priority)->toBe('normal')
        ->and($result->event)->toBeInstanceOf(NoteOnIssue::class);
});

it('ignores Issue note without @ai mention', function () {
    $router = new EventRouter;

    $result = $router->route(routerContext('note', [
        'noteable_type' => 'Issue',
        'note' => 'This is a regular comment',
        'author_id' => 3,
        'issue_iid' => 17,
    ]));

    expect($result)->toBeNull();
});

// ------------------------------------------------------------------
//  Issue label change → feature_dev
// ------------------------------------------------------------------

it('routes ai::develop label to feature_dev with low priority', function () {
    $router = new EventRouter;

    $result = $router->route(routerContext('issue', [
        'issue_iid' => 99,
        'action' => 'update',
        'author_id' => 12,
        'labels' => ['ai::develop', 'backend'],
    ]));

    expect($result)->not->toBeNull()
        ->and($result->intent)->toBe('feature_dev')
        ->and($result->priority)->toBe('low')
        ->and($result->event)->toBeInstanceOf(IssueLabelChanged::class);
});

it('ignores Issue label change without ai::develop', function () {
    $router = new EventRouter;

    $result = $router->route(routerContext('issue', [
        'issue_iid' => 99,
        'action' => 'update',
        'author_id' => 12,
        'labels' => ['backend', 'bug'],
    ]));

    expect($result)->toBeNull();
});

// ------------------------------------------------------------------
//  Push → incremental_review
// ------------------------------------------------------------------

it('routes push event to incremental_review', function () {
    $router = new EventRouter;

    $result = $router->route(routerContext('push', [
        'ref' => 'refs/heads/feature/login',
        'before' => '0000000000000000000000000000000000000000',
        'after' => 'abc123',
        'user_id' => 7,
        'commits' => [['id' => 'abc123', 'message' => 'Add login']],
        'total_commits_count' => 1,
    ]));

    expect($result)->not->toBeNull()
        ->and($result->intent)->toBe('incremental_review')
        ->and($result->priority)->toBe('normal')
        ->and($result->event)->toBeInstanceOf(PushToMRBranch::class);
});

// ------------------------------------------------------------------
//  Edge cases
// ------------------------------------------------------------------

it('returns null for unparseable event context', function () {
    $router = new EventRouter;

    $result = $router->route(['event_type' => 'merge_request', 'project_id' => 1]);

    expect($result)->toBeNull();
});

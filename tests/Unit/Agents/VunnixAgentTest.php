<?php

use App\Agents\Middleware\PruneConversationHistory;
use App\Agents\Tools\BrowseRepoTree;
use App\Agents\Tools\DispatchAction;
use App\Agents\Tools\ListIssues;
use App\Agents\Tools\ListMergeRequests;
use App\Agents\Tools\ReadFile;
use App\Agents\Tools\ReadIssue;
use App\Agents\Tools\ReadMergeRequest;
use App\Agents\Tools\ReadMRDiff;
use App\Agents\Tools\SearchCode;
use App\Agents\VunnixAgent;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;

uses(Tests\TestCase::class);

// ─── Interface Implementation ───────────────────────────────────

it('implements all required AI SDK interfaces', function () {
    $agent = new VunnixAgent;

    expect($agent)->toBeInstanceOf(Agent::class);
    expect($agent)->toBeInstanceOf(Conversational::class);
    expect($agent)->toBeInstanceOf(HasTools::class);
    expect($agent)->toBeInstanceOf(HasMiddleware::class);
});

// ─── Provider ───────────────────────────────────────────────────

it('uses the anthropic provider', function () {
    $agent = new VunnixAgent;

    expect($agent->provider())->toBe('anthropic');
});

// ─── Tools ──────────────────────────────────────────────────────

it('returns the T50 repo browsing tools', function () {
    $agent = new VunnixAgent;
    $tools = iterator_to_array($agent->tools());

    expect($tools[0])->toBeInstanceOf(BrowseRepoTree::class);
    expect($tools[1])->toBeInstanceOf(ReadFile::class);
    expect($tools[2])->toBeInstanceOf(SearchCode::class);
});

it('returns the T51 issue tools', function () {
    $agent = new VunnixAgent;
    $tools = iterator_to_array($agent->tools());

    expect($tools[3])->toBeInstanceOf(ListIssues::class);
    expect($tools[4])->toBeInstanceOf(ReadIssue::class);
});

it('returns the T52 merge request tools', function () {
    $agent = new VunnixAgent;
    $tools = iterator_to_array($agent->tools());

    expect($tools[5])->toBeInstanceOf(ListMergeRequests::class);
    expect($tools[6])->toBeInstanceOf(ReadMergeRequest::class);
    expect($tools[7])->toBeInstanceOf(ReadMRDiff::class);
});

it('returns the T55 dispatch action tool', function () {
    $agent = new VunnixAgent;
    $tools = iterator_to_array($agent->tools());

    expect($tools)->toHaveCount(9);
    expect($tools[8])->toBeInstanceOf(DispatchAction::class);
});

it('returns tools that implement the Tool interface', function () {
    $agent = new VunnixAgent;
    $tools = iterator_to_array($agent->tools());

    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(Tool::class);
    }
});

// ─── Middleware ──────────────────────────────────────────────────

it('returns the T58 conversation pruning middleware', function () {
    $agent = new VunnixAgent;
    $middleware = $agent->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(PruneConversationHistory::class);
});

// ─── Pruned Messages ────────────────────────────────────────────

it('returns pruned messages when set via setPrunedMessages', function () {
    $agent = new VunnixAgent;
    $messages = [
        new \Laravel\Ai\Messages\UserMessage('summary'),
        new \Laravel\Ai\Messages\Message('user', 'msg1'),
    ];

    $agent->setPrunedMessages($messages);

    expect($agent->messages())->toBe($messages);
});

it('returns empty messages when no conversation and no pruned messages set', function () {
    $agent = new VunnixAgent;

    expect($agent->messages())->toBe([]);
});

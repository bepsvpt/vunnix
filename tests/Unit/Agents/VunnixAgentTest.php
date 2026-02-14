<?php

use App\Agents\Tools\BrowseRepoTree;
use App\Agents\Tools\ReadFile;
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

    expect($tools)->toHaveCount(3);
    expect($tools[0])->toBeInstanceOf(BrowseRepoTree::class);
    expect($tools[1])->toBeInstanceOf(ReadFile::class);
    expect($tools[2])->toBeInstanceOf(SearchCode::class);
});

it('returns tools that implement the Tool interface', function () {
    $agent = new VunnixAgent;
    $tools = iterator_to_array($agent->tools());

    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(Tool::class);
    }
});

// ─── Middleware ──────────────────────────────────────────────────

it('returns an empty middleware array as placeholder', function () {
    $agent = new VunnixAgent;

    expect($agent->middleware())->toBe([]);
});

<?php

use App\Agents\VunnixAgent;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;

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

it('returns an empty tools array as placeholder', function () {
    $agent = new VunnixAgent;

    $tools = $agent->tools();
    expect(iterator_to_array($tools))->toBe([]);
});

// ─── Middleware ──────────────────────────────────────────────────

it('returns an empty middleware array as placeholder', function () {
    $agent = new VunnixAgent;

    expect($agent->middleware())->toBe([]);
});

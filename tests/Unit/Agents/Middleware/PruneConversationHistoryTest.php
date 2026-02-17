<?php

use App\Agents\Middleware\PruneConversationHistory;
use App\Agents\VunnixAgent;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;

// ─── Helper: Build message array of N turns ────────────────────
function buildMessages(int $turns): array
{
    $messages = [];
    for ($i = 1; $i <= $turns; $i++) {
        $messages[] = new Message('user', "User message {$i}");
        $messages[] = new Message('assistant', "Assistant response {$i}");
    }

    return $messages;
}

function buildAgentPrompt(VunnixAgent $agent): AgentPrompt
{
    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('name')->andReturn('anthropic');

    return new AgentPrompt(
        $agent,
        'Current user message',
        [],
        $provider,
        'claude-opus-4-20250514',
    );
}

// ─── No pruning for ≤20 turns ──────────────────────────────────

it('does not prune conversations with 20 or fewer turns', function (): void {
    $messages = buildMessages(20); // exactly 20 turns = 40 messages
    $agent = Mockery::mock(VunnixAgent::class)->makePartial();
    $agent->shouldReceive('messages')->andReturn($messages);
    $agent->shouldNotReceive('setPrunedMessages');

    $provider = Mockery::mock(TextProvider::class);
    $middleware = new PruneConversationHistory($provider);

    $prompt = buildAgentPrompt($agent);
    $prompt = new AgentPrompt($agent, 'test', [], $provider, 'claude-opus-4-20250514');

    $next = function (AgentPrompt $p) {
        return new AgentResponse('test-id', 'response text', new Usage, new Meta);
    };

    $result = $middleware->handle($prompt, $next);

    expect($result)->toBeInstanceOf(AgentResponse::class);
});

it('does not prune conversations with fewer than 20 turns', function (): void {
    $messages = buildMessages(10); // 10 turns = 20 messages
    $agent = Mockery::mock(VunnixAgent::class)->makePartial();
    $agent->shouldReceive('messages')->andReturn($messages);
    $agent->shouldNotReceive('setPrunedMessages');

    $provider = Mockery::mock(TextProvider::class);
    $middleware = new PruneConversationHistory($provider);

    $prompt = new AgentPrompt($agent, 'test', [], $provider, 'claude-opus-4-20250514');

    $next = function (AgentPrompt $p) {
        return new AgentResponse('test-id', 'response text', new Usage, new Meta);
    };

    $result = $middleware->handle($prompt, $next);

    expect($result)->toBeInstanceOf(AgentResponse::class);
});

it('does not prune when there are no messages (new conversation)', function (): void {
    $agent = Mockery::mock(VunnixAgent::class)->makePartial();
    $agent->shouldReceive('messages')->andReturn([]);
    $agent->shouldNotReceive('setPrunedMessages');

    $provider = Mockery::mock(TextProvider::class);
    $middleware = new PruneConversationHistory($provider);

    $prompt = new AgentPrompt($agent, 'test', [], $provider, 'claude-opus-4-20250514');

    $next = function (AgentPrompt $p) {
        return new AgentResponse('test-id', 'response text', new Usage, new Meta);
    };

    $result = $middleware->handle($prompt, $next);

    expect($result)->toBeInstanceOf(AgentResponse::class);
});

// ─── Pruning for >20 turns ─────────────────────────────────────

it('prunes conversations with more than 20 turns', function (): void {
    $messages = buildMessages(25); // 25 turns = 50 messages
    $agent = Mockery::mock(VunnixAgent::class)->makePartial();
    $agent->shouldReceive('messages')->andReturn($messages);

    // Expect setPrunedMessages to be called with an array
    $prunedMessages = null;
    $agent->shouldReceive('setPrunedMessages')->once()->withArgs(function ($msgs) use (&$prunedMessages) {
        $prunedMessages = $msgs;

        return is_array($msgs);
    });

    // Mock the text provider for summarization
    $gateway = Mockery::mock(TextGateway::class);
    $gateway->shouldReceive('generateText')->once()->andReturn(
        new TextResponse('Summary of older conversation turns.', new Usage, new Meta)
    );

    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGateway')->andReturn($gateway);
    $provider->shouldReceive('cheapestTextModel')->andReturn('claude-haiku-4-5-20251001');

    $middleware = new PruneConversationHistory($provider);

    $prompt = new AgentPrompt($agent, 'test', [], $provider, 'claude-opus-4-20250514');

    $next = function (AgentPrompt $p) {
        return new AgentResponse('test-id', 'response text', new Usage, new Meta);
    };

    $result = $middleware->handle($prompt, $next);

    expect($result)->toBeInstanceOf(AgentResponse::class);

    // The pruned messages should contain:
    // 1. A summary message (user role with the summary)
    // 2. The last 10 turns (20 messages) from the original
    expect($prunedMessages)->not->toBeNull();

    // First message should be the summary
    expect($prunedMessages[0])->toBeInstanceOf(UserMessage::class);
    expect($prunedMessages[0]->content)->toContain('Summary of older conversation turns.');

    // Remaining messages should be the last 10 turns (20 messages)
    $recentMessages = array_slice($prunedMessages, 1);
    expect($recentMessages)->toHaveCount(20);

    // Verify these are the LAST 20 messages from the original (turns 16-25)
    expect($recentMessages[0]->content)->toBe('User message 16');
    expect($recentMessages[1]->content)->toBe('Assistant response 16');
    expect($recentMessages[18]->content)->toBe('User message 25');
    expect($recentMessages[19]->content)->toBe('Assistant response 25');
});

it('keeps exactly the last 10 turns when pruning', function (): void {
    $messages = buildMessages(30); // 30 turns = 60 messages
    $agent = Mockery::mock(VunnixAgent::class)->makePartial();
    $agent->shouldReceive('messages')->andReturn($messages);

    $prunedMessages = null;
    $agent->shouldReceive('setPrunedMessages')->once()->withArgs(function ($msgs) use (&$prunedMessages) {
        $prunedMessages = $msgs;

        return true;
    });

    $gateway = Mockery::mock(TextGateway::class);
    $gateway->shouldReceive('generateText')->once()->andReturn(
        new TextResponse('Conversation summary.', new Usage, new Meta)
    );

    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGateway')->andReturn($gateway);
    $provider->shouldReceive('cheapestTextModel')->andReturn('claude-haiku-4-5-20251001');

    $middleware = new PruneConversationHistory($provider);

    $prompt = new AgentPrompt($agent, 'test', [], $provider, 'claude-opus-4-20250514');
    $next = fn ($p) => new AgentResponse('test-id', 'response text', new Usage, new Meta);

    $middleware->handle($prompt, $next);

    // Summary + 20 recent messages = 21 total
    expect($prunedMessages)->toHaveCount(21);

    // Recent messages should be turns 21-30
    $recentMessages = array_slice($prunedMessages, 1);
    expect($recentMessages[0]->content)->toBe('User message 21');
    expect($recentMessages[19]->content)->toBe('Assistant response 30');
});

// ─── Summary content ───────────────────────────────────────────

it('sends older messages to the summarizer', function (): void {
    $messages = buildMessages(25); // turns 1-15 get summarized, 16-25 kept
    $agent = Mockery::mock(VunnixAgent::class)->makePartial();
    $agent->shouldReceive('messages')->andReturn($messages);
    $agent->shouldReceive('setPrunedMessages');

    $summarizedContent = null;
    $gateway = Mockery::mock(TextGateway::class);
    $gateway->shouldReceive('generateText')->once()
        ->withArgs(function ($provider, $model, $instructions, $msgs) use (&$summarizedContent) {
            // The instructions should mention summarization
            $summarizedContent = $instructions;

            // The messages sent for summarization should contain the older turns
            expect($msgs)->toBeArray();
            expect($msgs[0])->toBeInstanceOf(UserMessage::class);

            return true;
        })
        ->andReturn(new TextResponse('Summary text.', new Usage, new Meta));

    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGateway')->andReturn($gateway);
    $provider->shouldReceive('cheapestTextModel')->andReturn('claude-haiku-4-5-20251001');

    $middleware = new PruneConversationHistory($provider);

    $prompt = new AgentPrompt($agent, 'test', [], $provider, 'claude-opus-4-20250514');
    $next = fn ($p) => new AgentResponse('test-id', 'response text', new Usage, new Meta);

    $middleware->handle($prompt, $next);

    // The summarization instruction should mention retaining intent and decisions
    expect($summarizedContent)->toContain('intent');
    expect($summarizedContent)->toContain('decision');
});

// ─── Graceful failure ──────────────────────────────────────────

it('keeps all messages when summarization fails', function (): void {
    $messages = buildMessages(25);
    $agent = Mockery::mock(VunnixAgent::class)->makePartial();
    $agent->shouldReceive('messages')->andReturn($messages);
    // Should NOT receive setPrunedMessages when summarization fails
    $agent->shouldNotReceive('setPrunedMessages');

    $gateway = Mockery::mock(TextGateway::class);
    $gateway->shouldReceive('generateText')->once()
        ->andThrow(new RuntimeException('API error'));

    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGateway')->andReturn($gateway);
    $provider->shouldReceive('cheapestTextModel')->andReturn('claude-haiku-4-5-20251001');

    $middleware = new PruneConversationHistory($provider);

    $prompt = new AgentPrompt($agent, 'test', [], $provider, 'claude-opus-4-20250514');
    $next = fn ($p) => new AgentResponse('test-id', 'response text', new Usage, new Meta);

    // Should not throw — gracefully continues with full history
    $result = $middleware->handle($prompt, $next);

    expect($result)->toBeInstanceOf(AgentResponse::class);
});

// ─── Non-VunnixAgent passthrough ───────────────────────────────

it('passes through without pruning for non-VunnixAgent agents', function (): void {
    // If middleware is applied to a non-VunnixAgent, it should do nothing
    $agent = Mockery::mock(\Laravel\Ai\Contracts\Agent::class);
    $agent->shouldReceive('instructions')->andReturn('test');
    $agent->shouldNotReceive('messages');

    $provider = Mockery::mock(TextProvider::class);
    $middleware = new PruneConversationHistory($provider);

    $prompt = new AgentPrompt($agent, 'test', [], $provider, 'claude-opus-4-20250514');
    $next = fn ($p) => new AgentResponse('test-id', 'response text', new Usage, new Meta);

    $result = $middleware->handle($prompt, $next);

    expect($result)->toBeInstanceOf(AgentResponse::class);
});

// ─── Boundary: exactly 21 turns triggers pruning ───────────────

it('triggers pruning at exactly 21 turns', function (): void {
    $messages = buildMessages(21); // 21 turns = 42 messages, just over threshold
    $agent = Mockery::mock(VunnixAgent::class)->makePartial();
    $agent->shouldReceive('messages')->andReturn($messages);

    $prunedMessages = null;
    $agent->shouldReceive('setPrunedMessages')->once()->withArgs(function ($msgs) use (&$prunedMessages) {
        $prunedMessages = $msgs;

        return true;
    });

    $gateway = Mockery::mock(TextGateway::class);
    $gateway->shouldReceive('generateText')->once()->andReturn(
        new TextResponse('Summary.', new Usage, new Meta)
    );

    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGateway')->andReturn($gateway);
    $provider->shouldReceive('cheapestTextModel')->andReturn('claude-haiku-4-5-20251001');

    $middleware = new PruneConversationHistory($provider);

    $prompt = new AgentPrompt($agent, 'test', [], $provider, 'claude-opus-4-20250514');
    $next = fn ($p) => new AgentResponse('test-id', 'response text', new Usage, new Meta);

    $middleware->handle($prompt, $next);

    // At 21 turns: summary + last 10 turns (20 messages) = 21
    expect($prunedMessages)->toHaveCount(21);

    // Recent messages should be turns 12-21
    $recentMessages = array_slice($prunedMessages, 1);
    expect($recentMessages[0]->content)->toBe('User message 12');
    expect($recentMessages[19]->content)->toBe('Assistant response 21');
});

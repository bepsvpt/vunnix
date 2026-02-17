<?php

use App\Http\Responses\ResilientStreamResponse;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;

// ─── Helpers ────────────────────────────────────────────────────

/**
 * Create a StreamableAgentResponse with a generator that yields given events
 * as JSON strings, then optionally throws an exception.
 *
 * The Meta object is required — StreamedAgentResponse.__construct() expects non-null Meta
 * after successful iteration completes in getIterator().
 *
 * @param  list<array<string, mixed>>  $events
 */
function makeAgentResponse(array $events, ?\Throwable $exception = null): StreamableAgentResponse
{
    $generator = function () use ($events, $exception): \Generator {
        foreach ($events as $event) {
            yield json_encode($event, JSON_THROW_ON_ERROR);
        }
        if ($exception !== null) {
            throw $exception;
        }
    };

    return new StreamableAgentResponse('test-invocation', $generator, new Meta('anthropic', 'claude-opus-4-20250514'));
}

/**
 * Capture the streamed output from a ResilientStreamResponse.
 *
 * Uses two-level output buffering: our code calls ob_flush() for real-time
 * streaming, which flushes from the inner buffer to the outer buffer.
 * The outer buffer captures all flushed content for assertions.
 */
function captureStream(StreamableAgentResponse $agentResponse): string
{
    $response = ResilientStreamResponse::from($agentResponse);
    ob_start(); // outer — captures flushed content from inner
    ob_start(); // inner — code's ob_flush() sends from here to outer
    $response->sendContent();
    // Flush any remaining inner content to outer, then close inner
    ob_end_flush();

    return ob_get_clean() ?: ''; // capture outer
}

/**
 * Parse SSE output into an array of decoded events.
 *
 * @return list<mixed>
 */
function parseSSEOutput(string $output): array
{
    $events = [];
    $lines = explode("\n", $output);
    foreach ($lines as $line) {
        if (! str_starts_with($line, 'data: ')) {
            continue;
        }
        $payload = substr($line, 6);
        if ($payload === '[DONE]') {
            $events[] = '[DONE]';

            continue;
        }
        $decoded = json_decode($payload, true);
        if ($decoded !== null) {
            $events[] = $decoded;
        }
    }

    return $events;
}

// ─── Tests ──────────────────────────────────────────────────────

it('streams normal events followed by [DONE] when no error occurs', function (): void {
    $events = [
        ['type' => 'stream_start'],
        ['type' => 'text_delta', 'delta' => 'Hello'],
        ['type' => 'stream_end'],
    ];

    $output = captureStream(makeAgentResponse($events));
    $parsed = parseSSEOutput($output);

    expect($parsed)->toHaveCount(4);
    expect($parsed[0])->toBe(['type' => 'stream_start']);
    expect($parsed[1])->toBe(['type' => 'text_delta', 'delta' => 'Hello']);
    expect($parsed[2])->toBe(['type' => 'stream_end']);
    expect($parsed[3])->toBe('[DONE]');
});

it('emits rate_limited error event when RateLimitedException is thrown', function (): void {
    Log::shouldReceive('warning')
        ->once()
        ->with('ResilientStreamResponse: AI provider error during streaming', \Mockery::on(function (array $context): bool {
            return $context['code'] === 'rate_limited'
                && $context['retryable'] === true
                && $context['exception_class'] === RateLimitedException::class;
        }));

    $events = [['type' => 'stream_start']];
    $exception = RateLimitedException::forProvider('anthropic');

    $output = captureStream(makeAgentResponse($events, $exception));
    $parsed = parseSSEOutput($output);

    // Should have: stream_start, error event, [DONE]
    expect($parsed)->toHaveCount(3);
    expect($parsed[0])->toBe(['type' => 'stream_start']);
    expect($parsed[1]['type'])->toBe('error');
    expect($parsed[1]['error']['code'])->toBe('rate_limited');
    expect($parsed[1]['error']['retryable'])->toBeTrue();
    expect($parsed[1]['error']['message'])->toContain('temporarily busy');
    expect($parsed[2])->toBe('[DONE]');
});

it('emits overloaded error event when ProviderOverloadedException is thrown', function (): void {
    Log::shouldReceive('warning')
        ->once()
        ->with('ResilientStreamResponse: AI provider error during streaming', \Mockery::on(function (array $context): bool {
            return $context['code'] === 'overloaded' && $context['retryable'] === true;
        }));

    $events = [['type' => 'stream_start']];
    $exception = new ProviderOverloadedException('AI provider [anthropic] is overloaded.');

    $output = captureStream(makeAgentResponse($events, $exception));
    $parsed = parseSSEOutput($output);

    expect($parsed)->toHaveCount(3);
    expect($parsed[1]['type'])->toBe('error');
    expect($parsed[1]['error']['code'])->toBe('overloaded');
    expect($parsed[1]['error']['retryable'])->toBeTrue();
    expect($parsed[1]['error']['message'])->toContain('overloaded');
    expect($parsed[2])->toBe('[DONE]');
});

it('emits ai_error event for generic AiException', function (): void {
    Log::shouldReceive('warning')
        ->once()
        ->with('ResilientStreamResponse: AI provider error during streaming', \Mockery::on(function (array $context): bool {
            return $context['code'] === 'ai_error' && $context['retryable'] === false;
        }));

    $events = [['type' => 'stream_start']];
    $exception = new AiException('Something went wrong');

    $output = captureStream(makeAgentResponse($events, $exception));
    $parsed = parseSSEOutput($output);

    expect($parsed)->toHaveCount(3);
    expect($parsed[1]['type'])->toBe('error');
    expect($parsed[1]['error']['code'])->toBe('ai_error');
    expect($parsed[1]['error']['retryable'])->toBeFalse();
    expect($parsed[2])->toBe('[DONE]');
});

it('sets correct response headers', function (): void {
    $agentResponse = makeAgentResponse([['type' => 'stream_start']]);
    $response = ResilientStreamResponse::from($agentResponse);

    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->get('Content-Type'))->toBe('text/event-stream');
    expect($response->headers->get('Cache-Control'))->toContain('no-cache');
    expect($response->headers->get('X-Accel-Buffering'))->toBe('no');
});

it('always emits [DONE] even after error events', function (): void {
    Log::spy();

    $exception = RateLimitedException::forProvider('anthropic');
    $output = captureStream(makeAgentResponse([], $exception));

    // The output must end with [DONE] so the frontend stream parser terminates
    expect($output)->toContain("data: [DONE]\n\n");
});

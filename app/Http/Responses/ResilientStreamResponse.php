<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Wraps StreamableAgentResponse with exception handling for AI provider errors.
 *
 * When the Anthropic API returns a rate limit (429) or overloaded (529) response
 * mid-stream, the AI SDK throws an exception that would crash the SSE connection.
 * This wrapper catches those exceptions and emits structured SSE error events,
 * allowing the frontend to display user-friendly messages and recover gracefully.
 *
 * @see D187 — Structured SSE error event for AI provider failures
 */
class ResilientStreamResponse
{
    /**
     * Wrap a StreamableAgentResponse in a resilient StreamedResponse.
     */
    public static function from(StreamableAgentResponse $agentResponse): StreamedResponse
    {
        return new StreamedResponse(
            function () use ($agentResponse): void {
                try {
                    foreach ($agentResponse as $event) {
                        if (connection_aborted() !== 0) {
                            return;
                        }

                        echo 'data: '.($event)."\n\n";

                        if (ob_get_level() > 0) {
                            ob_flush();
                        }

                        flush();
                    }
                } catch (RateLimitedException $e) {
                    self::emitErrorEvent('rate_limited', 'The AI service is temporarily busy. Please try again in a moment.', true, $e);
                } catch (ProviderOverloadedException $e) {
                    self::emitErrorEvent('overloaded', 'The AI service is currently overloaded. Please try again shortly.', true, $e);
                } catch (AiException $e) {
                    self::emitErrorEvent('ai_error', 'An error occurred while generating the response.', false, $e);
                } catch (Throwable $e) {
                    self::emitErrorEvent('internal_error', 'An unexpected error occurred while generating the response.', false, $e);
                }

                echo "data: [DONE]\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    /**
     * Emit a structured SSE error event and log the exception.
     */
    private static function emitErrorEvent(string $code, string $message, bool $retryable, Throwable $exception): void
    {
        Log::warning('ResilientStreamResponse: AI provider error during streaming', [
            'code' => $code,
            'retryable' => $retryable,
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
        ]);

        $errorPayload = json_encode([
            'type' => 'error',
            'error' => [
                'message' => $message,
                'code' => $code,
                'retryable' => $retryable,
            ],
        ], JSON_THROW_ON_ERROR);

        echo 'data: '.$errorPayload."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}

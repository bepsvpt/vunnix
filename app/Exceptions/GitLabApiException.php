<?php

namespace App\Exceptions;

use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Throwable;

/**
 * GitLab API error with classification for retry decisions.
 *
 * Wraps HTTP errors from GitLab API calls and classifies them as:
 * - Transient (429, 500, 503, 529) → eligible for retry
 * - Invalid request (400) → no retry, alert
 * - Authentication (401) → no retry, admin alert
 * - Context exceeded (specific body patterns) → no retry
 *
 * @see §15.5 Model Failure & Retry
 * @see §19.3 Job Timeout & Retry Policy
 */
class GitLabApiException extends RuntimeException
{
    /**
     * HTTP status codes that indicate transient failures.
     */
    private const TRANSIENT_CODES = [429, 500, 503, 529];

    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly string $responseBody,
        public readonly string $context,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * Create from a Laravel HTTP client RequestException.
     */
    public static function fromRequestException(RequestException $exception, string $context): self
    {
        $response = $exception->response;

        return new self(
            message: "GitLab API error ({$context}): {$response->status()} {$response->body()}",
            statusCode: $response->status(),
            responseBody: $response->body(),
            context: $context,
            previous: $exception,
        );
    }

    /**
     * Whether this error is transient and should be retried.
     */
    public function isTransient(): bool
    {
        return in_array($this->statusCode, self::TRANSIENT_CODES, true);
    }

    /**
     * Whether this is an invalid request (400) — no retry.
     */
    public function isInvalidRequest(): bool
    {
        return $this->statusCode === 400;
    }

    /**
     * Whether this is an authentication error (401) — no retry, admin alert.
     */
    public function isAuthenticationError(): bool
    {
        return $this->statusCode === 401;
    }

    /**
     * Whether this error should be retried.
     */
    public function shouldRetry(): bool
    {
        return $this->isTransient();
    }

    /**
     * Get a human-readable classification of the error.
     */
    public function classification(): string
    {
        return match (true) {
            $this->isTransient() => 'transient',
            $this->isInvalidRequest() => 'invalid_request',
            $this->isAuthenticationError() => 'authentication',
            default => 'unknown',
        };
    }
}

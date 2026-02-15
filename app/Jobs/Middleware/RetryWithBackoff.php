<?php

namespace App\Jobs\Middleware;

use App\Exceptions\GitLabApiException;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Job middleware that implements error-specific retry with exponential backoff.
 *
 * Backoff schedule: 30s → 2m → 8m (max 3 retries).
 *
 * Error classification:
 * - Transient (429, 500, 503, 529): retry with backoff
 * - Invalid request (400): fail immediately, no retry
 * - Authentication (401): fail immediately, admin alert
 * - Other errors: fail immediately
 *
 * @see §19.3 Job Timeout & Retry Policy
 * @see §15.5 Model Failure & Retry
 */
class RetryWithBackoff
{
    /**
     * Backoff delays in seconds: 30s, 2m, 8m.
     */
    private const BACKOFF_SECONDS = [30, 120, 480];

    /**
     * Maximum number of retries (not counting the initial attempt).
     * Total attempts = 1 initial + MAX_RETRIES = 4.
     */
    private const MAX_RETRIES = 3;

    public function handle(object $job, Closure $next): void
    {
        try {
            $next($job);
        } catch (GitLabApiException $e) {
            $this->handleGitLabException($job, $e);
        }
    }

    private function handleGitLabException(object $job, GitLabApiException $e): void
    {
        $attempts = $job->attempts();

        Log::warning('RetryWithBackoff: GitLab API error', [
            'job' => $job::class,
            'attempt' => $attempts,
            'status_code' => $e->statusCode,
            'classification' => $e->classification(),
            'context' => $e->context,
        ]);

        if ($e->isAuthenticationError()) {
            Log::critical('RetryWithBackoff: authentication failure — admin alert needed', [
                'job' => $job::class,
                'context' => $e->context,
                'message' => $e->getMessage(),
            ]);
            $job->fail($e);

            return;
        }

        if ($e->isInvalidRequest()) {
            Log::error('RetryWithBackoff: invalid request — not retrying', [
                'job' => $job::class,
                'context' => $e->context,
                'body' => $e->responseBody,
            ]);
            $job->fail($e);

            return;
        }

        if (! $e->shouldRetry()) {
            Log::error('RetryWithBackoff: non-retryable error', [
                'job' => $job::class,
                'status_code' => $e->statusCode,
                'context' => $e->context,
            ]);
            $job->fail($e);

            return;
        }

        // Transient error — retry if we haven't exceeded max retries
        // attempts() returns 1 on first try, 2 on first retry, etc.
        // So retries used = attempts - 1. Fail when retries used >= MAX_RETRIES.
        if ($attempts > self::MAX_RETRIES) {
            Log::error('RetryWithBackoff: max retries exceeded', [
                'job' => $job::class,
                'attempts' => $attempts,
                'max_retries' => self::MAX_RETRIES,
                'context' => $e->context,
            ]);
            $job->fail($e);

            return;
        }

        // Record this attempt in the job's history for DLQ persistence
        if (property_exists($job, 'attemptHistory')) {
            $job->attemptHistory[] = [
                'attempt' => $attempts,
                'timestamp' => now()->toIso8601String(),
                'error' => "HTTP {$e->statusCode}: ".mb_substr($e->responseBody, 0, 500),
            ];
        }

        // Backoff index: attempt 1 → backoff[0]=30s, attempt 2 → backoff[1]=120s, attempt 3 → backoff[2]=480s
        $delay = self::BACKOFF_SECONDS[$attempts - 1] ?? end(self::BACKOFF_SECONDS);

        Log::info('RetryWithBackoff: releasing for retry', [
            'job' => $job::class,
            'attempt' => $attempts,
            'delay_seconds' => $delay,
            'context' => $e->context,
        ]);

        $job->release($delay);
    }
}

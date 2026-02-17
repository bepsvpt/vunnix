<?php

use App\Exceptions\GitLabApiException;
use App\Jobs\Middleware\RetryWithBackoff;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    Log::spy();
});

/**
 * Fake job class to test middleware behavior.
 * Tracks calls to release(), fail(), and the exception thrown from $next.
 */
class FakeJob
{
    public int $attemptCount = 1;

    public ?int $releasedWithDelay = null;

    public ?\Throwable $failedWith = null;

    public ?\Closure $handler = null;

    public function attempts(): int
    {
        return $this->attemptCount;
    }

    public function release(int $delay = 0): void
    {
        $this->releasedWithDelay = $delay;
    }

    public function fail(\Throwable $exception): void
    {
        $this->failedWith = $exception;
    }
}

function makeGitLabException(int $statusCode): GitLabApiException
{
    return new GitLabApiException(
        message: "HTTP {$statusCode}",
        statusCode: $statusCode,
        responseBody: '',
        context: 'test',
    );
}

function runMiddleware(FakeJob $job, \Closure $next): void
{
    $middleware = new RetryWithBackoff;
    $middleware->handle($job, $next);
}

// ─── Transient errors → retry with backoff ─────────────────────────

it('retries transient 429 on first attempt with 30s delay', function (): void {
    $job = new FakeJob;
    $job->attemptCount = 1;

    runMiddleware($job, function (): void {
        throw makeGitLabException(429);
    });

    expect($job->releasedWithDelay)->toBe(30);
    expect($job->failedWith)->toBeNull();
});

it('retries transient 500 on second attempt with 120s delay', function (): void {
    $job = new FakeJob;
    $job->attemptCount = 2;

    runMiddleware($job, function (): void {
        throw makeGitLabException(500);
    });

    expect($job->releasedWithDelay)->toBe(120);
    expect($job->failedWith)->toBeNull();
});

it('retries transient 503 on third attempt with 480s delay', function (): void {
    $job = new FakeJob;
    $job->attemptCount = 3;

    runMiddleware($job, function (): void {
        throw makeGitLabException(503);
    });

    expect($job->releasedWithDelay)->toBe(480);
    expect($job->failedWith)->toBeNull();
});

it('retries transient 529 (overloaded)', function (): void {
    $job = new FakeJob;
    $job->attemptCount = 1;

    runMiddleware($job, function (): void {
        throw makeGitLabException(529);
    });

    expect($job->releasedWithDelay)->toBe(30);
    expect($job->failedWith)->toBeNull();
});

it('fails after max retries on transient error', function (): void {
    $job = new FakeJob;
    $job->attemptCount = 4; // Beyond max retries (3)

    runMiddleware($job, function (): void {
        throw makeGitLabException(503);
    });

    expect($job->failedWith)->toBeInstanceOf(GitLabApiException::class);
    expect($job->releasedWithDelay)->toBeNull();
});

// ─── Invalid request (400) → no retry ──────────────────────────────

it('fails immediately on 400 without retry', function (): void {
    $job = new FakeJob;
    $job->attemptCount = 1;

    runMiddleware($job, function (): void {
        throw makeGitLabException(400);
    });

    expect($job->failedWith)->toBeInstanceOf(GitLabApiException::class);
    expect($job->failedWith->statusCode)->toBe(400);
    expect($job->releasedWithDelay)->toBeNull();
});

// ─── Authentication error (401) → no retry + admin alert ───────────

it('fails immediately on 401 without retry', function (): void {
    $job = new FakeJob;
    $job->attemptCount = 1;

    runMiddleware($job, function (): void {
        throw makeGitLabException(401);
    });

    expect($job->failedWith)->toBeInstanceOf(GitLabApiException::class);
    expect($job->failedWith->statusCode)->toBe(401);
    expect($job->releasedWithDelay)->toBeNull();
});

it('logs critical on 401 for admin alert', function (): void {
    Log::shouldReceive('warning')->once()->withAnyArgs();
    Log::shouldReceive('critical')->once()
        ->withArgs(function (string $message) {
            return str_contains($message, 'authentication failure');
        });

    $job = new FakeJob;
    $job->attemptCount = 1;

    runMiddleware($job, function (): void {
        throw makeGitLabException(401);
    });

    // Mockery expectations verified on teardown; add explicit assertion for Pest
    expect($job->failedWith)->toBeInstanceOf(GitLabApiException::class);
});

// ─── Other errors → no retry ───────────────────────────────────────

it('fails immediately on 403 without retry', function (): void {
    $job = new FakeJob;
    $job->attemptCount = 1;

    runMiddleware($job, function (): void {
        throw makeGitLabException(403);
    });

    expect($job->failedWith)->toBeInstanceOf(GitLabApiException::class);
    expect($job->releasedWithDelay)->toBeNull();
});

it('fails immediately on 404 without retry', function (): void {
    $job = new FakeJob;
    $job->attemptCount = 1;

    runMiddleware($job, function (): void {
        throw makeGitLabException(404);
    });

    expect($job->failedWith)->toBeInstanceOf(GitLabApiException::class);
    expect($job->releasedWithDelay)->toBeNull();
});

// ─── Success → no middleware action ────────────────────────────────

it('passes through when job succeeds', function (): void {
    $job = new FakeJob;
    $executed = false;

    runMiddleware($job, function () use (&$executed): void {
        $executed = true;
    });

    expect($executed)->toBeTrue();
    expect($job->releasedWithDelay)->toBeNull();
    expect($job->failedWith)->toBeNull();
});

// ─── Non-GitLabApiException → not caught ───────────────────────────

it('does not catch non-GitLabApiException', function (): void {
    $job = new FakeJob;

    expect(fn () => runMiddleware($job, function (): void {
        throw new \RuntimeException('Some other error');
    }))->toThrow(\RuntimeException::class, 'Some other error');
});

// ─── Backoff schedule verification ─────────────────────────────────

it('follows exact backoff schedule: 30s → 120s → 480s', function (): void {
    $delays = [];

    foreach ([1, 2, 3] as $attempt) {
        $job = new FakeJob;
        $job->attemptCount = $attempt;

        runMiddleware($job, function (): void {
            throw makeGitLabException(503);
        });

        $delays[] = $job->releasedWithDelay;
    }

    expect($delays)->toBe([30, 120, 480]);
});

// ─── All jobs have middleware configured ────────────────────────────

it('is configured on ProcessTask', function (): void {
    $job = new \App\Jobs\ProcessTask(1);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(RetryWithBackoff::class);
});

it('is configured on ProcessTaskResult', function (): void {
    $job = new \App\Jobs\ProcessTaskResult(1);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(RetryWithBackoff::class);
});

it('is configured on PostSummaryComment', function (): void {
    $job = new \App\Jobs\PostSummaryComment(1);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(RetryWithBackoff::class);
});

it('is configured on PostInlineThreads', function (): void {
    $job = new \App\Jobs\PostInlineThreads(1);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(RetryWithBackoff::class);
});

it('is configured on PostLabelsAndStatus', function (): void {
    $job = new \App\Jobs\PostLabelsAndStatus(1);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(RetryWithBackoff::class);
});

it('is configured on PostPlaceholderComment', function (): void {
    $job = new \App\Jobs\PostPlaceholderComment(1);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(RetryWithBackoff::class);
});

it('is configured on PostHelpResponse', function (): void {
    $job = new \App\Jobs\PostHelpResponse(1, 1, 'unknown');
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(RetryWithBackoff::class);
});

// ─── Jobs have correct tries ───────────────────────────────────────

it('sets tries to 4 on all jobs', function (): void {
    $jobs = [
        new \App\Jobs\ProcessTask(1),
        new \App\Jobs\ProcessTaskResult(1),
        new \App\Jobs\PostSummaryComment(1),
        new \App\Jobs\PostInlineThreads(1),
        new \App\Jobs\PostLabelsAndStatus(1),
        new \App\Jobs\PostPlaceholderComment(1),
        new \App\Jobs\PostHelpResponse(1, 1, 'test'),
    ];

    foreach ($jobs as $job) {
        expect($job->tries)->toBe(4, $job::class.' should have tries = 4');
    }
});

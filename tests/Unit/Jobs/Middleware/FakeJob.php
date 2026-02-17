<?php

namespace Tests\Unit\Jobs\Middleware;

use Closure;
use Throwable;

/**
 * Fake job class to test middleware behavior.
 * Tracks calls to release(), fail(), and the exception thrown from $next.
 */
class FakeJob
{
    public int $attemptCount = 1;

    public ?int $releasedWithDelay = null;

    public ?Throwable $failedWith = null;

    public ?Closure $handler = null;

    public function attempts(): int
    {
        return $this->attemptCount;
    }

    public function release(int $delay = 0): void
    {
        $this->releasedWithDelay = $delay;
    }

    public function fail(Throwable $exception): void
    {
        $this->failedWith = $exception;
    }
}

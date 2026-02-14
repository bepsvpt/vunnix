<?php

namespace App\Support;

/**
 * Queue name constants (D134).
 *
 * Two queue groups:
 * - vunnix-server: Immediate server-side tasks (Issue creation, help responses)
 * - vunnix-runner-{priority}: CI pipeline tasks processed by priority
 *
 * These names must match docker-compose.yml worker --queue arguments.
 */
final class QueueNames
{
    public const SERVER = 'vunnix-server';

    public const RUNNER_HIGH = 'vunnix-runner-high';

    public const RUNNER_NORMAL = 'vunnix-runner-normal';

    public const RUNNER_LOW = 'vunnix-runner-low';
}

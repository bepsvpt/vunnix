<?php

use App\Enums\TaskPriority;

it('returns high runner queue name', function (): void {
    expect(TaskPriority::High->runnerQueueName())->toBe('vunnix-runner-high');
});

it('returns normal runner queue name', function (): void {
    expect(TaskPriority::Normal->runnerQueueName())->toBe('vunnix-runner-normal');
});

it('returns low runner queue name', function (): void {
    expect(TaskPriority::Low->runnerQueueName())->toBe('vunnix-runner-low');
});

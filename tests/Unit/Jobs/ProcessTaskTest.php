<?php

use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Jobs\ProcessTask;
use App\Models\Task;

it('routes server-side task to vunnix-server queue', function (): void {
    $task = new Task;
    $task->type = TaskType::PrdCreation;
    $task->priority = TaskPriority::Normal;

    $job = new ProcessTask(1);
    $job->resolveQueue($task);

    expect($job->queue)->toBe('vunnix-server');
});

it('routes runner task to priority-specific queue', function (): void {
    $task = new Task;
    $task->type = TaskType::CodeReview;
    $task->priority = TaskPriority::High;

    $job = new ProcessTask(1);
    $job->resolveQueue($task);

    expect($job->queue)->toBe('vunnix-runner-high');
});

it('routes normal priority runner task correctly', function (): void {
    $task = new Task;
    $task->type = TaskType::CodeReview;
    $task->priority = TaskPriority::Normal;

    $job = new ProcessTask(1);
    $job->resolveQueue($task);

    expect($job->queue)->toBe('vunnix-runner-normal');
});

it('routes low priority runner task correctly', function (): void {
    $task = new Task;
    $task->type = TaskType::FeatureDev;
    $task->priority = TaskPriority::Low;

    $job = new ProcessTask(1);
    $job->resolveQueue($task);

    expect($job->queue)->toBe('vunnix-runner-low');
});

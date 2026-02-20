<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use App\Services\Architecture\IterationMetricsCollector;
use Carbon\Carbon;

function makeIterationMetricsCollectorTask(
    TaskStatus $status,
    ?int $mrIid = null,
    ?array $result = null,
    mixed $createdAt = null,
    mixed $updatedAt = null,
): Task {
    $task = new Task;
    $rawResult = is_array($result) ? json_encode($result) : $result;
    $task->setRawAttributes([
        'status' => $status->value,
        'type' => TaskType::CodeReview->value,
        'mr_iid' => $mrIid,
        'result' => $rawResult,
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
    ], true);

    return $task;
}

/**
 * @param  list<mixed>  $args
 */
function callIterationMetricsCollectorMethod(IterationMetricsCollector $collector, string $method, array $args): mixed
{
    $reflection = new ReflectionMethod(IterationMetricsCollector::class, $method);

    return $reflection->invokeArgs($collector, $args);
}

it('extractFilesChangedCounts skips completed tasks with non-array files_changed values', function (): void {
    $collector = new IterationMetricsCollector;

    $tasks = collect([
        makeIterationMetricsCollectorTask(TaskStatus::Completed, result: ['files_changed' => 'not-an-array']),
    ]);

    /** @var array<int, float> $counts */
    $counts = callIterationMetricsCollectorMethod($collector, 'extractFilesChangedCounts', [$tasks]);

    expect($counts)->toBe([]);
});

it('extractLeadTimes skips completed tasks when timestamps are not Carbon instances', function (): void {
    $collector = new IterationMetricsCollector;

    $tasks = collect([
        makeIterationMetricsCollectorTask(TaskStatus::Completed, createdAt: null, updatedAt: null),
    ]);

    /** @var array<int, float> $leadTimes */
    $leadTimes = callIterationMetricsCollectorMethod($collector, 'extractLeadTimes', [$tasks]);

    expect($leadTimes)->toBe([]);
});

it('countReopenedRegressions ignores follow-up candidates without mr_iid', function (): void {
    $collector = new IterationMetricsCollector;

    $failed = makeIterationMetricsCollectorTask(
        TaskStatus::Failed,
        mrIid: 42,
        createdAt: Carbon::parse('2026-02-19 10:00:00'),
        updatedAt: Carbon::parse('2026-02-19 10:05:00'),
    );
    $completedWithoutMr = makeIterationMetricsCollectorTask(
        TaskStatus::Completed,
        mrIid: null,
        createdAt: Carbon::parse('2026-02-19 11:00:00'),
        updatedAt: Carbon::parse('2026-02-19 11:10:00'),
    );

    $count = callIterationMetricsCollectorMethod($collector, 'countReopenedRegressions', [collect([$failed, $completedWithoutMr])]);

    expect($count)->toBe(0);
});

it('median returns null for empty values and middle value for odd-length arrays', function (): void {
    $collector = new IterationMetricsCollector;

    $empty = callIterationMetricsCollectorMethod($collector, 'median', [[]]);
    $odd = callIterationMetricsCollectorMethod($collector, 'median', [[5.0, 1.0, 3.0]]);

    expect($empty)->toBeNull()
        ->and($odd)->toBe(3.0);
});

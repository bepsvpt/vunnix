<?php

use App\Enums\TaskType;
use App\Jobs\ExtractReviewPatterns;
use App\Models\Task;
use App\Modules\TaskOrchestration\Application\Publishers\ReviewPatternExtractionPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('returns false when memory feature flags are disabled', function (): void {
    config()->set('vunnix.memory.enabled', false);
    config()->set('vunnix.memory.review_learning', true);

    $publisher = new ReviewPatternExtractionPublisher;
    $task = Task::factory()->make([
        'type' => TaskType::CodeReview,
        'result' => ['findings' => [['title' => 'x']]],
    ]);

    expect($publisher->supports($task))->toBeFalse();
});

it('returns true only for review task types with findings', function (): void {
    config()->set('vunnix.memory.enabled', true);
    config()->set('vunnix.memory.review_learning', true);

    $publisher = new ReviewPatternExtractionPublisher;
    $valid = Task::factory()->make([
        'type' => TaskType::CodeReview,
        'result' => ['findings' => [['title' => 'x']]],
    ]);
    $wrongType = Task::factory()->make([
        'type' => TaskType::IssueDiscussion,
        'result' => ['findings' => [['title' => 'x']]],
    ]);
    $noFindings = Task::factory()->make([
        'type' => TaskType::CodeReview,
        'result' => ['findings' => []],
    ]);

    expect($publisher->supports($valid))->toBeTrue()
        ->and($publisher->supports($wrongType))->toBeFalse()
        ->and($publisher->supports($noFindings))->toBeFalse();
});

it('dispatches ExtractReviewPatterns when publishing', function (): void {
    config()->set('vunnix.memory.enabled', true);
    config()->set('vunnix.memory.review_learning', true);

    Queue::fake();

    $publisher = new ReviewPatternExtractionPublisher;
    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'result' => ['findings' => [['title' => 'x']]],
    ]);

    $publisher->publish($task);

    Queue::assertPushed(ExtractReviewPatterns::class, function (ExtractReviewPatterns $job) use ($task): bool {
        return $job->taskId === $task->id;
    });
});

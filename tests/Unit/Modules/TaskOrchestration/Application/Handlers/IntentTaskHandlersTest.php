<?php

use App\Enums\TaskType;
use App\Events\Webhook\MergeRequestOpened;
use App\Modules\TaskOrchestration\Application\Handlers\CodeReviewIntentTaskHandler;
use App\Modules\TaskOrchestration\Application\Handlers\FeatureDevelopmentIntentTaskHandler;
use App\Modules\TaskOrchestration\Application\Handlers\IssueDiscussionIntentTaskHandler;
use App\Services\RoutingResult;

it('returns null for unsupported intents in CodeReviewIntentTaskHandler', function (): void {
    $handler = new CodeReviewIntentTaskHandler;
    $event = new MergeRequestOpened(1, 1001, [], 42, 'feature/x', 'main', 7, 'abc');
    $supported = new RoutingResult('auto_review', 'normal', $event);
    $unsupported = new RoutingResult('feature_dev', 'normal', $event);

    expect($handler->priority())->toBe(100)
        ->and($handler->supports($supported))->toBeTrue()
        ->and($handler->resolveTaskType($supported))->toBe(TaskType::CodeReview)
        ->and($handler->supports($unsupported))->toBeFalse()
        ->and($handler->resolveTaskType($unsupported))->toBeNull();
});

it('returns null for unsupported intents in FeatureDevelopmentIntentTaskHandler', function (): void {
    $handler = new FeatureDevelopmentIntentTaskHandler;
    $event = new MergeRequestOpened(1, 1001, [], 42, 'feature/x', 'main', 7, 'abc');
    $supported = new RoutingResult('feature_dev', 'normal', $event);
    $unsupported = new RoutingResult('auto_review', 'normal', $event);

    expect($handler->priority())->toBe(80)
        ->and($handler->supports($supported))->toBeTrue()
        ->and($handler->resolveTaskType($supported))->toBe(TaskType::FeatureDev)
        ->and($handler->supports($unsupported))->toBeFalse()
        ->and($handler->resolveTaskType($unsupported))->toBeNull();
});

it('returns null for unsupported intents in IssueDiscussionIntentTaskHandler', function (): void {
    $handler = new IssueDiscussionIntentTaskHandler;
    $event = new MergeRequestOpened(1, 1001, [], 42, 'feature/x', 'main', 7, 'abc');
    $supported = new RoutingResult('ask_command', 'normal', $event);
    $unsupported = new RoutingResult('auto_review', 'normal', $event);

    expect($handler->priority())->toBe(90)
        ->and($handler->supports($supported))->toBeTrue()
        ->and($handler->resolveTaskType($supported))->toBe(TaskType::IssueDiscussion)
        ->and($handler->supports($unsupported))->toBeFalse()
        ->and($handler->resolveTaskType($unsupported))->toBeNull();
});

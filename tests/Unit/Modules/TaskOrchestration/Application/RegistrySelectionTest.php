<?php

use App\Enums\TaskType;
use App\Events\Webhook\MergeRequestOpened;
use App\Events\Webhook\WebhookEvent;
use App\Models\Task;
use App\Modules\TaskOrchestration\Application\Contracts\IntentClassifier;
use App\Modules\TaskOrchestration\Application\Contracts\ResultPublisher;
use App\Modules\TaskOrchestration\Application\Contracts\TaskHandler;
use App\Modules\TaskOrchestration\Application\Registries\IntentClassifierRegistry;
use App\Modules\TaskOrchestration\Application\Registries\ResultPublisherRegistry;
use App\Modules\TaskOrchestration\Application\Registries\TaskHandlerRegistry;
use App\Services\RoutingResult;

function makeRegistrySelectionWebhookEvent(): WebhookEvent
{
    return new MergeRequestOpened(
        projectId: 1,
        gitlabProjectId: 1001,
        payload: [],
        mergeRequestIid: 42,
        sourceBranch: 'feature/test',
        targetBranch: 'main',
        authorId: 99,
        lastCommitSha: 'abc123',
    );
}

final class RegistrySelectionAlphaClassifier implements IntentClassifier
{
    public function priority(): int
    {
        return 10;
    }

    public function supports(WebhookEvent $event): bool
    {
        return true;
    }

    public function classify(WebhookEvent $event): \App\Services\RoutingResult
    {
        return new RoutingResult('alpha', 'normal', $event);
    }
}

final class RegistrySelectionBetaClassifier implements IntentClassifier
{
    public function priority(): int
    {
        return 10;
    }

    public function supports(WebhookEvent $event): bool
    {
        return true;
    }

    public function classify(WebhookEvent $event): \App\Services\RoutingResult
    {
        return new RoutingResult('beta', 'normal', $event);
    }
}

final class RegistrySelectionHighPriorityHandler implements TaskHandler
{
    public function priority(): int
    {
        return 20;
    }

    public function supports(RoutingResult $routingResult): bool
    {
        return $routingResult->intent === 'auto_review';
    }

    public function resolveTaskType(RoutingResult $routingResult): \App\Enums\TaskType
    {
        return TaskType::CodeReview;
    }
}

final class RegistrySelectionLowPriorityHandler implements TaskHandler
{
    public function priority(): int
    {
        return 5;
    }

    public function supports(RoutingResult $routingResult): bool
    {
        return $routingResult->intent === 'auto_review';
    }

    public function resolveTaskType(RoutingResult $routingResult): \App\Enums\TaskType
    {
        return TaskType::FeatureDev;
    }
}

final class RegistrySelectionAlphaPublisher implements ResultPublisher
{
    /**
     * @param  array<int, string>  $log
     */
    public function __construct(
        private array &$log,
    ) {}

    public function priority(): int
    {
        return 10;
    }

    public function supports(Task $task): bool
    {
        return true;
    }

    public function publish(Task $task): void
    {
        $this->log[] = 'alpha';
    }
}

final class RegistrySelectionBetaPublisher implements ResultPublisher
{
    /**
     * @param  array<int, string>  $log
     */
    public function __construct(
        private array &$log,
    ) {}

    public function priority(): int
    {
        return 10;
    }

    public function supports(Task $task): bool
    {
        return true;
    }

    public function publish(Task $task): void
    {
        $this->log[] = 'beta';
    }
}

it('selects intent classifier deterministically by priority and class name', function (): void {
    $event = makeRegistrySelectionWebhookEvent();
    $registry = new IntentClassifierRegistry([
        new RegistrySelectionBetaClassifier,
        new RegistrySelectionAlphaClassifier,
    ]);

    $resolved = $registry->resolve($event);
    $result = $registry->classify($event);

    expect($resolved)->toBeInstanceOf(RegistrySelectionAlphaClassifier::class);
    expect($result?->intent)->toBe('alpha');
});

it('selects task handler by highest priority when multiple handlers support', function (): void {
    $event = makeRegistrySelectionWebhookEvent();
    $routingResult = new RoutingResult('auto_review', 'normal', $event);
    $registry = new TaskHandlerRegistry([
        new RegistrySelectionLowPriorityHandler,
        new RegistrySelectionHighPriorityHandler,
    ]);

    $resolved = $registry->resolve($routingResult);
    $taskType = $registry->resolveTaskType($routingResult);

    expect($resolved)->toBeInstanceOf(RegistrySelectionHighPriorityHandler::class);
    expect($taskType)->toBe(TaskType::CodeReview);
});

it('publishes matching handlers deterministically', function (): void {
    $log = [];
    $registry = new ResultPublisherRegistry([
        new RegistrySelectionBetaPublisher($log),
        new RegistrySelectionAlphaPublisher($log),
    ]);
    $task = new Task;

    $registry->publish($task);

    expect($log)->toBe(['alpha', 'beta']);
});

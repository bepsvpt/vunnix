<?php

use App\Events\Webhook\MergeRequestMerged;
use App\Events\Webhook\PushToMRBranch;
use App\Http\Controllers\WebhookController;
use App\Models\Project;
use App\Services\GitLabClient;
use App\Services\RoutingResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(Tests\TestCase::class);

function webhookCoverageProject(): Project
{
    $project = new Project;
    $project->id = 1;
    $project->gitlab_project_id = 101;

    return $project;
}

it('returns false when permission-required intent has no extractable author id', function (): void {
    $controller = new WebhookController;
    $project = webhookCoverageProject();
    $event = new PushToMRBranch(
        projectId: $project->id,
        gitlabProjectId: $project->gitlab_project_id,
        payload: [],
        ref: 'refs/heads/feature/test',
        beforeSha: 'abc',
        afterSha: 'def',
        userId: 123,
        commits: [],
        totalCommitsCount: 0,
    );
    $routingResult = new RoutingResult('ask_command', 'normal', $event);

    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Webhook permission check: no author ID on event, dropping'
            && $context['intent'] === 'ask_command');

    $method = new ReflectionMethod(WebhookController::class, 'hasRequiredPermission');
    $method->setAccessible(true);
    $allowed = $method->invoke($controller, $routingResult, $project);

    expect($allowed)->toBeFalse();
});

it('returns early in acceptance tracking dispatch when event is not MergeRequestMerged', function (): void {
    Queue::fake();

    $controller = new WebhookController;
    $project = webhookCoverageProject();
    $routingResult = new RoutingResult(
        'acceptance_tracking',
        'normal',
        new PushToMRBranch($project->id, $project->gitlab_project_id, [], 'refs/heads/x', 'a', 'b', 1, [], 0),
    );

    $method = new ReflectionMethod(WebhookController::class, 'dispatchAcceptanceTracking');
    $method->setAccessible(true);
    $method->invoke($controller, $routingResult, $project);

    Queue::assertNothingPushed();
});

it('returns early in code correlation dispatch when event is not PushToMRBranch', function (): void {
    Queue::fake();

    $controller = new WebhookController;
    $project = webhookCoverageProject();
    $routingResult = new RoutingResult(
        'incremental_review',
        'normal',
        new MergeRequestMerged($project->id, $project->gitlab_project_id, [], 42, 'feature/x', 'main', 7, 'sha'),
    );

    $method = new ReflectionMethod(WebhookController::class, 'dispatchCodeChangeCorrelation');
    $method->setAccessible(true);
    $method->invoke($controller, $routingResult, $project);

    Queue::assertNothingPushed();
});

it('returns early from code correlation dispatch when no MR is found for branch', function (): void {
    Queue::fake();

    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldReceive('findOpenMergeRequestForBranch')
        ->once()
        ->andReturnNull();
    app()->instance(GitLabClient::class, $gitLab);

    $controller = new WebhookController;
    $project = webhookCoverageProject();
    $routingResult = new RoutingResult(
        'incremental_review',
        'normal',
        new PushToMRBranch($project->id, $project->gitlab_project_id, [], 'refs/heads/feature/x', 'a', 'b', 1, [], 0),
    );

    $method = new ReflectionMethod(WebhookController::class, 'dispatchCodeChangeCorrelation');
    $method->setAccessible(true);
    $method->invoke($controller, $routingResult, $project);

    Queue::assertNothingPushed();
});

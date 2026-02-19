<?php

use App\Events\Webhook\IssueLabelChanged;
use App\Events\Webhook\MergeRequestOpened;
use App\Events\Webhook\NoteOnIssue;
use App\Events\Webhook\NoteOnMR;
use App\Models\Project;
use App\Models\User;
use App\Services\RoutingResult;
use App\Services\TaskDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('produces equivalent task type mapping with kernel flag on and off', function (): void {
    Queue::fake();

    $project = Project::factory()->create();
    $user = User::factory()->create();
    $service = app(TaskDispatchService::class);

    $cases = [
        'auto_review' => new RoutingResult(
            'auto_review',
            'normal',
            new MergeRequestOpened($project->id, $project->gitlab_project_id, [], 1, 'feature', 'main', $user->gitlab_id, 'abc123'),
        ),
        'on_demand_review' => new RoutingResult(
            'on_demand_review',
            'high',
            new NoteOnMR($project->id, $project->gitlab_project_id, [], 1, '@ai review', $user->gitlab_id),
        ),
        'issue_discussion' => new RoutingResult(
            'issue_discussion',
            'normal',
            new NoteOnIssue($project->id, $project->gitlab_project_id, [], 10, '@ai explain', $user->gitlab_id),
        ),
        'feature_dev' => new RoutingResult(
            'feature_dev',
            'low',
            new IssueLabelChanged($project->id, $project->gitlab_project_id, [], 10, 'update', $user->gitlab_id, ['ai::develop']),
        ),
    ];

    foreach ($cases as $routingResult) {
        config()->set('vunnix.orchestration.kernel_enabled', true);
        $kernelTask = $service->dispatch($routingResult);

        config()->set('vunnix.orchestration.kernel_enabled', false);
        $legacyTask = $service->dispatch($routingResult);

        expect($kernelTask)->not->toBeNull()
            ->and($legacyTask)->not->toBeNull()
            ->and($kernelTask->type)->toBe($legacyTask->type);
    }
});

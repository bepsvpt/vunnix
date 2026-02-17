<?php

use App\Enums\TaskOrigin;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Events\Webhook\IssueLabelChanged;
use App\Events\Webhook\MergeRequestMerged;
use App\Events\Webhook\MergeRequestOpened;
use App\Events\Webhook\NoteOnIssue;
use App\Events\Webhook\NoteOnMR;
use App\Events\Webhook\PushToMRBranch;
use App\Jobs\ProcessTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\RoutingResult;
use App\Services\TaskDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function makeRoutingResult(string $intent, string $priority, $event): RoutingResult
{
    return new RoutingResult($intent, $priority, $event);
}

// ─── Intent → TaskType mapping ─────────────────────────────────────

it('maps auto_review intent to CodeReview type', function (): void {
    Queue::fake();
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $event = new MergeRequestOpened($project->id, $project->gitlab_project_id, [], 1, 'feature', 'main', $user->gitlab_id, 'abc123');
    $result = makeRoutingResult('auto_review', 'normal', $event);

    $service = app(TaskDispatchService::class);
    $task = $service->dispatch($result);

    expect($task->type)->toBe(TaskType::CodeReview);
});

it('maps on_demand_review intent to CodeReview type', function (): void {
    Queue::fake();
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $event = new NoteOnMR($project->id, $project->gitlab_project_id, [], 1, '@ai review', $user->gitlab_id);
    $result = makeRoutingResult('on_demand_review', 'high', $event);

    $service = app(TaskDispatchService::class);
    $task = $service->dispatch($result);

    expect($task->type)->toBe(TaskType::CodeReview)
        ->and($task->priority)->toBe(TaskPriority::High);
});

it('maps issue_discussion intent to IssueDiscussion type', function (): void {
    Queue::fake();
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $event = new NoteOnIssue($project->id, $project->gitlab_project_id, [], 10, '@ai explain', $user->gitlab_id);
    $result = makeRoutingResult('issue_discussion', 'normal', $event);

    $service = app(TaskDispatchService::class);
    $task = $service->dispatch($result);

    expect($task->type)->toBe(TaskType::IssueDiscussion);
});

it('maps feature_dev intent to FeatureDev type', function (): void {
    Queue::fake();
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $event = new IssueLabelChanged($project->id, $project->gitlab_project_id, [], 10, 'update', $user->gitlab_id, ['ai::develop']);
    $result = makeRoutingResult('feature_dev', 'low', $event);

    $service = app(TaskDispatchService::class);
    $task = $service->dispatch($result);

    expect($task->type)->toBe(TaskType::FeatureDev)
        ->and($task->priority)->toBe(TaskPriority::Low);
});

// ─── Task lifecycle ────────────────────────────────────────────────

it('creates task in queued status (received → queued transition)', function (): void {
    Queue::fake();
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $event = new MergeRequestOpened($project->id, $project->gitlab_project_id, [], 1, 'feature', 'main', $user->gitlab_id, 'abc123');
    $result = makeRoutingResult('auto_review', 'normal', $event);

    $service = app(TaskDispatchService::class);
    $task = $service->dispatch($result);

    expect($task->status)->toBe(TaskStatus::Queued);
});

it('sets origin to webhook', function (): void {
    Queue::fake();
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $event = new MergeRequestOpened($project->id, $project->gitlab_project_id, [], 1, 'feature', 'main', $user->gitlab_id, 'abc123');
    $result = makeRoutingResult('auto_review', 'normal', $event);

    $service = app(TaskDispatchService::class);
    $task = $service->dispatch($result);

    expect($task->origin)->toBe(TaskOrigin::Webhook);
});

it('persists correct GitLab context for MR events', function (): void {
    Queue::fake();
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $event = new MergeRequestOpened($project->id, $project->gitlab_project_id, [], 42, 'feature', 'main', $user->gitlab_id, 'deadbeef');
    $result = makeRoutingResult('auto_review', 'normal', $event);

    $service = app(TaskDispatchService::class);
    $task = $service->dispatch($result);

    expect($task->project_id)->toBe($project->id)
        ->and($task->mr_iid)->toBe(42)
        ->and($task->commit_sha)->toBe('deadbeef');
});

it('persists correct GitLab context for Issue events', function (): void {
    Queue::fake();
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $event = new NoteOnIssue($project->id, $project->gitlab_project_id, [], 99, '@ai help', $user->gitlab_id);
    $result = makeRoutingResult('issue_discussion', 'normal', $event);

    $service = app(TaskDispatchService::class);
    $task = $service->dispatch($result);

    expect($task->issue_iid)->toBe(99)
        ->and($task->mr_iid)->toBeNull();
});

// ─── Job dispatch ──────────────────────────────────────────────────

it('dispatches ProcessTask job to vunnix-runner-normal for code review', function (): void {
    Queue::fake();
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $event = new MergeRequestOpened($project->id, $project->gitlab_project_id, [], 1, 'feature', 'main', $user->gitlab_id, 'abc123');
    $result = makeRoutingResult('auto_review', 'normal', $event);

    $service = app(TaskDispatchService::class);
    $service->dispatch($result);

    Queue::assertPushedOn('vunnix-runner-normal', ProcessTask::class);
});

it('dispatches ProcessTask job to vunnix-runner-high for on-demand review', function (): void {
    Queue::fake();
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $event = new NoteOnMR($project->id, $project->gitlab_project_id, [], 1, '@ai review', $user->gitlab_id);
    $result = makeRoutingResult('on_demand_review', 'high', $event);

    $service = app(TaskDispatchService::class);
    $service->dispatch($result);

    Queue::assertPushedOn('vunnix-runner-high', ProcessTask::class);
});

it('dispatches ProcessTask job to vunnix-runner-low for feature dev', function (): void {
    Queue::fake();
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $event = new IssueLabelChanged($project->id, $project->gitlab_project_id, [], 10, 'update', $user->gitlab_id, ['ai::develop']);
    $result = makeRoutingResult('feature_dev', 'low', $event);

    $service = app(TaskDispatchService::class);
    $service->dispatch($result);

    Queue::assertPushedOn('vunnix-runner-low', ProcessTask::class);
});

// ─── Transition logging ────────────────────────────────────────────

it('logs received → queued transition', function (): void {
    Queue::fake();
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $event = new MergeRequestOpened($project->id, $project->gitlab_project_id, [], 1, 'feature', 'main', $user->gitlab_id, 'abc123');
    $result = makeRoutingResult('auto_review', 'normal', $event);

    $service = app(TaskDispatchService::class);
    $task = $service->dispatch($result);

    $this->assertDatabaseHas('task_transition_logs', [
        'task_id' => $task->id,
        'from_status' => 'received',
        'to_status' => 'queued',
    ]);
});

// ─── Skips non-dispatchable intents ────────────────────────────────

it('returns null for help_response intent (already handled by PostHelpResponse)', function (): void {
    Queue::fake();
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $event = new NoteOnMR($project->id, $project->gitlab_project_id, [], 1, '@ai foo', $user->gitlab_id);
    $result = makeRoutingResult('help_response', 'normal', $event);

    $service = app(TaskDispatchService::class);
    $task = $service->dispatch($result);

    expect($task)->toBeNull();
    Queue::assertNothingPushed();
});

it('returns null for acceptance_tracking intent (not a task)', function (): void {
    Queue::fake();
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $event = new MergeRequestMerged($project->id, $project->gitlab_project_id, [], 1, 'feature', 'main', $user->gitlab_id, 'abc123');
    $result = makeRoutingResult('acceptance_tracking', 'normal', $event);

    $service = app(TaskDispatchService::class);
    $task = $service->dispatch($result);

    expect($task)->toBeNull();
    Queue::assertNothingPushed();
});

// ─── Push → MR resolution (T40) ──────────────────────────────────

it('resolves mr_iid from push event via GitLab API', function (): void {
    Queue::fake();
    Http::fake([
        '*/api/v4/projects/*/merge_requests*' => Http::response([
            ['iid' => 42, 'source_branch' => 'feature/login', 'state' => 'opened'],
        ], 200),
    ]);

    $project = Project::factory()->create();
    $user = User::factory()->create();

    $event = new PushToMRBranch(
        $project->id, $project->gitlab_project_id, [],
        'refs/heads/feature/login', '000000', 'abc123',
        $user->gitlab_id, [['id' => 'abc123', 'message' => 'fix typo']], 1,
    );
    $result = makeRoutingResult('incremental_review', 'normal', $event);

    $service = app(TaskDispatchService::class);
    $task = $service->dispatch($result);

    expect($task)->not->toBeNull()
        ->and($task->mr_iid)->toBe(42)
        ->and($task->commit_sha)->toBe('abc123')
        ->and($task->type)->toBe(TaskType::CodeReview);
});

it('skips dispatch for incremental_review when no open MR exists for branch', function (): void {
    Queue::fake();
    Http::fake([
        '*/api/v4/projects/*/merge_requests*' => Http::response([], 200),
    ]);

    $project = Project::factory()->create();
    $user = User::factory()->create();

    $event = new PushToMRBranch(
        $project->id, $project->gitlab_project_id, [],
        'refs/heads/feature/orphan', '000000', 'abc123',
        $user->gitlab_id, [['id' => 'abc123', 'message' => 'wip']], 1,
    );
    $result = makeRoutingResult('incremental_review', 'normal', $event);

    $service = app(TaskDispatchService::class);
    $task = $service->dispatch($result);

    expect($task)->toBeNull();
    Queue::assertNothingPushed();
});

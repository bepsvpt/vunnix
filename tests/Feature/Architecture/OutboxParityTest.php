<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\PostSummaryComment;
use App\Jobs\ProcessTaskResult;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ResultProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('keeps direct publisher behavior in shadow mode while mirroring to outbox', function (): void {
    Queue::fake();

    config()->set('vunnix.events.outbox_enabled', true);
    config()->set('vunnix.events.outbox_shadow_mode', true);

    $project = Project::factory()->create();
    $user = User::factory()->create();
    $task = Task::create([
        'type' => TaskType::CodeReview,
        'project_id' => $project->id,
        'user_id' => $user->id,
        'status' => TaskStatus::Running,
        'priority' => \App\Enums\TaskPriority::Normal,
        'origin' => \App\Enums\TaskOrigin::Webhook,
        'mr_iid' => 42,
        'result' => [
            'intent' => 'auto_review',
            'findings' => [],
        ],
    ]);

    $processor = Mockery::mock(ResultProcessor::class);
    $processor->shouldReceive('process')->once()->andReturn([
        'success' => true,
        'errors' => [],
    ]);

    (new ProcessTaskResult($task->id))->handle($processor);

    Queue::assertPushed(PostSummaryComment::class);
    $this->assertDatabaseCount('internal_outbox_events', 1);
});

it('uses outbox-only mode when shadow mode is disabled', function (): void {
    Queue::fake();

    config()->set('vunnix.events.outbox_enabled', true);
    config()->set('vunnix.events.outbox_shadow_mode', false);

    $project = Project::factory()->create();
    $user = User::factory()->create();
    $task = Task::create([
        'type' => TaskType::CodeReview,
        'project_id' => $project->id,
        'user_id' => $user->id,
        'status' => TaskStatus::Running,
        'priority' => \App\Enums\TaskPriority::Normal,
        'origin' => \App\Enums\TaskOrigin::Webhook,
        'mr_iid' => 42,
        'result' => [
            'intent' => 'auto_review',
            'findings' => [],
        ],
    ]);

    $processor = Mockery::mock(ResultProcessor::class);
    $processor->shouldReceive('process')->once()->andReturn([
        'success' => true,
        'errors' => [],
    ]);

    (new ProcessTaskResult($task->id))->handle($processor);

    Queue::assertNotPushed(PostSummaryComment::class);
    $this->assertDatabaseCount('internal_outbox_events', 1);
});

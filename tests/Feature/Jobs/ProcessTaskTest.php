<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\ProcessTask;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Models\Task;
use App\Services\TaskDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('delegates to TaskDispatcher for queued runner tasks', function () {
    $project = Project::factory()->create(['gitlab_project_id' => 100]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/100/merge_requests/1/changes' => Http::response([
            'changes' => [
                ['new_path' => 'app/Models/User.php'],
            ],
        ]),
        '*/api/v4/projects/100/trigger/pipeline' => Http::response([
            'id' => 1234,
            'status' => 'pending',
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::CodeReview,
        'project_id' => $project->id,
        'mr_iid' => 1,
    ]);

    $job = new ProcessTask($task->id);
    app()->call([$job, 'handle']);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Running)
        ->and($task->result['strategy'])->toBe('backend-review');
});

it('delegates to TaskDispatcher for queued server-side tasks', function () {
    Http::fake();

    $task = Task::factory()->queued()->create([
        'type' => TaskType::PrdCreation,
        'mr_iid' => null,
        'issue_iid' => 10,
    ]);

    $job = new ProcessTask($task->id);
    app()->call([$job, 'handle']);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Running);
});

it('skips tasks already in terminal state', function () {
    $task = Task::factory()->completed()->create();

    $mock = Mockery::mock(TaskDispatcher::class);
    $mock->shouldNotReceive('dispatch');
    app()->instance(TaskDispatcher::class, $mock);

    $job = new ProcessTask($task->id);
    app()->call([$job, 'handle']);
});

it('skips tasks that no longer exist', function () {
    $mock = Mockery::mock(TaskDispatcher::class);
    $mock->shouldNotReceive('dispatch');
    app()->instance(TaskDispatcher::class, $mock);

    $job = new ProcessTask(999999);
    app()->call([$job, 'handle']);
});

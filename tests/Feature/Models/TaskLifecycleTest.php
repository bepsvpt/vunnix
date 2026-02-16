<?php

use App\Enums\TaskOrigin;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use App\Models\User;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Helper ─────────────────────────────────────────────────────────

function createTask(array $overrides = []): Task
{
    return Task::factory()->create($overrides);
}

// ─── Valid state transitions ────────────────────────────────────────

it('transitions from received to queued', function () {
    $task = createTask(['status' => TaskStatus::Received]);

    $task->transitionTo(TaskStatus::Queued);

    expect($task->fresh()->status)->toBe(TaskStatus::Queued);
});

it('transitions from queued to running', function () {
    $task = createTask(['status' => TaskStatus::Queued]);

    $task->transitionTo(TaskStatus::Running);

    expect($task->fresh()->status)->toBe(TaskStatus::Running);
});

it('transitions from running to completed', function () {
    $task = createTask(['status' => TaskStatus::Running]);

    $task->transitionTo(TaskStatus::Completed);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::Completed)
        ->and($fresh->completed_at)->not->toBeNull();
});

it('transitions from running to failed', function () {
    $task = createTask(['status' => TaskStatus::Running]);

    $task->transitionTo(TaskStatus::Failed);

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
});

it('transitions from running to superseded', function () {
    $task = createTask(['status' => TaskStatus::Running]);

    $task->transitionTo(TaskStatus::Superseded);

    expect($task->fresh()->status)->toBe(TaskStatus::Superseded);
});

it('transitions from queued to superseded', function () {
    $task = createTask(['status' => TaskStatus::Queued]);

    $task->transitionTo(TaskStatus::Superseded);

    expect($task->fresh()->status)->toBe(TaskStatus::Superseded);
});

it('transitions from failed to queued on retry', function () {
    $task = createTask(['status' => TaskStatus::Failed, 'retry_count' => 1]);

    $task->transitionTo(TaskStatus::Queued);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::Queued);
});

it('transitions from queued to failed for scheduling timeout', function () {
    $task = createTask(['status' => TaskStatus::Queued]);

    $task->transitionTo(TaskStatus::Failed, 'scheduling_timeout');

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::Failed)
        ->and($fresh->error_reason)->toBe('scheduling_timeout');
});

it('sets started_at when transitioning to running', function () {
    $task = createTask(['status' => TaskStatus::Queued]);

    expect($task->started_at)->toBeNull();

    $task->transitionTo(TaskStatus::Running);

    expect($task->fresh()->started_at)->not->toBeNull();
});

it('sets completed_at when transitioning to completed', function () {
    $task = createTask(['status' => TaskStatus::Running]);

    expect($task->completed_at)->toBeNull();

    $task->transitionTo(TaskStatus::Completed);

    expect($task->fresh()->completed_at)->not->toBeNull();
});

// ─── Invalid state transitions ──────────────────────────────────────

it('throws exception for completed to running', function () {
    $task = createTask(['status' => TaskStatus::Completed]);

    $task->transitionTo(TaskStatus::Running);
})->throws(\App\Exceptions\InvalidTaskTransitionException::class);

it('throws exception for completed to queued', function () {
    $task = createTask(['status' => TaskStatus::Completed]);

    $task->transitionTo(TaskStatus::Queued);
})->throws(\App\Exceptions\InvalidTaskTransitionException::class);

it('throws exception for superseded to running', function () {
    $task = createTask(['status' => TaskStatus::Superseded]);

    $task->transitionTo(TaskStatus::Running);
})->throws(\App\Exceptions\InvalidTaskTransitionException::class);

it('throws exception for superseded to queued', function () {
    $task = createTask(['status' => TaskStatus::Superseded]);

    $task->transitionTo(TaskStatus::Queued);
})->throws(\App\Exceptions\InvalidTaskTransitionException::class);

it('throws exception for failed to completed', function () {
    $task = createTask(['status' => TaskStatus::Failed]);

    $task->transitionTo(TaskStatus::Completed);
})->throws(\App\Exceptions\InvalidTaskTransitionException::class);

it('throws exception for received to running (must go through queued)', function () {
    $task = createTask(['status' => TaskStatus::Received]);

    $task->transitionTo(TaskStatus::Running);
})->throws(\App\Exceptions\InvalidTaskTransitionException::class);

it('throws exception for received to completed', function () {
    $task = createTask(['status' => TaskStatus::Received]);

    $task->transitionTo(TaskStatus::Completed);
})->throws(\App\Exceptions\InvalidTaskTransitionException::class);

// ─── Observer fires on transitions ──────────────────────────────────

it('fires TaskObserver on state transition', function () {
    $task = createTask(['status' => TaskStatus::Received]);
    $task->transitionTo(TaskStatus::Queued);

    // The observer's updated() method should have been called,
    // verified by the row it inserts into task_transition_logs
    $this->assertDatabaseHas('task_transition_logs', [
        'task_id' => $task->id,
        'from_status' => TaskStatus::Received->value,
        'to_status' => TaskStatus::Queued->value,
    ]);
});

it('logs all transitions through full lifecycle', function () {
    $task = createTask(['status' => TaskStatus::Received]);

    $task->transitionTo(TaskStatus::Queued);
    $task->transitionTo(TaskStatus::Running);
    $task->transitionTo(TaskStatus::Completed);

    $logs = \Illuminate\Support\Facades\DB::table('task_transition_logs')
        ->where('task_id', $task->id)
        ->orderBy('id')
        ->get();

    expect($logs)->toHaveCount(3)
        ->and($logs[0]->from_status)->toBe('received')
        ->and($logs[0]->to_status)->toBe('queued')
        ->and($logs[1]->from_status)->toBe('queued')
        ->and($logs[1]->to_status)->toBe('running')
        ->and($logs[2]->from_status)->toBe('running')
        ->and($logs[2]->to_status)->toBe('completed');
});

it('includes task_id and timestamp in transition logs', function () {
    $task = createTask(['status' => TaskStatus::Received]);

    $task->transitionTo(TaskStatus::Queued);

    $log = \Illuminate\Support\Facades\DB::table('task_transition_logs')
        ->where('task_id', $task->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->task_id)->toBe($task->id)
        ->and($log->transitioned_at)->not->toBeNull();
});

// ─── Superseding behavior ───────────────────────────────────────────

it('supersedes queued tasks for same MR on new push', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    // Task A is queued for MR !5
    $taskA = createTask([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'mr_iid' => 5,
        'status' => TaskStatus::Queued,
        'commit_sha' => 'aaa1111',
    ]);

    // Task B arrives for same MR !5 with new commit
    $taskB = createTask([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'mr_iid' => 5,
        'status' => TaskStatus::Received,
        'commit_sha' => 'bbb2222',
    ]);

    // Supersede queued tasks for same MR
    Task::supersedeForMergeRequest($project->id, 5, $taskB->id);

    expect($taskA->fresh()->status)->toBe(TaskStatus::Superseded)
        ->and($taskA->fresh()->superseded_by_id)->toBe($taskB->id);
});

it('supersedes running tasks for same MR on new push', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $taskA = createTask([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'mr_iid' => 5,
        'status' => TaskStatus::Running,
        'commit_sha' => 'aaa1111',
    ]);

    $taskB = createTask([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'mr_iid' => 5,
        'status' => TaskStatus::Received,
        'commit_sha' => 'bbb2222',
    ]);

    Task::supersedeForMergeRequest($project->id, 5, $taskB->id);

    expect($taskA->fresh()->status)->toBe(TaskStatus::Superseded)
        ->and($taskA->fresh()->superseded_by_id)->toBe($taskB->id);
});

it('does not supersede already completed tasks', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $completedTask = createTask([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'mr_iid' => 5,
        'status' => TaskStatus::Completed,
    ]);

    $newTask = createTask([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'mr_iid' => 5,
        'status' => TaskStatus::Received,
    ]);

    Task::supersedeForMergeRequest($project->id, 5, $newTask->id);

    expect($completedTask->fresh()->status)->toBe(TaskStatus::Completed);
});

// ─── All fields persisted correctly ─────────────────────────────────

it('persists all required fields', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $task = Task::factory()->create([
        'type' => TaskType::CodeReview,
        'origin' => TaskOrigin::Webhook,
        'user_id' => $user->id,
        'project_id' => $project->id,
        'priority' => TaskPriority::High,
        'status' => TaskStatus::Received,
        'mr_iid' => 42,
        'issue_iid' => null,
        'comment_id' => 123,
        'commit_sha' => 'abc1234567890def1234567890abc123456789d',
        'conversation_id' => null,
        'prompt' => 'Review this code',
        'response' => null,
        'tokens_used' => null,
        'model' => null,
        'result' => ['findings' => []],
        'prompt_version' => ['skill' => 'code-review', 'version' => '1.0'],
        'cost' => 0.001234,
        'retry_count' => 0,
        'error_reason' => null,
    ]);

    $fresh = $task->fresh();

    expect($fresh->type)->toBe(TaskType::CodeReview)
        ->and($fresh->origin)->toBe(TaskOrigin::Webhook)
        ->and($fresh->user_id)->toBe($user->id)
        ->and($fresh->project_id)->toBe($project->id)
        ->and($fresh->priority)->toBe(TaskPriority::High)
        ->and($fresh->status)->toBe(TaskStatus::Received)
        ->and($fresh->mr_iid)->toBe(42)
        ->and($fresh->comment_id)->toBe(123)
        ->and($fresh->commit_sha)->toBe('abc1234567890def1234567890abc123456789d')
        ->and($fresh->result)->toBe(['findings' => []])
        ->and($fresh->prompt_version)->toBe(['skill' => 'code-review', 'version' => '1.0'])
        ->and((float) $fresh->cost)->toEqual(0.001234)
        ->and($fresh->retry_count)->toBe(0)
        ->and($fresh->error_reason)->toBeNull();
});

it('casts type to TaskType enum', function () {
    $task = createTask(['type' => TaskType::CodeReview]);
    expect($task->fresh()->type)->toBeInstanceOf(TaskType::class);
});

it('casts origin to TaskOrigin enum', function () {
    $task = createTask(['origin' => TaskOrigin::Webhook]);
    expect($task->fresh()->origin)->toBeInstanceOf(TaskOrigin::class);
});

it('casts priority to TaskPriority enum', function () {
    $task = createTask(['priority' => TaskPriority::High]);
    expect($task->fresh()->priority)->toBeInstanceOf(TaskPriority::class);
});

it('casts status to TaskStatus enum', function () {
    $task = createTask(['status' => TaskStatus::Received]);
    expect($task->fresh()->status)->toBeInstanceOf(TaskStatus::class);
});

it('casts result to array', function () {
    $task = createTask(['result' => ['key' => 'value']]);
    expect($task->fresh()->result)->toBe(['key' => 'value']);
});

it('casts prompt_version to array', function () {
    $task = createTask(['prompt_version' => ['version' => '1.0']]);
    expect($task->fresh()->prompt_version)->toBe(['version' => '1.0']);
});

// ─── Relationships ──────────────────────────────────────────────────

it('belongs to a user', function () {
    $user = User::factory()->create();
    $task = createTask(['user_id' => $user->id]);

    expect($task->user)->toBeInstanceOf(User::class)
        ->and($task->user->id)->toBe($user->id);
});

it('belongs to a project', function () {
    $project = Project::factory()->create();
    $task = createTask(['project_id' => $project->id]);

    expect($task->project)->toBeInstanceOf(Project::class)
        ->and($task->project->id)->toBe($project->id);
});

// ─── Terminal state checks ──────────────────────────────────────────

it('reports completed as terminal', function () {
    $task = createTask(['status' => TaskStatus::Completed]);
    expect($task->isTerminal())->toBeTrue();
});

it('reports failed as terminal', function () {
    $task = createTask(['status' => TaskStatus::Failed]);
    expect($task->isTerminal())->toBeTrue();
});

it('reports superseded as terminal', function () {
    $task = createTask(['status' => TaskStatus::Superseded]);
    expect($task->isTerminal())->toBeTrue();
});

it('reports received as not terminal', function () {
    $task = createTask(['status' => TaskStatus::Received]);
    expect($task->isTerminal())->toBeFalse();
});

it('reports queued as not terminal', function () {
    $task = createTask(['status' => TaskStatus::Queued]);
    expect($task->isTerminal())->toBeFalse();
});

it('reports running as not terminal', function () {
    $task = createTask(['status' => TaskStatus::Running]);
    expect($task->isTerminal())->toBeFalse();
});

// ─── Scopes ─────────────────────────────────────────────────────────

it('scopes active tasks (queued or running)', function () {
    createTask(['status' => TaskStatus::Queued]);
    createTask(['status' => TaskStatus::Running]);
    createTask(['status' => TaskStatus::Completed]);
    createTask(['status' => TaskStatus::Failed]);
    createTask(['status' => TaskStatus::Superseded]);

    expect(Task::active()->count())->toBe(2);
});

it('scopes tasks by project and MR', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    createTask(['project_id' => $project->id, 'user_id' => $user->id, 'mr_iid' => 10]);
    createTask(['project_id' => $project->id, 'user_id' => $user->id, 'mr_iid' => 20]);

    expect(Task::forMergeRequest($project->id, 10)->count())->toBe(1);
});

// ─── Retry increment ────────────────────────────────────────────────

it('increments retry_count when transitioning from failed to queued', function () {
    $task = createTask(['status' => TaskStatus::Failed, 'retry_count' => 1]);

    $task->transitionTo(TaskStatus::Queued);

    expect($task->fresh()->retry_count)->toBe(2);
});

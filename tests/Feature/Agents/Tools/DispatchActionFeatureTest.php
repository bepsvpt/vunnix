<?php

use App\Agents\Tools\DispatchAction;
use App\Enums\TaskOrigin;
use App\Enums\TaskType;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectAccessChecker;
use App\Services\TaskDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Mock TaskDispatcher as a no-op — these tests verify task creation,
    // not the dispatch pipeline (which has its own test suite).
    $this->mockDispatcher = Mockery::mock(TaskDispatcher::class);
    $this->mockDispatcher->shouldReceive('dispatch')->andReturnNull();
    app()->instance(TaskDispatcher::class, $this->mockDispatcher);
});

// ─── Helpers ────────────────────────────────────────────────────

function dispatchTestUser(Project $project, bool $withDispatchPermission = true): User
{
    $user = User::factory()->create();
    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    $role = Role::create([
        'name' => 'developer',
        'project_id' => $project->id,
        'description' => 'Test developer role',
        'is_default' => true,
    ]);

    $chatAccess = Permission::firstOrCreate(
        ['name' => 'chat.access'],
        ['description' => 'Can use chat', 'group' => 'chat'],
    );
    $role->permissions()->attach($chatAccess->id);

    if ($withDispatchPermission) {
        $dispatchPerm = Permission::firstOrCreate(
            ['name' => 'chat.dispatch_task'],
            ['description' => 'Can trigger AI actions from chat', 'group' => 'chat'],
        );
        $role->permissions()->attach($dispatchPerm->id);
    }

    $user->assignRole($role, $project);

    return $user;
}

// ─── Task Creation with conversation origin ─────────────────────

it('creates a task with conversation origin when dispatching implement_feature', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = dispatchTestUser($project);
    Auth::login($user);
    Context::add('vunnix_conversation_id', 'conv-test-123');

    $tool = new DispatchAction(
        app(ProjectAccessChecker::class),
        $this->mockDispatcher,
    );

    $request = new Request([
        'action_type' => 'implement_feature',
        'project_id' => $project->gitlab_project_id,
        'title' => 'Add Stripe payments',
        'description' => 'Implement payment flow with Stripe SDK',
        'branch_name' => 'ai/payment-feature',
        'target_branch' => 'main',
    ]);

    $result = $tool->handle($request);

    expect($result)->toContain('Task dispatched');
    expect($result)->toContain('Feature implementation');
    expect($result)->toContain('Add Stripe payments');

    $task = Task::latest()->first();
    expect($task->type)->toBe(TaskType::FeatureDev);
    expect($task->origin)->toBe(TaskOrigin::Conversation);
    expect($task->conversation_id)->toBe('conv-test-123');
    expect($task->user_id)->toBe($user->id);
    expect($task->project_id)->toBe($project->id);
    expect($task->result['action_type'])->toBe('implement_feature');
    expect($task->result['title'])->toBe('Add Stripe payments');
    expect($task->result['dispatched_from'])->toBe('conversation');
});

it('creates a task for deep_analysis action type', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = dispatchTestUser($project);
    Auth::login($user);
    Context::add('vunnix_conversation_id', 'conv-test-456');

    $tool = new DispatchAction(
        app(ProjectAccessChecker::class),
        $this->mockDispatcher,
    );

    $request = new Request([
        'action_type' => 'deep_analysis',
        'project_id' => $project->gitlab_project_id,
        'title' => 'Analyze payment module dependencies',
        'description' => 'Which reports are affected if we modify the order status enum?',
    ]);

    $result = $tool->handle($request);

    expect($result)->toContain('Deep analysis');
    expect($result)->toContain('fed back into this conversation');

    $task = Task::latest()->first();
    expect($task->type)->toBe(TaskType::DeepAnalysis);
    expect($task->origin)->toBe(TaskOrigin::Conversation);
    expect($task->conversation_id)->toBe('conv-test-456');
});

// ─── Permission denial ──────────────────────────────────────────

it('returns permission error when user lacks chat.dispatch_task', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = dispatchTestUser($project, withDispatchPermission: false);
    Auth::login($user);

    $tool = new DispatchAction(
        app(ProjectAccessChecker::class),
        $this->mockDispatcher,
    );

    $request = new Request([
        'action_type' => 'create_issue',
        'project_id' => $project->gitlab_project_id,
        'title' => 'Test issue',
        'description' => 'Test description',
    ]);

    $result = $tool->handle($request);

    expect($result)->toContain('do not have permission');
    expect($result)->toContain('chat.dispatch_task');
    expect(Task::count())->toBe(0);
});

// ─── Invalid action type ────────────────────────────────────────

it('returns error for invalid action type', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = dispatchTestUser($project);
    Auth::login($user);

    $tool = new DispatchAction(
        app(ProjectAccessChecker::class),
        $this->mockDispatcher,
    );

    $request = new Request([
        'action_type' => 'delete_repo',
        'project_id' => $project->gitlab_project_id,
        'title' => 'Test',
        'description' => 'Test',
    ]);

    $result = $tool->handle($request);

    expect($result)->toContain('Invalid action type');
    expect(Task::count())->toBe(0);
});

// ─── Action type mapping ────────────────────────────────────────

// ─── Designer iteration flow (T72) ──────────────────────────

it('stores existing_mr_iid on task when dispatching ui_adjustment correction', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = dispatchTestUser($project);
    Auth::login($user);

    $tool = new DispatchAction(
        app(ProjectAccessChecker::class),
        $this->mockDispatcher,
    );

    $request = new Request([
        'action_type' => 'ui_adjustment',
        'project_id' => $project->gitlab_project_id,
        'title' => 'Fix card padding on mobile',
        'description' => 'Reduce padding from 24px to 16px on viewports < 768px',
        'branch_name' => 'ai/fix-card-padding',
        'target_branch' => 'main',
        'existing_mr_iid' => 456,
    ]);

    $result = $tool->handle($request);

    expect($result)->toContain('Task dispatched');

    $task = Task::latest()->first();
    expect($task->type)->toBe(TaskType::UiAdjustment);
    expect($task->mr_iid)->toBe(456);
    expect($task->result['existing_mr_iid'])->toBe(456);
    expect($task->result['branch_name'])->toBe('ai/fix-card-padding');
});

it('does not set mr_iid when existing_mr_iid is absent', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = dispatchTestUser($project);
    Auth::login($user);

    $tool = new DispatchAction(
        app(ProjectAccessChecker::class),
        $this->mockDispatcher,
    );

    $request = new Request([
        'action_type' => 'ui_adjustment',
        'project_id' => $project->gitlab_project_id,
        'title' => 'New UI change',
        'description' => 'Initial adjustment',
        'branch_name' => 'ai/new-change',
        'target_branch' => 'main',
    ]);

    $result = $tool->handle($request);

    $task = Task::latest()->first();
    expect($task->mr_iid)->toBeNull();
    expect($task->result)->not->toHaveKey('existing_mr_iid');
});

// ─── Action type mapping ────────────────────────────────────────

it('maps create_issue to PrdCreation task type', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = dispatchTestUser($project);
    Auth::login($user);

    $tool = new DispatchAction(
        app(ProjectAccessChecker::class),
        $this->mockDispatcher,
    );

    $request = new Request([
        'action_type' => 'create_issue',
        'project_id' => $project->gitlab_project_id,
        'title' => 'Add dark mode',
        'description' => 'Implement dark mode theme toggle',
        'assignee_id' => 7,
        'labels' => 'feature,ai::created',
    ]);

    $result = $tool->handle($request);

    $task = Task::latest()->first();
    expect($task->type)->toBe(TaskType::PrdCreation);
    expect($task->result['assignee_id'])->toBe(7);
    expect($task->result['labels'])->toBe(['feature', 'ai::created']);
});

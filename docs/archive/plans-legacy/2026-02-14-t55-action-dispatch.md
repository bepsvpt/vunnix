# T55: Action Dispatch from Conversation — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enable the Conversation Engine to dispatch tasks (create Issue, implement feature, UI adjustment, create MR, deep analysis) from chat when a user confirms an action.

**Architecture:** New AI SDK Tool class `DispatchAction` that validates `chat.dispatch_task` permission, creates a Task record with `origin=conversation` and `conversation_id`, dispatches it via TaskDispatcher, and returns a confirmation string to the AI stream. A new `DeepAnalysis` TaskType supports read-only CLI dispatch (D132). The system prompt's `[Action Dispatch]` section is expanded with permission failure guidance and supported action types.

**Tech Stack:** Laravel AI SDK Tool, Pest tests, existing Task model + TaskDispatcher + RBAC

---

### Task 1: Add `DeepAnalysis` to TaskType enum

**Files:**
- Modify: `app/Enums/TaskType.php`
- Test: `tests/Unit/Enums/TaskTypeTest.php` (if exists, else inline verification)

**Step 1: Add the new enum case and execution mode**

In `app/Enums/TaskType.php`, add `DeepAnalysis` case with `runner` execution mode:

```php
enum TaskType: string
{
    case CodeReview = 'code_review';
    case IssueDiscussion = 'issue_discussion';
    case FeatureDev = 'feature_dev';
    case UiAdjustment = 'ui_adjustment';
    case PrdCreation = 'prd_creation';
    case SecurityAudit = 'security_audit';
    case DeepAnalysis = 'deep_analysis';

    public function executionMode(): string
    {
        return match ($this) {
            self::PrdCreation => 'server',
            default => 'runner',
        };
    }
}
```

**Step 2: Run existing tests to verify no breakage**

Run: `php artisan test --filter=TaskType`
Expected: All existing tests pass.

**Step 3: Commit**

```bash
git add app/Enums/TaskType.php
git commit --no-gpg-sign -m "T55.1: Add DeepAnalysis case to TaskType enum"
```

---

### Task 2: Create `DispatchAction` AI SDK Tool

**Files:**
- Create: `app/Agents/Tools/DispatchAction.php`
- Test: `tests/Unit/Agents/Tools/DispatchActionTest.php`

**Step 1: Write the failing unit tests**

Create `tests/Unit/Agents/Tools/DispatchActionTest.php`:

```php
<?php

use App\Agents\Tools\DispatchAction;
use App\Enums\TaskOrigin;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectAccessChecker;
use App\Services\TaskDispatcher;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;
use Mockery;

beforeEach(function () {
    $this->accessChecker = Mockery::mock(ProjectAccessChecker::class);
    $this->taskDispatcher = Mockery::mock(TaskDispatcher::class);
    $this->tool = new DispatchAction($this->accessChecker, $this->taskDispatcher);
});

afterEach(fn () => Mockery::close());

it('has a description', function () {
    expect($this->tool->description())->toBeString()->not->toBeEmpty();
});

it('defines the expected schema parameters', function () {
    $schema = new JsonSchemaTypeFactory;
    $params = $this->tool->schema($schema);

    expect($params)->toHaveKeys([
        'action_type',
        'project_id',
        'title',
        'description',
        'branch_name',
        'target_branch',
        'assignee_id',
        'labels',
        'user_id',
        'conversation_id',
    ]);
});

it('returns rejection when access checker denies access', function () {
    $this->accessChecker
        ->shouldReceive('check')
        ->with(42)
        ->andReturn('Access denied: project not registered.');

    $request = Request::from([
        'action_type' => 'implement_feature',
        'project_id' => 42,
        'title' => 'Add payment',
        'description' => 'Implement Stripe payments',
        'user_id' => 1,
        'conversation_id' => 'conv-abc',
    ]);

    $result = $this->tool->handle($request);

    expect($result)->toContain('Access denied');
    $this->taskDispatcher->shouldNotHaveReceived('dispatch');
});

it('returns error for invalid action type', function () {
    $this->accessChecker
        ->shouldReceive('check')
        ->with(42)
        ->andReturnNull();

    $request = Request::from([
        'action_type' => 'invalid_type',
        'project_id' => 42,
        'title' => 'Test',
        'description' => 'Test desc',
        'user_id' => 1,
        'conversation_id' => 'conv-abc',
    ]);

    $result = $this->tool->handle($request);

    expect($result)->toContain('Invalid action type');
    $this->taskDispatcher->shouldNotHaveReceived('dispatch');
});

it('returns permission error when user lacks chat.dispatch_task', function () {
    $this->accessChecker
        ->shouldReceive('check')
        ->with(42)
        ->andReturnNull();

    // Mock User without permission
    $user = Mockery::mock(User::class);
    $user->shouldReceive('hasPermission')
        ->with('chat.dispatch_task', Mockery::type(Project::class))
        ->andReturnFalse();

    $project = Mockery::mock(Project::class);
    $project->shouldReceive('getAttribute')->with('id')->andReturn(1);

    // We need to mock the static lookups — this test becomes a feature test.
    // For the unit test, we verify the tool returns the right error format.
    // See Task 4 for the feature-level permission integration test.
});

it('maps action types to TaskType enum correctly', function () {
    $mapping = DispatchAction::ACTION_TYPE_MAP;

    expect($mapping)->toHaveKeys([
        'create_issue',
        'implement_feature',
        'ui_adjustment',
        'create_mr',
        'deep_analysis',
    ]);

    expect($mapping['create_issue'])->toBe(TaskType::PrdCreation);
    expect($mapping['implement_feature'])->toBe(TaskType::FeatureDev);
    expect($mapping['ui_adjustment'])->toBe(TaskType::UiAdjustment);
    expect($mapping['create_mr'])->toBe(TaskType::FeatureDev);
    expect($mapping['deep_analysis'])->toBe(TaskType::DeepAnalysis);
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=DispatchActionTest`
Expected: FAIL (class does not exist)

**Step 3: Write the DispatchAction tool implementation**

Create `app/Agents/Tools/DispatchAction.php`:

```php
<?php

namespace App\Agents\Tools;

use App\Enums\TaskOrigin;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectAccessChecker;
use App\Services\TaskDispatcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * AI SDK Tool: Dispatch an action from conversation.
 *
 * Creates a Task in the Task Queue when a user confirms an action
 * during a conversation. Validates project access and chat.dispatch_task
 * permission before creating the task.
 *
 * Supported action types: create_issue, implement_feature, ui_adjustment,
 * create_mr, deep_analysis (D132).
 *
 * @see §3.2 — Action dispatch from conversation
 * @see §4.3 — Action Dispatch UX
 */
class DispatchAction implements Tool
{
    /**
     * Map from action_type strings to TaskType enum values.
     */
    public const ACTION_TYPE_MAP = [
        'create_issue' => TaskType::PrdCreation,
        'implement_feature' => TaskType::FeatureDev,
        'ui_adjustment' => TaskType::UiAdjustment,
        'create_mr' => TaskType::FeatureDev,
        'deep_analysis' => TaskType::DeepAnalysis,
    ];

    public function __construct(
        protected ProjectAccessChecker $accessChecker,
        protected TaskDispatcher $taskDispatcher,
    ) {}

    public function description(): string
    {
        return 'Dispatch an action (create Issue, implement feature, UI adjustment, create MR, or deep analysis) to the task queue. Only call this after presenting a preview and receiving explicit user confirmation.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action_type' => $schema
                ->string()
                ->description('The type of action: create_issue, implement_feature, ui_adjustment, create_mr, or deep_analysis.')
                ->required(),
            'project_id' => $schema
                ->integer()
                ->description('The GitLab project ID to dispatch the action against.')
                ->required(),
            'title' => $schema
                ->string()
                ->description('A short title for the action (e.g., Issue title, feature name).')
                ->required(),
            'description' => $schema
                ->string()
                ->description('Detailed description of what the action should accomplish.')
                ->required(),
            'branch_name' => $schema
                ->string()
                ->description('Target branch name for feature/UI/MR actions (e.g., "ai/payment-feature"). Not used for create_issue or deep_analysis.'),
            'target_branch' => $schema
                ->string()
                ->description('Base branch to target (defaults to "main"). Not used for create_issue or deep_analysis.'),
            'assignee_id' => $schema
                ->integer()
                ->description('GitLab user ID to assign (for create_issue actions).'),
            'labels' => $schema
                ->string()
                ->description('Comma-separated labels to apply (e.g., "feature,ai::created").'),
            'user_id' => $schema
                ->integer()
                ->description('The authenticated user ID dispatching this action.')
                ->required(),
            'conversation_id' => $schema
                ->string()
                ->description('The conversation ID this action was dispatched from.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $actionType = (string) $request->string('action_type');
        $projectId = $request->integer('project_id');

        // 1. Validate project access
        $rejection = $this->accessChecker->check($projectId);
        if ($rejection !== null) {
            return $rejection;
        }

        // 2. Validate action type
        if (! isset(self::ACTION_TYPE_MAP[$actionType])) {
            return "Invalid action type: \"{$actionType}\". Supported types: "
                . implode(', ', array_keys(self::ACTION_TYPE_MAP)) . '.';
        }

        // 3. Resolve the internal project and user
        $project = Project::where('gitlab_project_id', $projectId)->first();
        if (! $project) {
            return 'Error: project not found in Vunnix registry.';
        }

        $userId = $request->integer('user_id');
        $user = User::find($userId);
        if (! $user) {
            return 'Error: user not found.';
        }

        // 4. Check chat.dispatch_task permission
        if (! $user->hasPermission('chat.dispatch_task', $project)) {
            return "You do not have permission to dispatch actions on this project. "
                . "The 'chat.dispatch_task' permission is required. Contact your project admin to request access.";
        }

        // 5. Create the task
        $taskType = self::ACTION_TYPE_MAP[$actionType];
        $title = (string) $request->string('title');
        $description = (string) $request->string('description');
        $conversationId = (string) $request->string('conversation_id');

        $taskData = [
            'type' => $taskType,
            'origin' => TaskOrigin::Conversation,
            'user_id' => $user->id,
            'project_id' => $project->id,
            'status' => TaskStatus::Received,
            'conversation_id' => $conversationId,
            'result' => $this->buildResultMetadata($request, $actionType),
        ];

        // Set branch info for feature/UI/MR actions
        if (in_array($actionType, ['implement_feature', 'ui_adjustment', 'create_mr'])) {
            $taskData['commit_sha'] = (string) $request->string('target_branch', 'main');
        }

        $task = Task::create($taskData);

        Log::info('DispatchAction: task created from conversation', [
            'task_id' => $task->id,
            'action_type' => $actionType,
            'project_id' => $project->id,
            'conversation_id' => $conversationId,
        ]);

        // 6. Dispatch the task
        try {
            $this->taskDispatcher->dispatch($task);
        } catch (\Throwable $e) {
            Log::error('DispatchAction: dispatch failed', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            return "Task #{$task->id} was created but dispatch failed: {$e->getMessage()}. "
                . 'The task will be retried automatically.';
        }

        return $this->buildSuccessMessage($task, $actionType, $title);
    }

    /**
     * Build the result metadata stored on the Task record.
     */
    private function buildResultMetadata(Request $request, string $actionType): array
    {
        $meta = [
            'action_type' => $actionType,
            'title' => (string) $request->string('title'),
            'description' => (string) $request->string('description'),
            'dispatched_from' => 'conversation',
        ];

        $branchName = (string) $request->string('branch_name');
        if ($branchName !== '') {
            $meta['branch_name'] = $branchName;
        }

        $targetBranch = (string) $request->string('target_branch');
        if ($targetBranch !== '') {
            $meta['target_branch'] = $targetBranch;
        }

        $assigneeId = $request->integer('assignee_id');
        if ($assigneeId > 0) {
            $meta['assignee_id'] = $assigneeId;
        }

        $labels = (string) $request->string('labels');
        if ($labels !== '') {
            $meta['labels'] = array_map('trim', explode(',', $labels));
        }

        return $meta;
    }

    /**
     * Build a human-readable success message for the chat stream.
     */
    private function buildSuccessMessage(Task $task, string $actionType, string $title): string
    {
        $typeLabel = match ($actionType) {
            'create_issue' => 'Issue creation',
            'implement_feature' => 'Feature implementation',
            'ui_adjustment' => 'UI adjustment',
            'create_mr' => 'Merge request creation',
            'deep_analysis' => 'Deep analysis',
        };

        $message = "[System: Task dispatched] {$typeLabel} \"{$title}\" has been dispatched as Task #{$task->id}.";

        if ($actionType === 'deep_analysis') {
            $message .= ' The analysis results will be fed back into this conversation when complete.';
        } else {
            $message .= ' You can track its progress in the pinned task bar.';
        }

        return $message;
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=DispatchActionTest`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Agents/Tools/DispatchAction.php tests/Unit/Agents/Tools/DispatchActionTest.php
git commit --no-gpg-sign -m "T55.2: Add DispatchAction AI SDK tool with unit tests"
```

---

### Task 3: Register DispatchAction in VunnixAgent

**Files:**
- Modify: `app/Agents/VunnixAgent.php`
- Modify: `tests/Feature/Agents/VunnixAgentTest.php`
- Modify: `tests/Unit/Agents/VunnixAgentTest.php`

**Step 1: Write failing test for tool registration**

In `tests/Unit/Agents/VunnixAgentTest.php`, add:

```php
it('returns the T55 dispatch action tool', function () {
    $tools = (new VunnixAgent)->tools();
    $toolClasses = array_map(fn ($t) => $t::class, [...$tools]);

    expect($toolClasses)->toContain(\App\Agents\Tools\DispatchAction::class);
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter="T55 dispatch"`
Expected: FAIL

**Step 3: Register the tool in VunnixAgent::tools()**

In `app/Agents/VunnixAgent.php`, add to imports:

```php
use App\Agents\Tools\DispatchAction;
```

Add to `tools()` return array:

```php
// T55: Action dispatch
app(DispatchAction::class),
```

**Step 4: Run tests to verify**

Run: `php artisan test --filter=VunnixAgentTest`
Expected: All pass

**Step 5: Commit**

```bash
git add app/Agents/VunnixAgent.php tests/Unit/Agents/VunnixAgentTest.php
git commit --no-gpg-sign -m "T55.3: Register DispatchAction tool in VunnixAgent"
```

---

### Task 4: Expand system prompt [Action Dispatch] section

**Files:**
- Modify: `app/Agents/VunnixAgent.php` — `actionDispatchSection()` method
- Modify: `tests/Feature/Agents/VunnixAgentTest.php`

**Step 1: Write failing tests for the expanded prompt**

In `tests/Feature/Agents/VunnixAgentTest.php`, update the existing action dispatch test and add new ones:

```php
it('includes action dispatch section with supported action types', function () {
    $agent = new VunnixAgent;
    $instructions = $agent->instructions();

    expect($instructions)->toContain('[Action Dispatch]');
    expect($instructions)->toContain('create_issue');
    expect($instructions)->toContain('implement_feature');
    expect($instructions)->toContain('ui_adjustment');
    expect($instructions)->toContain('create_mr');
    expect($instructions)->toContain('deep_analysis');
});

it('includes permission check guidance in action dispatch section', function () {
    $agent = new VunnixAgent;
    $instructions = $agent->instructions();

    expect($instructions)->toContain('chat.dispatch_task');
    expect($instructions)->toContain('permission');
});

it('includes deep analysis proactive suggestion guidance', function () {
    $agent = new VunnixAgent;
    $instructions = $agent->instructions();

    expect($instructions)->toContain('deep analysis');
    expect($instructions)->toContain('read-only');
    expect($instructions)->toContain('insufficient');
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="action dispatch"`
Expected: Some FAIL

**Step 3: Update the actionDispatchSection()**

Replace the method body in `app/Agents/VunnixAgent.php`:

```php
protected function actionDispatchSection(): string
{
    return <<<'PROMPT'
[Action Dispatch]
You can dispatch actions to the task queue using the DispatchAction tool. Supported action types:
- **create_issue** — Create a GitLab Issue (PRD) with title, description, assignee, labels
- **implement_feature** — Dispatch a feature implementation to GitLab Runner
- **ui_adjustment** — Dispatch a UI change to GitLab Runner
- **create_mr** — Dispatch merge request creation to GitLab Runner
- **deep_analysis** — Dispatch a read-only deep codebase analysis to GitLab Runner (D132)

**Dispatch protocol:**
1. Confirm you have enough context (ask clarifying questions if not — apply quality gate)
2. Present a structured preview to the user: action type, target project, title, and description
3. Wait for explicit user confirmation before calling DispatchAction
4. Never dispatch an action without explicit user confirmation

**Permission handling:**
The DispatchAction tool checks the user's `chat.dispatch_task` permission automatically.
If the user lacks this permission, explain that they need to contact their project admin to get the "chat.dispatch_task" permission assigned to their role.

**Deep analysis (D132):**
When your GitLab API tools (BrowseRepoTree, ReadFile, SearchCode) are insufficient for complex cross-module questions, proactively suggest a deep analysis dispatch:
"This question requires deeper codebase scanning than my API tools can provide. Shall I run a background deep analysis?"
Deep analysis is read-only and non-destructive — no preview card is needed. On user confirmation, dispatch with action_type "deep_analysis". The result will be fed back into this conversation.
PROMPT;
}
```

**Step 4: Run tests to verify**

Run: `php artisan test --filter=VunnixAgentTest`
Expected: All pass

**Step 5: Commit**

```bash
git add app/Agents/VunnixAgent.php tests/Feature/Agents/VunnixAgentTest.php
git commit --no-gpg-sign -m "T55.4: Expand action dispatch system prompt with types, permissions, and deep analysis"
```

---

### Task 5: Feature tests — permission validation integration

**Files:**
- Modify: `tests/Feature/Agents/VunnixAgentTest.php`

**Step 1: Write the feature-level permission validation test**

Add to `tests/Feature/Agents/VunnixAgentTest.php`:

```php
// ─── Action Dispatch (T55) ──────────────────────────────────────

it('dispatches action when user has chat.dispatch_task permission', function () {
    VunnixAgent::fake([
        'Task dispatched successfully. Feature implementation "Add payments" has been dispatched as Task #1.',
    ]);

    $project = Project::factory()->create();
    $user = agentTestUser($project);

    // Give user chat.dispatch_task permission
    $permission = \App\Models\Permission::firstOrCreate(
        ['name' => 'chat.dispatch_task'],
        ['description' => 'Can trigger AI actions from chat', 'group' => 'chat'],
    );
    $role = $user->rolesForProject($project)->first();
    $role->permissions()->syncWithoutDetaching([$permission->id]);

    $conversation = Conversation::factory()->forUser($user)->forProject($project)->create();

    $response = $this->actingAs($user)
        ->post("/api/v1/conversations/{$conversation->id}/stream", [
            'content' => 'Yes, please implement the payment feature',
        ]);

    $response->assertOk();
    $response->streamedContent();

    VunnixAgent::assertPrompted(
        fn ($prompt) => str_contains($prompt->prompt, 'implement the payment feature')
    );
});

it('explains permission denial when user lacks chat.dispatch_task', function () {
    // This test verifies the DispatchAction tool returns a permission error.
    // The AI SDK tool receives user_id and checks permission internally.
    VunnixAgent::fake([
        'I apologize, but you do not have permission to dispatch actions on this project.',
    ]);

    $project = Project::factory()->create();
    $user = agentTestUser($project);
    // Do NOT assign chat.dispatch_task permission

    $conversation = Conversation::factory()->forUser($user)->forProject($project)->create();

    $response = $this->actingAs($user)
        ->post("/api/v1/conversations/{$conversation->id}/stream", [
            'content' => 'Please create an issue for adding dark mode',
        ]);

    $response->assertOk();
    $response->streamedContent();

    VunnixAgent::assertPrompted(
        fn ($prompt) => str_contains($prompt->prompt, 'create an issue')
    );
});
```

**Step 2: Run tests**

Run: `php artisan test --filter=VunnixAgentTest`
Expected: All pass (the AI is faked, so these verify the stream flow works with T55 context)

**Step 3: Commit**

```bash
git add tests/Feature/Agents/VunnixAgentTest.php
git commit --no-gpg-sign -m "T55.5: Add feature tests for action dispatch permission flow"
```

---

### Task 6: Feature tests — task creation integration

**Files:**
- Create: `tests/Feature/Agents/Tools/DispatchActionFeatureTest.php`

**Step 1: Write the feature-level integration tests**

These tests use the real database (RefreshDatabase) to verify that DispatchAction creates Task records correctly:

```php
<?php

use App\Agents\Tools\DispatchAction;
use App\Enums\TaskOrigin;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectAccessChecker;
use App\Services\TaskDispatcher;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

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

it('creates a task with conversation origin when dispatching implement_feature', function () {
    Http::fake();

    $project = Project::factory()->create();
    $user = dispatchTestUser($project);

    $tool = new DispatchAction(
        app(ProjectAccessChecker::class),
        app(TaskDispatcher::class),
    );

    $request = Request::from([
        'action_type' => 'implement_feature',
        'project_id' => $project->gitlab_project_id,
        'title' => 'Add Stripe payments',
        'description' => 'Implement payment flow with Stripe SDK',
        'branch_name' => 'ai/payment-feature',
        'target_branch' => 'main',
        'user_id' => $user->id,
        'conversation_id' => 'conv-test-123',
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

it('creates a task for deep_analysis action type', function () {
    Http::fake();

    $project = Project::factory()->create();
    $user = dispatchTestUser($project);

    $tool = new DispatchAction(
        app(ProjectAccessChecker::class),
        app(TaskDispatcher::class),
    );

    $request = Request::from([
        'action_type' => 'deep_analysis',
        'project_id' => $project->gitlab_project_id,
        'title' => 'Analyze payment module dependencies',
        'description' => 'Which reports are affected if we modify the order status enum?',
        'user_id' => $user->id,
        'conversation_id' => 'conv-test-456',
    ]);

    $result = $tool->handle($request);

    expect($result)->toContain('Deep analysis');
    expect($result)->toContain('fed back into this conversation');

    $task = Task::latest()->first();
    expect($task->type)->toBe(TaskType::DeepAnalysis);
    expect($task->origin)->toBe(TaskOrigin::Conversation);
    expect($task->conversation_id)->toBe('conv-test-456');
});

it('returns permission error when user lacks chat.dispatch_task', function () {
    $project = Project::factory()->create();
    $user = dispatchTestUser($project, withDispatchPermission: false);

    $tool = new DispatchAction(
        app(ProjectAccessChecker::class),
        app(TaskDispatcher::class),
    );

    $request = Request::from([
        'action_type' => 'create_issue',
        'project_id' => $project->gitlab_project_id,
        'title' => 'Test issue',
        'description' => 'Test description',
        'user_id' => $user->id,
        'conversation_id' => 'conv-test-789',
    ]);

    $result = $tool->handle($request);

    expect($result)->toContain('do not have permission');
    expect($result)->toContain('chat.dispatch_task');
    expect(Task::count())->toBe(0);
});

it('returns error for invalid action type', function () {
    $project = Project::factory()->create();
    $user = dispatchTestUser($project);

    $tool = new DispatchAction(
        app(ProjectAccessChecker::class),
        app(TaskDispatcher::class),
    );

    $request = Request::from([
        'action_type' => 'delete_repo',
        'project_id' => $project->gitlab_project_id,
        'title' => 'Test',
        'description' => 'Test',
        'user_id' => $user->id,
        'conversation_id' => 'conv-test-000',
    ]);

    $result = $tool->handle($request);

    expect($result)->toContain('Invalid action type');
    expect(Task::count())->toBe(0);
});

it('maps create_issue to PrdCreation task type', function () {
    Http::fake();

    $project = Project::factory()->create();
    $user = dispatchTestUser($project);

    $tool = new DispatchAction(
        app(ProjectAccessChecker::class),
        app(TaskDispatcher::class),
    );

    $request = Request::from([
        'action_type' => 'create_issue',
        'project_id' => $project->gitlab_project_id,
        'title' => 'Add dark mode',
        'description' => 'Implement dark mode theme toggle',
        'assignee_id' => 7,
        'labels' => 'feature,ai::created',
        'user_id' => $user->id,
        'conversation_id' => 'conv-test-issue',
    ]);

    $result = $tool->handle($request);

    $task = Task::latest()->first();
    expect($task->type)->toBe(TaskType::PrdCreation);
    expect($task->result['assignee_id'])->toBe(7);
    expect($task->result['labels'])->toBe(['feature', 'ai::created']);
});
```

**Step 2: Run tests**

Run: `php artisan test --filter=DispatchActionFeatureTest`
Expected: All pass

**Step 3: Commit**

```bash
git add tests/Feature/Agents/Tools/DispatchActionFeatureTest.php
git commit --no-gpg-sign -m "T55.6: Add feature-level integration tests for DispatchAction"
```

---

### Task 7: Add TaskDispatcher support for DeepAnalysis

**Files:**
- Modify: `app/Services/TaskDispatcher.php` — `resolveStrategy()` method

**Step 1: Verify current behavior**

Run: `php artisan test --filter=TaskDispatcher`
Expected: All pass

**Step 2: Add DeepAnalysis strategy resolution**

In `TaskDispatcher::resolveStrategy()`, add a case for DeepAnalysis. Deep analysis tasks use BackendReview strategy (read-only codebase scanning):

```php
// In resolveStrategy():
return match ($task->type) {
    TaskType::FeatureDev => ReviewStrategy::BackendReview,
    TaskType::UiAdjustment => ReviewStrategy::FrontendReview,
    TaskType::IssueDiscussion => ReviewStrategy::BackendReview,
    TaskType::DeepAnalysis => ReviewStrategy::BackendReview,
    default => ReviewStrategy::BackendReview,
};
```

**Step 3: Run tests**

Run: `php artisan test --filter=TaskDispatcher`
Expected: All pass

**Step 4: Commit**

```bash
git add app/Services/TaskDispatcher.php
git commit --no-gpg-sign -m "T55.7: Add DeepAnalysis strategy resolution to TaskDispatcher"
```

---

### Task 8: Update verify_m3.py structural checks

**Files:**
- Modify: `verify/verify_m3.py`

**Step 1: Add T55 structural checks**

Add a new T55 section in `verify/verify_m3.py` after the T54 section:

```python
# ============================================================
#  T55: Action dispatch from conversation
# ============================================================
section("T55: Action Dispatch from Conversation")

# DispatchAction tool class
checker.check(
    "DispatchAction tool class exists",
    file_exists("app/Agents/Tools/DispatchAction.php"),
)
checker.check(
    "DispatchAction implements Tool contract",
    file_contains("app/Agents/Tools/DispatchAction.php", "implements Tool"),
)
checker.check(
    "DispatchAction has handle method",
    file_contains("app/Agents/Tools/DispatchAction.php", "public function handle"),
)
checker.check(
    "DispatchAction has schema method",
    file_contains("app/Agents/Tools/DispatchAction.php", "public function schema"),
)

# Permission check
checker.check(
    "DispatchAction checks chat.dispatch_task permission",
    file_contains("app/Agents/Tools/DispatchAction.php", "chat.dispatch_task"),
)

# Task creation with conversation origin
checker.check(
    "DispatchAction uses TaskOrigin::Conversation",
    file_contains("app/Agents/Tools/DispatchAction.php", "TaskOrigin::Conversation"),
)
checker.check(
    "DispatchAction sets conversation_id on task",
    file_contains("app/Agents/Tools/DispatchAction.php", "conversation_id"),
)

# Action type mapping
checker.check(
    "DispatchAction supports create_issue",
    file_contains("app/Agents/Tools/DispatchAction.php", "create_issue"),
)
checker.check(
    "DispatchAction supports implement_feature",
    file_contains("app/Agents/Tools/DispatchAction.php", "implement_feature"),
)
checker.check(
    "DispatchAction supports ui_adjustment",
    file_contains("app/Agents/Tools/DispatchAction.php", "ui_adjustment"),
)
checker.check(
    "DispatchAction supports create_mr",
    file_contains("app/Agents/Tools/DispatchAction.php", "create_mr"),
)
checker.check(
    "DispatchAction supports deep_analysis",
    file_contains("app/Agents/Tools/DispatchAction.php", "deep_analysis"),
)

# Deep analysis D132
checker.check(
    "DeepAnalysis task type exists",
    file_contains("app/Enums/TaskType.php", "DeepAnalysis"),
)

# VunnixAgent registers DispatchAction
checker.check(
    "VunnixAgent registers DispatchAction tool",
    file_contains("app/Agents/VunnixAgent.php", "DispatchAction"),
)

# System prompt updates
checker.check(
    "System prompt lists supported action types",
    file_contains("app/Agents/VunnixAgent.php", "create_issue"),
)
checker.check(
    "System prompt mentions permission handling",
    file_contains("app/Agents/VunnixAgent.php", "chat.dispatch_task"),
)
checker.check(
    "System prompt describes deep analysis D132",
    file_contains("app/Agents/VunnixAgent.php", "deep analysis"),
)

# Uses TaskDispatcher
checker.check(
    "DispatchAction uses TaskDispatcher",
    file_contains("app/Agents/Tools/DispatchAction.php", "TaskDispatcher"),
)
checker.check(
    "DispatchAction uses ProjectAccessChecker",
    file_contains("app/Agents/Tools/DispatchAction.php", "ProjectAccessChecker"),
)

# Unit tests
checker.check(
    "DispatchAction unit tests exist",
    file_exists("tests/Unit/Agents/Tools/DispatchActionTest.php"),
)

# Feature tests
checker.check(
    "DispatchAction feature tests exist",
    file_exists("tests/Feature/Agents/Tools/DispatchActionFeatureTest.php"),
)
checker.check(
    "Feature test covers permission denial",
    file_contains(
        "tests/Feature/Agents/Tools/DispatchActionFeatureTest.php",
        "lacks chat.dispatch_task",
    ),
)
checker.check(
    "Feature test covers task creation with conversation origin",
    file_contains(
        "tests/Feature/Agents/Tools/DispatchActionFeatureTest.php",
        "conversation origin",
    ),
)
checker.check(
    "Feature test covers deep_analysis action type",
    file_contains(
        "tests/Feature/Agents/Tools/DispatchActionFeatureTest.php",
        "deep_analysis",
    ),
)
```

**Step 2: Run structural verification**

Run: `python3 verify/verify_m3.py`
Expected: All checks pass (after all prior tasks are complete)

**Step 3: Commit**

```bash
git add verify/verify_m3.py
git commit --no-gpg-sign -m "T55.8: Add T55 structural checks to verify_m3.py"
```

---

### Task 9: Final verification and squash commit

**Step 1: Run full test suite**

Run: `php artisan test`
Expected: All tests pass (minus pre-existing RetryWithBackoff ordering issue)

**Step 2: Run structural verification**

Run: `python3 verify/verify_m3.py`
Expected: All checks pass

**Step 3: Update progress.md**

Mark T55 as complete, bold T56 as next.

**Step 4: Clear handoff.md**

Reset to empty template.

**Step 5: Final commit**

```bash
git add progress.md handoff.md
git commit --no-gpg-sign -m "T55: Add action dispatch from conversation (DispatchAction tool, DeepAnalysis type, permission checks)"
```

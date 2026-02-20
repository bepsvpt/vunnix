# T73: Reverb Channel Configuration — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Define Laravel Broadcasting channels for real-time task status updates, activity feed, and metrics — with RBAC-scoped channel authorization.

**Architecture:** Three Reverb channels (`task.{id}`, `project.{id}.activity`, `metrics.{projectId}`) authorized via project membership. Broadcast events fire from `TaskObserver` on status transitions. Testing uses `Event::fake()` per §22.1.

**Tech Stack:** Laravel 12 + Reverb 1.7, Laravel Broadcasting, Pest

---

### Task 1: Publish broadcasting config

**Files:**
- Create: `config/broadcasting.php`

**Step 1: Publish the default broadcasting config**

```bash
php artisan vendor:publish --tag=laravel-broadcasting
```

This generates `config/broadcasting.php` with the Reverb driver pre-configured. The `.env` already has `BROADCAST_CONNECTION=reverb` and all `REVERB_*` variables set.

**Step 2: Verify the config loads**

```bash
php artisan config:show broadcasting
```

Expected: Shows `default: reverb` and connection details matching `.env`.

**Step 3: Commit**

```bash
git add config/broadcasting.php
git commit --no-gpg-sign -m "T73.1: Publish broadcasting config

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 2: Create TaskStatusChanged broadcast event

**Files:**
- Create: `app/Events/TaskStatusChanged.php`
- Test: `tests/Feature/Events/TaskStatusChangedTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Events\TaskStatusChanged;
use App\Models\Task;
use Illuminate\Broadcasting\PrivateChannel;

uses(\Tests\TestCase::class);

test('TaskStatusChanged broadcasts on private task channel', function () {
    $task = Task::factory()->create(['status' => 'completed']);

    $event = new TaskStatusChanged($task);

    expect($event->broadcastOn())
        ->toBeArray()
        ->toHaveCount(2);

    $channels = $event->broadcastOn();
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe("private-task.{$task->id}");
    expect($channels[1])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[1]->name)->toBe("private-project.{$task->project_id}.activity");
});

test('TaskStatusChanged payload includes status and task summary', function () {
    $task = Task::factory()->create([
        'status' => 'completed',
        'type' => 'code_review',
        'pipeline_id' => 12345,
    ]);

    $event = new TaskStatusChanged($task);
    $data = $event->broadcastWith();

    expect($data)->toHaveKeys(['task_id', 'status', 'type', 'project_id', 'pipeline_id', 'timestamp']);
    expect($data['task_id'])->toBe($task->id);
    expect($data['status'])->toBe('completed');
    expect($data['type'])->toBe('code_review');
    expect($data['project_id'])->toBe($task->project_id);
    expect($data['pipeline_id'])->toBe(12345);
});

test('TaskStatusChanged event name is task.status.changed', function () {
    $task = Task::factory()->create();

    $event = new TaskStatusChanged($task);

    expect($event->broadcastAs())->toBe('task.status.changed');
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --filter=TaskStatusChanged
```

Expected: FAIL — class not found.

**Step 3: Implement TaskStatusChanged**

```php
<?php

namespace App\Events;

use App\Models\Task;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Task $task,
    ) {}

    /**
     * Broadcast on both the task-specific channel and the project activity channel.
     *
     * @return array<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("task.{$this->task->id}"),
            new PrivateChannel("project.{$this->task->project_id}.activity"),
        ];
    }

    /**
     * Data payload sent to clients.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'task_id' => $this->task->id,
            'status' => $this->task->status->value,
            'type' => $this->task->type->value,
            'project_id' => $this->task->project_id,
            'pipeline_id' => $this->task->pipeline_id,
            'mr_iid' => $this->task->mr_iid,
            'issue_iid' => $this->task->issue_iid,
            'result_summary' => $this->task->isTerminal() ? ($this->task->result['summary'] ?? null) : null,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'task.status.changed';
    }
}
```

**Step 4: Run tests to verify they pass**

```bash
php artisan test --filter=TaskStatusChanged
```

Expected: PASS.

**Step 5: Commit**

```bash
git add app/Events/TaskStatusChanged.php tests/Feature/Events/TaskStatusChangedTest.php
git commit --no-gpg-sign -m "T73.2: Add TaskStatusChanged broadcast event

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 3: Wire TaskObserver to dispatch broadcast event

**Files:**
- Modify: `app/Observers/TaskObserver.php`
- Test: `tests/Feature/Observers/TaskObserverBroadcastTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Enums\TaskStatus;
use App\Events\TaskStatusChanged;
use App\Models\Task;
use Illuminate\Support\Facades\Event;

uses(\Tests\TestCase::class);

test('TaskObserver dispatches TaskStatusChanged on status transition', function () {
    Event::fake([TaskStatusChanged::class]);

    $task = Task::factory()->create(['status' => TaskStatus::Queued]);

    $task->transitionTo(TaskStatus::Running);

    Event::assertDispatched(TaskStatusChanged::class, function ($event) use ($task) {
        return $event->task->id === $task->id
            && $event->task->status === TaskStatus::Running;
    });
});

test('TaskObserver does not dispatch TaskStatusChanged when non-status field changes', function () {
    Event::fake([TaskStatusChanged::class]);

    $task = Task::factory()->create(['status' => TaskStatus::Running]);

    $task->update(['tokens_used' => 500]);

    Event::assertNotDispatched(TaskStatusChanged::class);
});

test('TaskObserver dispatches on terminal transitions with result data', function () {
    Event::fake([TaskStatusChanged::class]);

    $task = Task::factory()->create(['status' => TaskStatus::Running]);

    $task->result = ['summary' => 'Review complete', 'severity' => 'clean'];
    $task->transitionTo(TaskStatus::Completed);

    Event::assertDispatched(TaskStatusChanged::class, function ($event) {
        return $event->task->status === TaskStatus::Completed;
    });
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --filter=TaskObserverBroadcast
```

Expected: FAIL — event not dispatched.

**Step 3: Add broadcast dispatch to TaskObserver**

Modify `app/Observers/TaskObserver.php` — add the event dispatch after the transition log insert:

```php
<?php

namespace App\Observers;

use App\Events\TaskStatusChanged;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

class TaskObserver
{
    /**
     * Log state transitions and broadcast status changes whenever a task is updated.
     */
    public function updated(Task $task): void
    {
        if (! $task->wasChanged('status')) {
            return;
        }

        // Log the transition
        DB::table('task_transition_logs')->insert([
            'task_id' => $task->id,
            'from_status' => $task->getOriginal('status') instanceof \App\Enums\TaskStatus
                ? $task->getOriginal('status')->value
                : $task->getOriginal('status'),
            'to_status' => $task->status->value,
            'transitioned_at' => now(),
        ]);

        // Broadcast the status change via Reverb
        TaskStatusChanged::dispatch($task);
    }
}
```

**Step 4: Run tests to verify they pass**

```bash
php artisan test --filter=TaskObserverBroadcast
```

Expected: PASS.

**Step 5: Run existing TaskObserver/lifecycle tests to verify no regression**

```bash
php artisan test --filter=TaskLifecycle
```

Expected: PASS — existing tests unaffected (they don't fake events, so the broadcast dispatches silently).

**Step 6: Commit**

```bash
git add app/Observers/TaskObserver.php tests/Feature/Observers/TaskObserverBroadcastTest.php
git commit --no-gpg-sign -m "T73.3: Wire TaskObserver to dispatch TaskStatusChanged broadcast

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 4: Define channel authorization routes

**Files:**
- Create: `routes/channels.php`
- Test: `tests/Feature/Broadcasting/ChannelAuthorizationTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

uses(\Tests\TestCase::class);

test('task channel authorizes user with access to task project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['enabled' => true]);
    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);
    $task = Task::factory()->create(['project_id' => $project->id]);

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-task.{$task->id}",
        ])
        ->assertOk();
});

test('task channel rejects user without access to task project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['enabled' => true]);
    $task = Task::factory()->create(['project_id' => $project->id]);

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-task.{$task->id}",
        ])
        ->assertForbidden();
});

test('project activity channel authorizes project member', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['enabled' => true]);
    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-project.{$project->id}.activity",
        ])
        ->assertOk();
});

test('project activity channel rejects non-member', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['enabled' => true]);

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-project.{$project->id}.activity",
        ])
        ->assertForbidden();
});

test('metrics channel authorizes project member', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['enabled' => true]);
    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-metrics.{$project->id}",
        ])
        ->assertOk();
});

test('metrics channel rejects non-member', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['enabled' => true]);

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-metrics.{$project->id}",
        ])
        ->assertForbidden();
});

test('unauthenticated user cannot authorize any channel', function () {
    $task = Task::factory()->create();

    $this->post('/broadcasting/auth', [
        'channel_name' => "private-task.{$task->id}",
    ])->assertUnauthorized();
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --filter=ChannelAuthorization
```

Expected: FAIL — channels.php not loaded / no channel definitions.

**Step 3: Create routes/channels.php**

```php
<?php

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Channel authorization callbacks. Each returns true/false to allow/deny
| the authenticated user's subscription to the private channel.
|
*/

/**
 * task.{id} — User must have access to the task's project.
 */
Broadcast::channel('task.{taskId}', function (User $user, int $taskId) {
    $task = Task::find($taskId);

    if (! $task) {
        return false;
    }

    return $user->projects()
        ->where('projects.id', $task->project_id)
        ->exists();
});

/**
 * project.{id}.activity — User must be a member of the project.
 */
Broadcast::channel('project.{projectId}.activity', function (User $user, int $projectId) {
    return $user->projects()
        ->where('projects.id', $projectId)
        ->exists();
});

/**
 * metrics.{projectId} — User must be a member of the project.
 */
Broadcast::channel('metrics.{projectId}', function (User $user, int $projectId) {
    return $user->projects()
        ->where('projects.id', $projectId)
        ->exists();
});
```

**Step 4: Register channels.php and the /broadcasting/auth route**

Laravel 12 requires explicitly loading channels and the broadcast auth routes. Check `bootstrap/app.php` or `routes/` setup. Add to `bootstrap/app.php` if not already present:

```php
->withBroadcasting(
    __DIR__.'/../routes/channels.php',
    ['middleware' => ['web', 'auth']],
)
```

The `withBroadcasting()` call both loads `routes/channels.php` AND registers the `/broadcasting/auth` endpoint with the specified middleware.

**Step 5: Run tests to verify they pass**

```bash
php artisan test --filter=ChannelAuthorization
```

Expected: PASS.

**Step 6: Commit**

```bash
git add routes/channels.php bootstrap/app.php tests/Feature/Broadcasting/ChannelAuthorizationTest.php
git commit --no-gpg-sign -m "T73.4: Define Reverb channel authorization routes

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 5: Integration test — task lifecycle triggers broadcast

**Files:**
- Test: `tests/Feature/Broadcasting/TaskLifecycleBroadcastTest.php`

**Step 1: Write the integration test**

This verifies the full chain: `transitionTo()` → `TaskObserver` → `TaskStatusChanged` dispatched on correct channels with correct payload.

```php
<?php

use App\Enums\TaskStatus;
use App\Events\TaskStatusChanged;
use App\Models\Task;
use Illuminate\Support\Facades\Event;

uses(\Tests\TestCase::class);

test('task completing dispatches broadcast with status and result summary', function () {
    Event::fake([TaskStatusChanged::class]);

    $task = Task::factory()->create([
        'status' => TaskStatus::Running,
        'type' => 'code_review',
        'pipeline_id' => 99,
    ]);

    $task->result = ['summary' => 'All checks passed'];
    $task->transitionTo(TaskStatus::Completed);

    Event::assertDispatched(TaskStatusChanged::class, function ($event) use ($task) {
        $data = $event->broadcastWith();

        return $event->task->id === $task->id
            && $data['status'] === 'completed'
            && $data['type'] === 'code_review'
            && $data['pipeline_id'] === 99
            && $data['result_summary'] === 'All checks passed'
            && $data['project_id'] === $task->project_id;
    });
});

test('task failing dispatches broadcast with failed status', function () {
    Event::fake([TaskStatusChanged::class]);

    $task = Task::factory()->create(['status' => TaskStatus::Running]);

    $task->transitionTo(TaskStatus::Failed, 'Runner timeout');

    Event::assertDispatched(TaskStatusChanged::class, function ($event) {
        return $event->task->status === TaskStatus::Failed
            && $event->broadcastWith()['status'] === 'failed';
    });
});

test('activity feed channel receives task status events', function () {
    Event::fake([TaskStatusChanged::class]);

    $task = Task::factory()->create(['status' => TaskStatus::Queued]);

    $task->transitionTo(TaskStatus::Running);

    Event::assertDispatched(TaskStatusChanged::class, function ($event) use ($task) {
        $channels = collect($event->broadcastOn())->map->name;
        return $channels->contains("private-project.{$task->project_id}.activity");
    });
});
```

**Step 2: Run tests**

```bash
php artisan test --filter=TaskLifecycleBroadcast
```

Expected: PASS.

**Step 3: Commit**

```bash
git add tests/Feature/Broadcasting/TaskLifecycleBroadcastTest.php
git commit --no-gpg-sign -m "T73.5: Add task lifecycle broadcast integration tests

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 6: Verification script and full test run

**Files:**
- Modify: `verify/verify_m3.py` (add T73 checks since T73 is built as a cross-milestone dep for M3)

**Step 1: Add T73 structural checks to verify_m3.py**

Append a new section at the end (before `checker.summary()`):

```python
# ============================================================
#  T73: Reverb channel configuration (cross-milestone dep)
# ============================================================
section("T73: Reverb Channel Configuration")

checker.check(
    "Broadcasting config exists",
    file_exists("config/broadcasting.php"),
)
checker.check(
    "Channel routes exist",
    file_exists("routes/channels.php"),
)
checker.check(
    "TaskStatusChanged event exists",
    file_exists("app/Events/TaskStatusChanged.php"),
)
checker.check(
    "TaskStatusChanged implements ShouldBroadcast",
    file_contains("app/Events/TaskStatusChanged.php", "ShouldBroadcast"),
)
checker.check(
    "TaskStatusChanged broadcasts on task channel",
    file_contains("app/Events/TaskStatusChanged.php", "task.{$this->task->id}"),
)
checker.check(
    "TaskStatusChanged broadcasts on project activity channel",
    file_contains("app/Events/TaskStatusChanged.php", "project.{$this->task->project_id}.activity"),
)
checker.check(
    "Channel auth for task channel",
    file_contains("routes/channels.php", "task.{taskId}"),
)
checker.check(
    "Channel auth for project activity channel",
    file_contains("routes/channels.php", "project.{projectId}.activity"),
)
checker.check(
    "Channel auth for metrics channel",
    file_contains("routes/channels.php", "metrics.{projectId}"),
)
checker.check(
    "TaskObserver dispatches TaskStatusChanged",
    file_contains("app/Observers/TaskObserver.php", "TaskStatusChanged::dispatch"),
)
```

**Step 2: Run full verification**

```bash
php artisan test
python3 verify/verify_m3.py
```

Expected: Both pass.

**Step 3: Final commit with progress update**

Update `progress.md`: mark T73 `[x]`, bold T69 as next.

```bash
git add verify/verify_m3.py progress.md handoff.md
git commit --no-gpg-sign -m "T73: Complete Reverb channel configuration

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Notes for Implementer

- **Existing TaskObserver tests:** The `TaskLifecycleTest.php` tests don't use `Event::fake()`, so the new broadcast dispatch will fire silently (no real Reverb server in test). If any test hits an issue with broadcasting, add `Event::fake([TaskStatusChanged::class])` in that specific test.
- **Sync queue caution (from Learnings):** If existing tests run on sync queue and the broadcast event triggers downstream side effects, you may need to fake the event in those tests. Check for failures after wiring the observer.
- **Broadcasting auth route:** Laravel 12 uses `withBroadcasting()` in `bootstrap/app.php`. Check the existing file first — it may already have a `withRouting()` call that needs the broadcasting addition nearby.
- **No frontend in T73:** Laravel Echo setup is T74. T73 is backend-only (channels + events + authorization).
- **Metrics channel:** T73 defines the channel but no event class dispatches to it yet. That comes in T84 (metrics aggregation). The channel authorization is set up now so it's ready when needed.

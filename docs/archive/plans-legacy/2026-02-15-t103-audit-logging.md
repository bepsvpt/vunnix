# T103: Audit Logging Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement full-content audit logging for all AI interactions and administrative actions, with an admin-only API for querying logs.

**Architecture:** A single `AuditLog` model backed by the existing `audit_logs` migration stores all event types. An `AuditLogService` provides a `log()` method called from controllers, observers, and auth hooks. An `AuditLogController` exposes `GET /api/v1/audit-logs` with cursor pagination and filters (event_type, user_id, project_id, date_from, date_to). The six event types (conversation_turn, task_execution, action_dispatch, configuration_change, webhook_received, auth_event) share the same table — `event_type` discriminates and `properties` (JSONB) holds type-specific data per D98 (full content, never truncated).

**Tech Stack:** Laravel 11, Pest, PostgreSQL (JSONB), Eloquent API Resources

---

### Task 1: Create AuditLog model

**Files:**
- Create: `app/Models/AuditLog.php`

**Step 1: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_type',
        'user_id',
        'project_id',
        'task_id',
        'conversation_id',
        'summary',
        'properties',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
```

**Step 2: Create the factory**

Create `database/factories/AuditLogFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'event_type' => $this->faker->randomElement([
                'conversation_turn', 'task_execution', 'action_dispatch',
                'configuration_change', 'webhook_received', 'auth_event',
            ]),
            'summary' => $this->faker->sentence(),
            'properties' => ['detail' => $this->faker->sentence()],
        ];
    }

    public function conversationTurn(): static
    {
        return $this->state(fn () => [
            'event_type' => 'conversation_turn',
            'summary' => 'Conversation turn recorded',
            'properties' => [
                'user_message' => 'Hello, review my code',
                'ai_response' => 'I will review your code now.',
                'tool_calls' => [],
                'tokens_used' => 150,
                'model' => 'claude-opus-4-6',
            ],
        ]);
    }

    public function taskExecution(): static
    {
        return $this->state(fn () => [
            'event_type' => 'task_execution',
            'summary' => 'Task execution completed',
            'properties' => [
                'task_type' => 'code_review',
                'prompt_sent' => 'Review this merge request...',
                'ai_response' => 'Found 3 issues...',
                'tokens_used' => 500,
                'cost' => 0.015,
                'duration_seconds' => 45,
                'result_status' => 'completed',
            ],
        ]);
    }

    public function configurationChange(): static
    {
        return $this->state(fn () => [
            'event_type' => 'configuration_change',
            'summary' => 'Configuration changed: ai_model',
            'properties' => [
                'key' => 'ai_model',
                'old_value' => 'claude-opus-4-6',
                'new_value' => 'claude-sonnet-4-20250514',
            ],
        ]);
    }

    public function authEvent(): static
    {
        return $this->state(fn () => [
            'event_type' => 'auth_event',
            'summary' => 'User logged in',
            'properties' => [
                'action' => 'login',
            ],
        ]);
    }

    public function webhookReceived(): static
    {
        return $this->state(fn () => [
            'event_type' => 'webhook_received',
            'summary' => 'Webhook received: merge_request',
            'properties' => [
                'gitlab_event_type' => 'merge_request',
                'relevant_ids' => ['mr_iid' => 42],
            ],
        ]);
    }

    public function actionDispatch(): static
    {
        return $this->state(fn () => [
            'event_type' => 'action_dispatch',
            'summary' => 'Action dispatched: code_review',
            'properties' => [
                'action_type' => 'code_review',
                'gitlab_artifact_url' => 'https://gitlab.example.com/project/-/merge_requests/42',
            ],
        ]);
    }
}
```

**Step 3: Commit**

```bash
git add app/Models/AuditLog.php database/factories/AuditLogFactory.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T103.1: Add AuditLog model and factory

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Create AuditLogService

**Files:**
- Create: `app/Services/AuditLogService.php`
- Test: `tests/Feature/Services/AuditLogServiceTest.php`

**Step 1: Write the failing test**

Create `tests/Feature/Services/AuditLogServiceTest.php`:

```php
<?php

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Ensure agent_conversations table exists (required by migrations ordering)
    if (! Schema::hasTable('agent_conversations')) {
        Schema::create('agent_conversations', function ($table) {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('title');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });
    } elseif (! Schema::hasColumn('agent_conversations', 'project_id')) {
        Schema::table('agent_conversations', function ($table) {
            $table->unsignedBigInteger('project_id')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
    }

    if (! Schema::hasTable('agent_conversation_messages')) {
        Schema::create('agent_conversation_messages', function ($table) {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36)->index();
            $table->foreignId('user_id');
            $table->string('agent');
            $table->string('role', 25);
            $table->text('content');
            $table->text('attachments');
            $table->text('tool_calls');
            $table->text('tool_results');
            $table->text('usage');
            $table->text('meta');
            $table->timestamps();
        });
    }
});

it('logs a conversation turn with full content', function () {
    $user = User::factory()->create();
    $service = new AuditLogService();

    $service->logConversationTurn(
        userId: $user->id,
        conversationId: 'conv-abc-123',
        userMessage: 'Review my code please',
        aiResponse: 'I found 2 issues in your code.',
        toolCalls: [['name' => 'ReadFile', 'result' => 'contents...']],
        tokensUsed: 250,
        model: 'claude-opus-4-6',
    );

    $log = AuditLog::where('event_type', 'conversation_turn')->first();

    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
    expect($log->conversation_id)->toBe('conv-abc-123');
    expect($log->properties['user_message'])->toBe('Review my code please');
    expect($log->properties['ai_response'])->toBe('I found 2 issues in your code.');
    expect($log->properties['tool_calls'])->toHaveCount(1);
    expect($log->properties['tokens_used'])->toBe(250);
    expect($log->properties['model'])->toBe('claude-opus-4-6');
});

it('logs a task execution with prompt, response, cost', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $service = new AuditLogService();

    $service->logTaskExecution(
        taskId: 99,
        userId: $user->id,
        projectId: $project->id,
        taskType: 'code_review',
        gitlabContext: ['mr_iid' => 42],
        promptSent: 'Review this merge request diff...',
        aiResponse: 'Found 3 issues with severity high.',
        tokensUsed: 500,
        cost: 0.015,
        durationSeconds: 45,
        resultStatus: 'completed',
    );

    $log = AuditLog::where('event_type', 'task_execution')->first();

    expect($log)->not->toBeNull();
    expect($log->task_id)->toBe(99);
    expect($log->user_id)->toBe($user->id);
    expect($log->project_id)->toBe($project->id);
    expect($log->properties['prompt_sent'])->toBe('Review this merge request diff...');
    expect($log->properties['ai_response'])->toBe('Found 3 issues with severity high.');
    expect($log->properties['cost'])->toBe(0.015);
    expect($log->properties['duration_seconds'])->toBe(45);
    expect($log->properties['result_status'])->toBe('completed');
});

it('logs a configuration change with old and new values', function () {
    $user = User::factory()->create();
    $service = new AuditLogService();

    $service->logConfigurationChange(
        userId: $user->id,
        key: 'ai_model',
        oldValue: 'claude-opus-4-6',
        newValue: 'claude-sonnet-4-20250514',
    );

    $log = AuditLog::where('event_type', 'configuration_change')->first();

    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
    expect($log->properties['key'])->toBe('ai_model');
    expect($log->properties['old_value'])->toBe('claude-opus-4-6');
    expect($log->properties['new_value'])->toBe('claude-sonnet-4-20250514');
});

it('logs an action dispatch', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $service = new AuditLogService();

    $service->logActionDispatch(
        userId: $user->id,
        conversationId: 'conv-xyz-789',
        actionType: 'code_review',
        projectId: $project->id,
        gitlabArtifactUrl: 'https://gitlab.example.com/project/-/merge_requests/42',
    );

    $log = AuditLog::where('event_type', 'action_dispatch')->first();

    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
    expect($log->conversation_id)->toBe('conv-xyz-789');
    expect($log->project_id)->toBe($project->id);
    expect($log->properties['action_type'])->toBe('code_review');
    expect($log->properties['gitlab_artifact_url'])->toContain('merge_requests/42');
});

it('logs a webhook received event', function () {
    $project = Project::factory()->create();
    $service = new AuditLogService();

    $service->logWebhookReceived(
        projectId: $project->id,
        eventType: 'merge_request',
        relevantIds: ['mr_iid' => 42, 'action' => 'open'],
    );

    $log = AuditLog::where('event_type', 'webhook_received')->first();

    expect($log)->not->toBeNull();
    expect($log->user_id)->toBeNull(); // webhooks have no user
    expect($log->project_id)->toBe($project->id);
    expect($log->properties['gitlab_event_type'])->toBe('merge_request');
    expect($log->properties['relevant_ids'])->toBe(['mr_iid' => 42, 'action' => 'open']);
});

it('logs an auth event', function () {
    $user = User::factory()->create();
    $service = new AuditLogService();

    $service->logAuthEvent(
        userId: $user->id,
        action: 'login',
        ipAddress: '192.168.1.100',
        userAgent: 'Mozilla/5.0',
    );

    $log = AuditLog::where('event_type', 'auth_event')->first();

    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
    expect($log->properties['action'])->toBe('login');
    expect($log->ip_address)->toBe('192.168.1.100');
    expect($log->user_agent)->toBe('Mozilla/5.0');
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Feature/Services/AuditLogServiceTest.php
```

Expected: FAIL — `AuditLogService` class not found.

**Step 3: Implement AuditLogService**

Create `app/Services/AuditLogService.php`:

```php
<?php

namespace App\Services;

use App\Models\AuditLog;

class AuditLogService
{
    public function logConversationTurn(
        int $userId,
        string $conversationId,
        string $userMessage,
        string $aiResponse,
        array $toolCalls,
        int $tokensUsed,
        string $model,
    ): AuditLog {
        return AuditLog::create([
            'event_type' => 'conversation_turn',
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'summary' => 'Conversation turn recorded',
            'properties' => [
                'user_message' => $userMessage,
                'ai_response' => $aiResponse,
                'tool_calls' => $toolCalls,
                'tokens_used' => $tokensUsed,
                'model' => $model,
            ],
        ]);
    }

    public function logTaskExecution(
        int $taskId,
        ?int $userId,
        int $projectId,
        string $taskType,
        array $gitlabContext,
        string $promptSent,
        string $aiResponse,
        int $tokensUsed,
        float $cost,
        int $durationSeconds,
        string $resultStatus,
    ): AuditLog {
        return AuditLog::create([
            'event_type' => 'task_execution',
            'user_id' => $userId,
            'task_id' => $taskId,
            'project_id' => $projectId,
            'summary' => "Task execution completed: {$taskType}",
            'properties' => [
                'task_type' => $taskType,
                'gitlab_context' => $gitlabContext,
                'prompt_sent' => $promptSent,
                'ai_response' => $aiResponse,
                'tokens_used' => $tokensUsed,
                'cost' => $cost,
                'duration_seconds' => $durationSeconds,
                'result_status' => $resultStatus,
            ],
        ]);
    }

    public function logActionDispatch(
        int $userId,
        string $conversationId,
        string $actionType,
        int $projectId,
        ?string $gitlabArtifactUrl = null,
    ): AuditLog {
        return AuditLog::create([
            'event_type' => 'action_dispatch',
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'project_id' => $projectId,
            'summary' => "Action dispatched: {$actionType}",
            'properties' => [
                'action_type' => $actionType,
                'gitlab_artifact_url' => $gitlabArtifactUrl,
            ],
        ]);
    }

    public function logConfigurationChange(
        int $userId,
        string $key,
        mixed $oldValue,
        mixed $newValue,
        ?int $projectId = null,
    ): AuditLog {
        return AuditLog::create([
            'event_type' => 'configuration_change',
            'user_id' => $userId,
            'project_id' => $projectId,
            'summary' => "Configuration changed: {$key}",
            'properties' => [
                'key' => $key,
                'old_value' => $oldValue,
                'new_value' => $newValue,
            ],
        ]);
    }

    public function logWebhookReceived(
        int $projectId,
        string $eventType,
        array $relevantIds,
    ): AuditLog {
        return AuditLog::create([
            'event_type' => 'webhook_received',
            'project_id' => $projectId,
            'summary' => "Webhook received: {$eventType}",
            'properties' => [
                'gitlab_event_type' => $eventType,
                'relevant_ids' => $relevantIds,
            ],
        ]);
    }

    public function logAuthEvent(
        int $userId,
        string $action,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AuditLog {
        return AuditLog::create([
            'event_type' => 'auth_event',
            'user_id' => $userId,
            'summary' => "User {$action}",
            'properties' => [
                'action' => $action,
            ],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }
}
```

**Step 4: Run tests to verify they pass**

```bash
php artisan test tests/Feature/Services/AuditLogServiceTest.php
```

Expected: All 6 tests PASS.

**Step 5: Commit**

```bash
git add app/Services/AuditLogService.php tests/Feature/Services/AuditLogServiceTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T103.2: Add AuditLogService with tests for all 6 event types

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Create AuditLogResource

**Files:**
- Create: `app/Http/Resources/AuditLogResource.php`

**Step 1: Create the resource**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type,
            'user_id' => $this->user_id,
            'user_name' => $this->user?->name,
            'project_id' => $this->project_id,
            'project_name' => $this->project?->name,
            'task_id' => $this->task_id,
            'conversation_id' => $this->conversation_id,
            'summary' => $this->summary,
            'properties' => $this->properties,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
```

**Step 2: Commit**

```bash
git add app/Http/Resources/AuditLogResource.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T103.3: Add AuditLogResource for API responses

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Create AuditLogController and routes

**Files:**
- Create: `app/Http/Controllers/Api/AuditLogController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Http/Controllers/Api/AuditLogControllerTest.php`

**Step 1: Write the failing tests**

Create `tests/Feature/Http/Controllers/Api/AuditLogControllerTest.php`:

```php
<?php

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! Schema::hasTable('agent_conversations')) {
        Schema::create('agent_conversations', function ($table) {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('title');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });
    } elseif (! Schema::hasColumn('agent_conversations', 'project_id')) {
        Schema::table('agent_conversations', function ($table) {
            $table->unsignedBigInteger('project_id')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
    }

    if (! Schema::hasTable('agent_conversation_messages')) {
        Schema::create('agent_conversation_messages', function ($table) {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36)->index();
            $table->foreignId('user_id');
            $table->string('agent');
            $table->string('role', 25);
            $table->text('content');
            $table->text('attachments');
            $table->text('tool_calls');
            $table->text('tool_results');
            $table->text('usage');
            $table->text('meta');
            $table->timestamps();
        });
    }
});

/**
 * Helper: create an admin user with admin.global_config permission.
 */
function createAuditAdmin(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'admin']);
    $perm = Permission::firstOrCreate(
        ['name' => 'admin.global_config'],
        ['description' => 'Can edit global Vunnix settings', 'group' => 'admin']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

/**
 * Helper: create a non-admin user on a project.
 */
function createAuditRegularUser(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'developer']);
    $perm = Permission::firstOrCreate(
        ['name' => 'review.view'],
        ['description' => 'Can view AI review results', 'group' => 'review']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

// ─── Authentication & Authorization ──────────────────────────────

it('returns 401 for unauthenticated users on audit log index', function () {
    $this->getJson('/api/v1/audit-logs')
        ->assertUnauthorized();
});

it('returns 403 for non-admin users on audit log index', function () {
    $project = Project::factory()->enabled()->create();
    $user = createAuditRegularUser($project);

    $this->actingAs($user)
        ->getJson('/api/v1/audit-logs')
        ->assertForbidden();
});

// ─── GET /api/v1/audit-logs ──────────────────────────────────────

it('returns audit logs for admin users with cursor pagination', function () {
    $project = Project::factory()->enabled()->create();
    $user = createAuditAdmin($project);

    AuditLog::factory()->count(3)->create([
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/audit-logs')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [['id', 'event_type', 'summary', 'created_at']],
            'meta' => ['next_cursor', 'per_page'],
        ]);
});

it('returns empty data when no audit logs exist', function () {
    $project = Project::factory()->enabled()->create();
    $user = createAuditAdmin($project);

    $this->actingAs($user)
        ->getJson('/api/v1/audit-logs')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('filters audit logs by event_type', function () {
    $project = Project::factory()->enabled()->create();
    $user = createAuditAdmin($project);

    AuditLog::factory()->conversationTurn()->create(['user_id' => $user->id]);
    AuditLog::factory()->taskExecution()->create(['user_id' => $user->id]);
    AuditLog::factory()->authEvent()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/audit-logs?event_type=conversation_turn')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.event_type', 'conversation_turn');
});

it('filters audit logs by user_id', function () {
    $project = Project::factory()->enabled()->create();
    $admin = createAuditAdmin($project);
    $other = User::factory()->create();

    AuditLog::factory()->create(['user_id' => $admin->id]);
    AuditLog::factory()->create(['user_id' => $other->id]);

    $this->actingAs($admin)
        ->getJson("/api/v1/audit-logs?user_id={$other->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user_id', $other->id);
});

it('filters audit logs by project_id', function () {
    $project = Project::factory()->enabled()->create();
    $otherProject = Project::factory()->create();
    $user = createAuditAdmin($project);

    AuditLog::factory()->create(['project_id' => $project->id]);
    AuditLog::factory()->create(['project_id' => $otherProject->id]);

    $this->actingAs($user)
        ->getJson("/api/v1/audit-logs?project_id={$project->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.project_id', $project->id);
});

it('filters audit logs by date range', function () {
    $project = Project::factory()->enabled()->create();
    $user = createAuditAdmin($project);

    AuditLog::factory()->create([
        'user_id' => $user->id,
        'created_at' => '2026-02-01 12:00:00',
    ]);
    AuditLog::factory()->create([
        'user_id' => $user->id,
        'created_at' => '2026-02-10 12:00:00',
    ]);
    AuditLog::factory()->create([
        'user_id' => $user->id,
        'created_at' => '2026-02-20 12:00:00',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/audit-logs?date_from=2026-02-05&date_to=2026-02-15')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('paginates audit logs with cursor', function () {
    $project = Project::factory()->enabled()->create();
    $user = createAuditAdmin($project);

    AuditLog::factory()->count(5)->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/audit-logs?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $cursor = $response->json('meta.next_cursor');
    expect($cursor)->not->toBeNull();

    $this->actingAs($user)
        ->getJson("/api/v1/audit-logs?per_page=2&cursor={$cursor}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

// ─── GET /api/v1/audit-logs/{id} ─────────────────────────────────

it('returns a single audit log entry for admin', function () {
    $project = Project::factory()->enabled()->create();
    $user = createAuditAdmin($project);

    $log = AuditLog::factory()->conversationTurn()->create([
        'user_id' => $user->id,
        'conversation_id' => 'conv-test-123',
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/audit-logs/{$log->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $log->id)
        ->assertJsonPath('data.event_type', 'conversation_turn')
        ->assertJsonPath('data.conversation_id', 'conv-test-123')
        ->assertJsonStructure(['data' => ['id', 'event_type', 'summary', 'properties', 'created_at']]);
});

it('returns 403 for non-admin on audit log show', function () {
    $project = Project::factory()->enabled()->create();
    $user = createAuditRegularUser($project);

    $log = AuditLog::factory()->create();

    $this->actingAs($user)
        ->getJson("/api/v1/audit-logs/{$log->id}")
        ->assertForbidden();
});

it('returns 404 for non-existent audit log', function () {
    $project = Project::factory()->enabled()->create();
    $user = createAuditAdmin($project);

    $this->actingAs($user)
        ->getJson('/api/v1/audit-logs/99999')
        ->assertNotFound();
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Feature/Http/Controllers/Api/AuditLogControllerTest.php
```

Expected: FAIL — route not defined / controller not found.

**Step 3: Create AuditLogController**

Create `app/Http/Controllers/Api/AuditLogController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $perPage = min((int) ($request->input('per_page', 25)), 100);

        $query = AuditLog::with(['user', 'project'])
            ->when($request->filled('event_type'), fn ($q) => $q->where('event_type', $request->input('event_type')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->input('user_id')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->input('project_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->where('created_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->where('created_at', '<=', $request->input('date_to') . ' 23:59:59'))
            ->orderByDesc('id');

        $paginator = $query->cursorPaginate($perPage);

        return response()->json([
            'data' => AuditLogResource::collection($paginator->items()),
            'meta' => [
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'per_page' => $perPage,
            ],
        ]);
    }

    public function show(Request $request, AuditLog $auditLog): JsonResponse
    {
        $this->authorizeAdmin($request);

        $auditLog->load(['user', 'project', 'task']);

        return response()->json([
            'data' => new AuditLogResource($auditLog),
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        $hasAdmin = $user->projects()
            ->where('enabled', true)
            ->get()
            ->contains(fn ($project) => $user->hasPermission('admin.global_config', $project));

        if (! $hasAdmin) {
            abort(403, 'Audit log access is restricted to administrators.');
        }
    }
}
```

**Step 4: Add routes to api.php**

Add inside the `Route::middleware('auth')` group in `routes/api.php`, after the dead letter queue routes (around line 177):

```php
        // Audit logs (T103) — admin-only via RBAC
        Route::get('/audit-logs', [AuditLogController::class, 'index'])
            ->name('api.audit-logs.index');
        Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])
            ->name('api.audit-logs.show');
```

Also add the import at the top of `routes/api.php`:

```php
use App\Http\Controllers\Api\AuditLogController;
```

**Step 5: Run tests to verify they pass**

```bash
php artisan test tests/Feature/Http/Controllers/Api/AuditLogControllerTest.php
```

Expected: All 10 tests PASS.

**Step 6: Commit**

```bash
git add app/Http/Controllers/Api/AuditLogController.php tests/Feature/Http/Controllers/Api/AuditLogControllerTest.php routes/api.php app/Http/Resources/AuditLogResource.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T103.4: Add AuditLogController with admin-only API and cursor pagination

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Hook audit logging into AuthController (login/logout)

**Files:**
- Modify: `app/Http/Controllers/AuthController.php`

**Step 1: Add audit logging to login (callback) and logout**

In `AuthController::callback()`, after `auth()->login($user, remember: true)`, add:

```php
        try {
            app(AuditLogService::class)->logAuthEvent(
                userId: $user->id,
                action: 'login',
                ipAddress: request()->ip(),
                userAgent: request()->userAgent(),
            );
        } catch (\Throwable) {
            // Audit logging should never break auth flow
        }
```

In `AuthController::logout()`, before `auth()->logout()`, add:

```php
        $userId = auth()->id();
        if ($userId) {
            try {
                app(AuditLogService::class)->logAuthEvent(
                    userId: $userId,
                    action: 'logout',
                    ipAddress: $request->ip(),
                    userAgent: $request->userAgent(),
                );
            } catch (\Throwable) {
                // Audit logging should never break auth flow
            }
        }
```

Add the import at the top:

```php
use App\Services\AuditLogService;
```

**Step 2: Commit**

```bash
git add app/Http/Controllers/AuthController.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T103.5: Hook audit logging into AuthController for login/logout events

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Hook audit logging into AdminSettingsController (config changes)

**Files:**
- Modify: `app/Http/Controllers/Api/AdminSettingsController.php`

**Step 1: Add audit logging to the update method**

In `AdminSettingsController::update()`, capture old values before updating and log each change. Modify the `foreach` loop:

```php
        foreach ($request->validated()['settings'] as $item) {
            $key = $item['key'];
            $value = $item['value'];
            $type = $item['type'] ?? 'string';

            $oldSetting = GlobalSetting::where('key', $key)->first();
            $oldValue = $oldSetting?->value;

            if ($key === 'bot_pat_created_at') {
                GlobalSetting::updateOrCreate(
                    ['key' => $key],
                    ['bot_pat_created_at' => $value, 'value' => $value, 'type' => 'string']
                );
            } else {
                GlobalSetting::set($key, $value, $type);
            }

            if ($oldValue !== $value) {
                try {
                    app(AuditLogService::class)->logConfigurationChange(
                        userId: $request->user()->id,
                        key: $key,
                        oldValue: $oldValue,
                        newValue: $value,
                    );
                } catch (\Throwable) {
                    // Audit logging should never break settings update
                }
            }
        }
```

Add the import at the top:

```php
use App\Services\AuditLogService;
```

**Step 2: Commit**

```bash
git add app/Http/Controllers/Api/AdminSettingsController.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T103.6: Hook audit logging into AdminSettingsController for config changes

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Hook audit logging into AdminProjectConfigController (per-project config changes)

**Files:**
- Modify: `app/Http/Controllers/Api/AdminProjectConfigController.php`
- Reference: Read the file first, then add audit logging to the `update` method following the same pattern as Task 6.

**Step 1: Read the file and add audit logging**

In the `update()` method, capture old config values before calling `ProjectConfigService::bulkSet()`, then log each changed key.

Add after the `bulkSet()` call:

```php
        foreach ($changes as $key => ['old' => $old, 'new' => $new]) {
            try {
                app(AuditLogService::class)->logConfigurationChange(
                    userId: $request->user()->id,
                    key: $key,
                    oldValue: $old,
                    newValue: $new,
                    projectId: $project->id,
                );
            } catch (\Throwable) {
                // Audit logging should never break config update
            }
        }
```

The exact implementation depends on the controller's current code — read it first, capture old values before `bulkSet()`, compute the diff, then log.

Add the import:

```php
use App\Services\AuditLogService;
```

**Step 2: Commit**

```bash
git add app/Http/Controllers/Api/AdminProjectConfigController.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T103.7: Hook audit logging into AdminProjectConfigController

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Hook audit logging into webhook controller

**Files:**
- Modify: `app/Http/Controllers/WebhookController.php` (or wherever the webhook entry point is)
- Reference: Check `app/Http/Controllers/WebhookController.php` for the entry point. Add a call to `AuditLogService::logWebhookReceived()` after the webhook is validated, before event routing.

**Step 1: Read the webhook controller and add audit logging**

After the webhook is validated and the project is identified, log:

```php
        try {
            app(AuditLogService::class)->logWebhookReceived(
                projectId: $project->id,
                eventType: $eventType,
                relevantIds: $relevantIds, // e.g., ['mr_iid' => 42, 'action' => 'open']
            );
        } catch (\Throwable) {
            // Audit logging should never break webhook processing
        }
```

The exact placement and variable names depend on the current webhook controller code — read it first.

**Step 2: Commit**

```bash
git add app/Http/Controllers/WebhookController.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T103.8: Hook audit logging into WebhookController

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Add T103 verification checks to verify_m5.py

**Files:**
- Modify: `verify/verify_m5.py`

**Step 1: Add T103 section before the Summary section (line 1404)**

Insert before `# Summary`:

```python
# ============================================================
#  T103: Audit logging
# ============================================================
section("T103: Audit Logging")

# Migration
checker.check(
    "audit_logs migration exists",
    file_exists("database/migrations/2024_01_01_000011_create_audit_logs_table.php"),
)

# Model
checker.check(
    "AuditLog model exists",
    file_exists("app/Models/AuditLog.php"),
)
checker.check(
    "AuditLog has event_type field",
    file_contains("app/Models/AuditLog.php", "'event_type'"),
)
checker.check(
    "AuditLog has properties cast",
    file_contains("app/Models/AuditLog.php", "'properties' => 'array'"),
)

# Factory
checker.check(
    "AuditLog factory exists",
    file_exists("database/factories/AuditLogFactory.php"),
)
checker.check(
    "AuditLog factory has conversationTurn state",
    file_contains("database/factories/AuditLogFactory.php", "conversationTurn"),
)
checker.check(
    "AuditLog factory has taskExecution state",
    file_contains("database/factories/AuditLogFactory.php", "taskExecution"),
)

# Service
checker.check(
    "AuditLogService exists",
    file_exists("app/Services/AuditLogService.php"),
)
checker.check(
    "AuditLogService has logConversationTurn",
    file_contains("app/Services/AuditLogService.php", "logConversationTurn"),
)
checker.check(
    "AuditLogService has logTaskExecution",
    file_contains("app/Services/AuditLogService.php", "logTaskExecution"),
)
checker.check(
    "AuditLogService has logConfigurationChange",
    file_contains("app/Services/AuditLogService.php", "logConfigurationChange"),
)
checker.check(
    "AuditLogService has logWebhookReceived",
    file_contains("app/Services/AuditLogService.php", "logWebhookReceived"),
)
checker.check(
    "AuditLogService has logAuthEvent",
    file_contains("app/Services/AuditLogService.php", "logAuthEvent"),
)
checker.check(
    "AuditLogService has logActionDispatch",
    file_contains("app/Services/AuditLogService.php", "logActionDispatch"),
)

# Controller
checker.check(
    "AuditLogController exists",
    file_exists("app/Http/Controllers/Api/AuditLogController.php"),
)
checker.check(
    "AuditLogController has index method",
    file_contains("app/Http/Controllers/Api/AuditLogController.php", "function index"),
)
checker.check(
    "AuditLogController has show method",
    file_contains("app/Http/Controllers/Api/AuditLogController.php", "function show"),
)
checker.check(
    "AuditLogController has authorizeAdmin",
    file_contains("app/Http/Controllers/Api/AuditLogController.php", "authorizeAdmin"),
)

# Resource
checker.check(
    "AuditLogResource exists",
    file_exists("app/Http/Resources/AuditLogResource.php"),
)

# Routes
checker.check(
    "Audit log routes registered",
    file_contains("routes/api.php", "audit-logs"),
)

# Integration hooks
checker.check(
    "AuthController has audit logging",
    file_contains("app/Http/Controllers/AuthController.php", "AuditLogService"),
)
checker.check(
    "AdminSettingsController has audit logging",
    file_contains("app/Http/Controllers/Api/AdminSettingsController.php", "AuditLogService"),
)

# Tests
checker.check(
    "AuditLogService test exists",
    file_exists("tests/Feature/Services/AuditLogServiceTest.php"),
)
checker.check(
    "AuditLogController test exists",
    file_exists("tests/Feature/Http/Controllers/Api/AuditLogControllerTest.php"),
)
```

**Step 2: Run verification**

```bash
python3 verify/verify_m5.py
```

Expected: T103 section passes.

**Step 3: Commit**

```bash
git add verify/verify_m5.py
git commit --no-gpg-sign -m "$(cat <<'EOF'
T103.9: Add T103 verification checks to verify_m5.py

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: Run full verification and mark task complete

**Step 1: Run full test suite**

```bash
php artisan test --parallel
```

Expected: All tests pass (no regressions).

**Step 2: Run milestone verification**

```bash
python3 verify/verify_m5.py
```

Expected: All checks pass.

**Step 3: Update progress.md**

- Check `[x]` for T103
- Bold T104 as the next task
- Update milestone count to 16/18
- Update summary: Tasks Complete: 104 / 116

**Step 4: Clear handoff.md**

Reset to empty template.

**Step 5: Commit**

```bash
git add progress.md handoff.md
git commit --no-gpg-sign -m "$(cat <<'EOF'
T103: Complete audit logging — mark task done

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

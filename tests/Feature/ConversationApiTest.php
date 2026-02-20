<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// ─── Setup ─────────────────────────────────────────────────────

beforeEach(function (): void {
    // The SDK migration creates agent_conversations and agent_conversation_messages,
    // but our custom columns (project_id, archived_at) are added by PostgreSQL-only
    // migrations. Create the tables with all columns if they
    // don't exist yet (SDK migration may have a 2026 timestamp sorting after ours).
    if (! Schema::hasTable('agent_conversations')) {
        Schema::create('agent_conversations', function ($table): void {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('title');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });
    } elseif (! Schema::hasColumn('agent_conversations', 'project_id')) {
        Schema::table('agent_conversations', function ($table): void {
            $table->unsignedBigInteger('project_id')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
    }

    if (! Schema::hasTable('agent_conversation_messages')) {
        Schema::create('agent_conversation_messages', function ($table): void {
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

// ─── Helpers ─────────────────────────────────────────────────────

/**
 * Create a user with chat.access permission on a project.
 */
function userWithChatAccess(Project $project): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['project_id' => $project->id]);
    $perm = Permission::firstOrCreate(['name' => 'chat.access'], ['description' => 'Chat access']);
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    // Also make user a project member (for policy checks)
    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    return $user;
}

/**
 * Create a user who is a project member but has no chat.access permission.
 */
function userWithoutChatAccess(Project $project): User
{
    $user = User::factory()->create();

    // Member of project but no chat.access permission
    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    return $user;
}

// ─── POST /api/v1/conversations ─────────────────────────────────

it('creates a conversation when user has chat.access', function (): void {
    $project = Project::factory()->create();
    $user = userWithChatAccess($project);

    $this->actingAs($user)
        ->postJson('/api/v1/conversations', [
            'project_id' => $project->id,
            'title' => 'Test conversation',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.title', 'Test conversation')
        ->assertJsonPath('data.project_id', $project->id)
        ->assertJsonPath('data.user_id', $user->id);
});

it('creates a conversation with default title when none provided', function (): void {
    $project = Project::factory()->create();
    $user = userWithChatAccess($project);

    $this->actingAs($user)
        ->postJson('/api/v1/conversations', [
            'project_id' => $project->id,
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.title', 'New conversation');
});

it('returns 403 when user lacks chat.access on create', function (): void {
    $project = Project::factory()->create();
    $user = userWithoutChatAccess($project);

    $this->actingAs($user)
        ->postJson('/api/v1/conversations', [
            'project_id' => $project->id,
        ])
        ->assertForbidden();
});

it('returns 422 when project_id is missing on create', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/conversations', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('project_id');
});

it('returns 422 when project_id does not exist on create', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/conversations', [
            'project_id' => 99999,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('project_id');
});

// ─── GET /api/v1/conversations ─────────────────────────────────

it('lists conversations accessible by the user', function (): void {
    $project = Project::factory()->create();
    $user = userWithChatAccess($project);

    Conversation::factory()->count(3)->forUser($user)->forProject($project)->create();

    // Create a conversation in another project — should not appear
    $otherProject = Project::factory()->create();
    Conversation::factory()->forProject($otherProject)->create();

    $this->actingAs($user)
        ->getJson('/api/v1/conversations')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('filters conversations by project_id', function (): void {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $user = userWithChatAccess($projectA);

    // Also add user to projectB
    $roleB = Role::factory()->create(['project_id' => $projectB->id]);
    $user->assignRole($roleB, $projectB);
    $user->projects()->attach($projectB->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    Conversation::factory()->count(2)->forUser($user)->forProject($projectA)->create();
    Conversation::factory()->count(1)->forUser($user)->forProject($projectB)->create();

    $this->actingAs($user)
        ->getJson('/api/v1/conversations?project_id='.$projectA->id)
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filters conversations by archived status', function (): void {
    $project = Project::factory()->create();
    $user = userWithChatAccess($project);

    Conversation::factory()->count(2)->forUser($user)->forProject($project)->create();
    Conversation::factory()->count(1)->forUser($user)->forProject($project)->archived()->create();

    // Default (not archived)
    $this->actingAs($user)
        ->getJson('/api/v1/conversations')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // Archived only
    $this->actingAs($user)
        ->getJson('/api/v1/conversations?archived=1')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('searches conversations by keyword in title', function (): void {
    $project = Project::factory()->create();
    $user = userWithChatAccess($project);

    Conversation::factory()->forUser($user)->forProject($project)->create(['title' => 'Deploy pipeline config']);
    Conversation::factory()->forUser($user)->forProject($project)->create(['title' => 'Auth bug investigation']);
    Conversation::factory()->forUser($user)->forProject($project)->create(['title' => 'Pipeline optimization']);

    $this->actingAs($user)
        ->getJson('/api/v1/conversations?search=pipeline')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('returns cursor-paginated results', function (): void {
    $project = Project::factory()->create();
    $user = userWithChatAccess($project);

    Conversation::factory()->count(5)->forUser($user)->forProject($project)->create();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/conversations?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // Response should have cursor pagination metadata
    $json = $response->json();
    expect($json)->toHaveKey('meta');
    expect($json['meta'])->toHaveKey('per_page', 2);
});

it('includes last_message preview in list', function (): void {
    $project = Project::factory()->create();
    $user = userWithChatAccess($project);

    $conversation = Conversation::factory()->forUser($user)->forProject($project)->create();
    Message::factory()->forConversation($conversation)->create([
        'content' => 'This is a test message',
        'role' => 'user',
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/conversations')
        ->assertOk()
        ->assertJsonPath('data.0.last_message.content', 'This is a test message')
        ->assertJsonPath('data.0.last_message.role', 'user');
});

// ─── GET /api/v1/conversations/{conversation} ────────────────────

it('loads a conversation with messages', function (): void {
    $project = Project::factory()->create();
    $user = userWithChatAccess($project);

    $conversation = Conversation::factory()->forUser($user)->forProject($project)->create();
    Message::factory()->count(3)->forConversation($conversation)->create([
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/conversations/{$conversation->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $conversation->id)
        ->assertJsonCount(3, 'data.messages');
});

it('returns 403 when user cannot view conversation', function (): void {
    $project = Project::factory()->create();
    $otherUser = User::factory()->create();

    $conversation = Conversation::factory()->forProject($project)->create();

    // otherUser has no access to this project
    $this->actingAs($otherUser)
        ->getJson("/api/v1/conversations/{$conversation->id}")
        ->assertForbidden();
});

it('returns 404 for nonexistent conversation', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/conversations/nonexistent-uuid')
        ->assertNotFound();
});

// ─── POST /api/v1/conversations/{conversation}/messages ────────

it('sends a user message to a conversation', function (): void {
    $project = Project::factory()->create();
    $user = userWithChatAccess($project);

    $conversation = Conversation::factory()->forUser($user)->forProject($project)->create();

    $this->actingAs($user)
        ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Hello, can you review this code?',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.role', 'user')
        ->assertJsonPath('data.content', 'Hello, can you review this code?')
        ->assertJsonPath('data.user_id', $user->id);

    // Verify the message was persisted
    expect($conversation->messages()->count())->toBe(1);
});

it('returns 403 when user cannot send message to conversation', function (): void {
    $project = Project::factory()->create();
    $otherUser = User::factory()->create();

    $conversation = Conversation::factory()->forProject($project)->create();

    $this->actingAs($otherUser)
        ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => 'Unauthorized message',
        ])
        ->assertForbidden();
});

it('returns 422 when message content is missing', function (): void {
    $project = Project::factory()->create();
    $user = userWithChatAccess($project);

    $conversation = Conversation::factory()->forUser($user)->forProject($project)->create();

    $this->actingAs($user)
        ->postJson("/api/v1/conversations/{$conversation->id}/messages", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('content');
});

it('returns 422 when message content exceeds max length', function (): void {
    $project = Project::factory()->create();
    $user = userWithChatAccess($project);

    $conversation = Conversation::factory()->forUser($user)->forProject($project)->create();

    $this->actingAs($user)
        ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
            'content' => str_repeat('a', 50001),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('content');
});

// ─── PATCH /api/v1/conversations/{conversation}/archive ────────

it('archives an active conversation', function (): void {
    $project = Project::factory()->create();
    $user = userWithChatAccess($project);

    $conversation = Conversation::factory()->forUser($user)->forProject($project)->create();
    expect($conversation->isArchived())->toBeFalse();

    $this->actingAs($user)
        ->patchJson("/api/v1/conversations/{$conversation->id}/archive")
        ->assertOk()
        ->assertJsonPath('data.id', $conversation->id);

    $conversation->refresh();
    expect($conversation->isArchived())->toBeTrue();
});

it('unarchives an archived conversation', function (): void {
    $project = Project::factory()->create();
    $user = userWithChatAccess($project);

    $conversation = Conversation::factory()->forUser($user)->forProject($project)->archived()->create();
    expect($conversation->isArchived())->toBeTrue();

    $this->actingAs($user)
        ->patchJson("/api/v1/conversations/{$conversation->id}/archive")
        ->assertOk();

    $conversation->refresh();
    expect($conversation->isArchived())->toBeFalse();
});

it('returns 403 when user cannot archive conversation', function (): void {
    $project = Project::factory()->create();
    $otherUser = User::factory()->create();

    $conversation = Conversation::factory()->forProject($project)->create();

    $this->actingAs($otherUser)
        ->patchJson("/api/v1/conversations/{$conversation->id}/archive")
        ->assertForbidden();
});

// ─── Authentication ────────────────────────────────────────────

it('returns 302 redirect for unauthenticated requests to list', function (): void {
    $this->get('/api/v1/conversations')
        ->assertRedirect();
});

it('returns 302 redirect for unauthenticated requests to create', function (): void {
    $this->post('/api/v1/conversations')
        ->assertRedirect();
});

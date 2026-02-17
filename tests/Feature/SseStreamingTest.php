<?php

use App\Agents\VunnixAgent;
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
    // Ensure agent_conversations and agent_conversation_messages tables exist with all
    // custom columns. The SDK migration may have a 2026 timestamp that sorts after our
    // 2024 custom migrations, and PostgreSQL-only migrations skip on SQLite.
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

function sseUser(Project $project): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['project_id' => $project->id]);
    $perm = Permission::firstOrCreate(['name' => 'chat.access'], ['description' => 'Chat access']);
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    return $user;
}

// ─── SSE Streaming Endpoint ─────────────────────────────────────

it('streams an AI response as SSE for a valid message', function (): void {
    VunnixAgent::fake(['This is a streamed response from the AI assistant']);

    $project = Project::factory()->create();
    $user = sseUser($project);
    $conversation = Conversation::factory()->forUser($user)->forProject($project)->create();

    $response = $this->actingAs($user)
        ->post("/api/v1/conversations/{$conversation->id}/stream", [
            'content' => 'Hello, can you help me?',
        ]);

    $response->assertOk();
    $contentType = $response->headers->get('Content-Type');
    expect($contentType)->toStartWith('text/event-stream');

    $content = $response->streamedContent();

    // Should contain text_delta events with the response words
    expect($content)->toContain('"type":"text_delta"');
    expect($content)->toContain('"type":"stream_start"');
    expect($content)->toContain('"type":"stream_end"');
    expect($content)->toContain('data: [DONE]');

    // Response text should be present across deltas
    expect($content)->toContain('streamed');
    expect($content)->toContain('response');
});

it('persists the user message via SDK middleware after streaming', function (): void {
    VunnixAgent::fake(['AI response text']);

    $project = Project::factory()->create();
    $user = sseUser($project);
    $conversation = Conversation::factory()->forUser($user)->forProject($project)->create();

    $response = $this->actingAs($user)
        ->post("/api/v1/conversations/{$conversation->id}/stream", [
            'content' => 'Review my authentication code',
        ]);

    $response->assertOk();

    // Consume the stream to trigger SDK middleware callbacks
    $response->streamedContent();

    // User message should be persisted by the SDK's RememberConversation middleware
    $userMessage = $conversation->messages()->where('role', 'user')->first();
    expect($userMessage)->not->toBeNull();
    expect($userMessage->content)->toBe('Review my authentication code');
});

it('returns 403 when user is not a project member', function (): void {
    VunnixAgent::fake(['Should not reach this']);

    $project = Project::factory()->create();
    $outsideUser = User::factory()->create();
    $conversation = Conversation::factory()->forProject($project)->create();

    $this->actingAs($outsideUser)
        ->postJson("/api/v1/conversations/{$conversation->id}/stream", [
            'content' => 'Unauthorized message',
        ])
        ->assertForbidden();

    VunnixAgent::assertNeverPrompted();
});

it('returns 422 when content is missing', function (): void {
    $project = Project::factory()->create();
    $user = sseUser($project);
    $conversation = Conversation::factory()->forUser($user)->forProject($project)->create();

    $this->actingAs($user)
        ->postJson("/api/v1/conversations/{$conversation->id}/stream", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('content');
});

it('returns 422 when content exceeds max length', function (): void {
    $project = Project::factory()->create();
    $user = sseUser($project);
    $conversation = Conversation::factory()->forUser($user)->forProject($project)->create();

    $this->actingAs($user)
        ->postJson("/api/v1/conversations/{$conversation->id}/stream", [
            'content' => str_repeat('a', 50001),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('content');
});

it('returns redirect for unauthenticated requests', function (): void {
    $project = Project::factory()->create();
    $conversation = Conversation::factory()->forProject($project)->create();

    $this->post("/api/v1/conversations/{$conversation->id}/stream", [
        'content' => 'Unauthenticated',
    ])->assertRedirect();
});

it('returns 404 for nonexistent conversation', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/conversations/nonexistent-uuid/stream', [
            'content' => 'Hello',
        ])
        ->assertNotFound();
});

it('includes all SSE event types in the stream', function (): void {
    VunnixAgent::fake(['Complete response with multiple words for delta events']);

    $project = Project::factory()->create();
    $user = sseUser($project);
    $conversation = Conversation::factory()->forUser($user)->forProject($project)->create();

    $response = $this->actingAs($user)
        ->post("/api/v1/conversations/{$conversation->id}/stream", [
            'content' => 'Test all event types',
        ]);

    $content = $response->streamedContent();

    // Verify the complete SSE event lifecycle
    expect($content)->toContain('"type":"stream_start"');
    expect($content)->toContain('"type":"text_start"');
    expect($content)->toContain('"type":"text_delta"');
    expect($content)->toContain('"type":"text_end"');
    expect($content)->toContain('"type":"stream_end"');
    expect($content)->toContain('data: [DONE]');

    // Each event should be a valid SSE data line
    $lines = array_filter(explode("\n", $content), fn ($line) => str_starts_with($line, 'data: '));
    expect(count($lines))->toBeGreaterThanOrEqual(6); // start, text_start, at least 1 delta, text_end, stream_end, [DONE]

    // All data lines except [DONE] should be valid JSON
    foreach ($lines as $line) {
        $payload = substr($line, 6); // Remove 'data: ' prefix
        if ($payload === '[DONE]') {
            continue;
        }
        $decoded = json_decode($payload, true);
        expect($decoded)->toBeArray();
        expect($decoded)->toHaveKey('type');
    }
});

it('sends the user prompt to the agent', function (): void {
    VunnixAgent::fake(['Acknowledged']);

    $project = Project::factory()->create();
    $user = sseUser($project);
    $conversation = Conversation::factory()->forUser($user)->forProject($project)->create();

    $response = $this->actingAs($user)
        ->post("/api/v1/conversations/{$conversation->id}/stream", [
            'content' => 'Show me the auth module',
        ]);

    // Consume stream to trigger the agent
    $response->streamedContent();

    VunnixAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Show me the auth module'));
});

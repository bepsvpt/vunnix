<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
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

// ─── Creation ──────────────────────────────────────────────────

it('creates a conversation with a primary project', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $conversation = Conversation::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'title' => 'Test conversation',
    ]);

    expect($conversation->id)->toBeString()->not->toBeEmpty();
    expect($conversation->user_id)->toBe($user->id);
    expect($conversation->project_id)->toBe($project->id);
    expect($conversation->title)->toBe('Test conversation');
});

it('generates a UUID7 on creation', function (): void {
    $conversation = Conversation::factory()->create();

    expect($conversation->id)->toBeString();
    expect(strlen($conversation->id))->toBe(36);
});

// ─── Relationships ─────────────────────────────────────────────

it('belongs to a user', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->forUser($user)->create();

    expect($conversation->user->id)->toBe($user->id);
});

it('belongs to a project', function (): void {
    $project = Project::factory()->create();
    $conversation = Conversation::factory()->forProject($project)->create();

    expect($conversation->project->id)->toBe($project->id);
});

it('has many messages', function (): void {
    $conversation = Conversation::factory()->create();
    Message::factory()->count(3)->forConversation($conversation)->create();

    expect($conversation->messages)->toHaveCount(3);
});

it('loads latest message', function (): void {
    $conversation = Conversation::factory()->create();

    $firstMessage = Message::factory()->forConversation($conversation)->create([
        'content' => 'First message',
        'created_at' => now()->subMinutes(2),
    ]);

    $latestMessage = Message::factory()->forConversation($conversation)->create([
        'content' => 'Latest message',
        'created_at' => now(),
    ]);

    $conversation->load('latestMessage');
    expect($conversation->latestMessage->content)->toBe('Latest message');
});

// ─── Scopes ────────────────────────────────────────────────────

it('notArchived scope excludes archived conversations', function (): void {
    Conversation::factory()->count(2)->create();
    Conversation::factory()->count(1)->archived()->create();

    $results = Conversation::notArchived()->get();
    expect($results)->toHaveCount(2);
});

it('archived scope includes only archived conversations', function (): void {
    Conversation::factory()->count(2)->create();
    Conversation::factory()->count(1)->archived()->create();

    $results = Conversation::archived()->get();
    expect($results)->toHaveCount(1);
});

it('accessibleBy scope returns only conversations for user projects', function (): void {
    $user = User::factory()->create();
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();

    // User is member of projectA only
    $user->projects()->attach($projectA->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    Conversation::factory()->count(2)->forProject($projectA)->create();
    Conversation::factory()->count(3)->forProject($projectB)->create();

    $results = Conversation::accessibleBy($user)->get();
    expect($results)->toHaveCount(2);
});

it('forProject scope filters by project', function (): void {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();

    Conversation::factory()->count(2)->forProject($projectA)->create();
    Conversation::factory()->count(1)->forProject($projectB)->create();

    $results = Conversation::forProject($projectA->id)->get();
    expect($results)->toHaveCount(2);
});

// ─── allProjectIds ──────────────────────────────────────────────

it('allProjectIds includes primary project not in pivot', function (): void {
    $primaryProject = Project::factory()->create();
    $pivotProject = Project::factory()->create();

    $conversation = Conversation::factory()->forProject($primaryProject)->create();
    $conversation->projects()->attach($pivotProject->id);

    $ids = $conversation->allProjectIds();

    expect($ids)->toContain($primaryProject->id);
    expect($ids)->toContain($pivotProject->id);
    expect($ids)->toHaveCount(2);
});

it('allProjectIds returns no duplicates when primary project is also in pivot', function (): void {
    $project = Project::factory()->create();
    $anotherProject = Project::factory()->create();

    $conversation = Conversation::factory()->forProject($project)->create();
    // Add the primary project AND another project to the pivot
    $conversation->projects()->attach([$project->id, $anotherProject->id]);

    $ids = $conversation->allProjectIds();

    // Should contain both projects but no duplicates of the primary
    expect($ids)->toContain($project->id);
    expect($ids)->toContain($anotherProject->id);
    expect($ids)->toHaveCount(2);
});

it('allProjectIds returns only pivot projects when no primary project', function (): void {
    $pivotProjectA = Project::factory()->create();
    $pivotProjectB = Project::factory()->create();

    $conversation = Conversation::factory()->create(['project_id' => null]);
    $conversation->projects()->attach([$pivotProjectA->id, $pivotProjectB->id]);

    $ids = $conversation->allProjectIds();

    expect($ids)->toContain($pivotProjectA->id);
    expect($ids)->toContain($pivotProjectB->id);
    expect($ids)->toHaveCount(2);
});

it('allProjectIds returns empty array when no projects at all', function (): void {
    $conversation = Conversation::factory()->create(['project_id' => null]);

    $ids = $conversation->allProjectIds();

    expect($ids)->toBeArray();
    expect($ids)->toBeEmpty();
});

// ─── Archive ───────────────────────────────────────────────────

it('isArchived returns true when archived_at is set', function (): void {
    $conversation = Conversation::factory()->archived()->create();
    expect($conversation->isArchived())->toBeTrue();
});

it('isArchived returns false when archived_at is null', function (): void {
    $conversation = Conversation::factory()->create();
    expect($conversation->isArchived())->toBeFalse();
});

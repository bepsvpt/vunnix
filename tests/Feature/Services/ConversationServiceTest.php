<?php

use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

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

    if (! Schema::hasTable('conversation_projects')) {
        Schema::create('conversation_projects', function ($table): void {
            $table->id();
            $table->string('conversation_id');
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['conversation_id', 'project_id']);
        });
    }
});

// ─── addProject: user without project access ─────────────────────

it('aborts with 403 when user does not have access to the project', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();

    // User is a member of project but NOT otherProject
    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    $conversation = Conversation::factory()->forUser($user)->forProject($project)->create();

    $service = app(ConversationService::class);
    $service->addProject($conversation, $user, $otherProject->id);
})->throws(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'You do not have access to this project.');

// ─── addProject: project_id same as primary ──────────────────────

it('returns conversation unchanged when adding the primary project', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    $conversation = Conversation::factory()->forUser($user)->forProject($project)->create();

    $service = app(ConversationService::class);
    $result = $service->addProject($conversation, $user, $project->id);

    // Should return the same conversation without attaching to pivot
    expect($result->id)->toBe($conversation->id);
    expect($conversation->projects()->count())->toBe(0);
});

// ─── addProject: project already in pivot table ──────────────────

it('returns conversation unchanged when project is already in pivot', function (): void {
    $user = User::factory()->create();
    $primaryProject = Project::factory()->create();
    $additionalProject = Project::factory()->create();

    $user->projects()->attach($primaryProject->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);
    $user->projects()->attach($additionalProject->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    $conversation = Conversation::factory()->forUser($user)->forProject($primaryProject)->create();

    // Pre-attach the project to conversation pivot
    $conversation->projects()->attach($additionalProject->id);
    expect($conversation->projects()->count())->toBe(1);

    $service = app(ConversationService::class);
    $result = $service->addProject($conversation, $user, $additionalProject->id);

    // Should still be just 1 project in the pivot (no duplicate)
    expect($result->id)->toBe($conversation->id);
    expect($conversation->projects()->count())->toBe(1);
});

// ─── addProject: new project attached successfully ───────────────

it('attaches a new project and loads relationship', function (): void {
    $user = User::factory()->create();
    $primaryProject = Project::factory()->create();
    $newProject = Project::factory()->create();

    $user->projects()->attach($primaryProject->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);
    $user->projects()->attach($newProject->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    $conversation = Conversation::factory()->forUser($user)->forProject($primaryProject)->create();

    $service = app(ConversationService::class);
    $result = $service->addProject($conversation, $user, $newProject->id);

    // Project should now be in the pivot
    expect($conversation->projects()->count())->toBe(1);
    expect($result->relationLoaded('projects'))->toBeTrue();
    expect($result->projects->pluck('id')->toArray())->toContain($newProject->id);
});

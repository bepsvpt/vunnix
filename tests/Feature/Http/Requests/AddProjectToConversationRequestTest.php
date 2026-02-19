<?php

use App\Models\Conversation;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
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

/**
 * Create a user who is a member of the given project.
 */
function addProjectTestUser(Project $project): User
{
    $user = User::factory()->create();
    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    return $user;
}

function grantChatAccess(User $user, Project $project): void
{
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'developer']);
    $permission = Permission::firstOrCreate(
        ['name' => 'chat.access'],
        ['description' => 'Can access chat', 'group' => 'chat']
    );
    $role->permissions()->attach($permission);
    $user->assignRole($role, $project);
}

it('rejects request when project_id is missing', function (): void {
    $project = Project::factory()->create();
    $user = addProjectTestUser($project);
    $conversation = Conversation::factory()->forProject($project)->forUser($user)->create();

    $response = $this->actingAs($user)
        ->postJson("/api/v1/conversations/{$conversation->id}/projects", []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['project_id']);
});

it('rejects request when project_id is not an integer', function (): void {
    $project = Project::factory()->create();
    $user = addProjectTestUser($project);
    $conversation = Conversation::factory()->forProject($project)->forUser($user)->create();

    $response = $this->actingAs($user)
        ->postJson("/api/v1/conversations/{$conversation->id}/projects", [
            'project_id' => 'not-an-integer',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['project_id']);
});

it('rejects request when project_id does not exist in projects table', function (): void {
    $project = Project::factory()->create();
    $user = addProjectTestUser($project);
    $conversation = Conversation::factory()->forProject($project)->forUser($user)->create();

    $response = $this->actingAs($user)
        ->postJson("/api/v1/conversations/{$conversation->id}/projects", [
            'project_id' => 999999,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['project_id']);
});

it('passes validation with a valid existing project_id', function (): void {
    $project = Project::factory()->create();
    $targetProject = Project::factory()->create();
    $user = addProjectTestUser($project);
    $conversation = Conversation::factory()->forProject($project)->forUser($user)->create();

    $response = $this->actingAs($user)
        ->postJson("/api/v1/conversations/{$conversation->id}/projects", [
            'project_id' => $targetProject->id,
        ]);

    // The request should pass validation (no 422 with project_id errors).
    // It may fail at authorization (403) or succeed (200) depending on
    // policy setup, but the validation layer itself should not reject it.
    expect($response->status())->not->toBe(422);
});

it('adds a project when user is authorized and belongs to the target project', function (): void {
    $primaryProject = Project::factory()->create();
    $targetProject = Project::factory()->create();
    $user = addProjectTestUser($primaryProject);
    $user->projects()->attach($targetProject->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);
    grantChatAccess($user, $primaryProject);

    $conversation = Conversation::factory()
        ->forProject($primaryProject)
        ->forUser($user)
        ->create();

    $this->actingAs($user)
        ->postJson("/api/v1/conversations/{$conversation->id}/projects", [
            'project_id' => $targetProject->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $conversation->id);

    expect($conversation->fresh()->projects()->where('projects.id', $targetProject->id)->exists())
        ->toBeTrue();
});

<?php

use App\Models\Conversation;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Policies\ConversationPolicy;
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

function grantPrimaryChatPermission(User $user, Project $project): void
{
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'developer']);
    $permission = Permission::firstOrCreate(
        ['name' => 'chat.access'],
        ['description' => 'Can access chat', 'group' => 'chat']
    );
    $role->permissions()->attach($permission);
    $user->assignRole($role, $project);
}

it('allows viewing a conversation through additional project membership', function (): void {
    $primaryProject = Project::factory()->create();
    $additionalProject = Project::factory()->create();
    $user = User::factory()->create();

    grantPrimaryChatPermission($user, $primaryProject);
    $user->projects()->attach($additionalProject->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    $conversation = Conversation::factory()
        ->forProject($primaryProject)
        ->create();
    $conversation->projects()->attach($additionalProject->id);

    $policy = new ConversationPolicy;

    expect($policy->view($user, $conversation))->toBeTrue();
});

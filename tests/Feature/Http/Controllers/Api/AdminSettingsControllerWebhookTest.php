<?php

use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake([
        'hooks.slack.com/*' => Http::response('ok', 200),
    ]);

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

function createWebhookAdmin(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'admin']);
    $perm = Permission::firstOrCreate(
        ['name' => 'admin.global_config'],
        ['description' => 'Can edit global settings', 'group' => 'admin']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

it('sends test webhook successfully', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createWebhookAdmin($project);

    $response = $this->actingAs($user)->postJson('/api/v1/admin/settings/test-webhook', [
        'webhook_url' => 'https://hooks.slack.com/services/T/B/x',
        'platform' => 'slack',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return $request->url() === 'https://hooks.slack.com/services/T/B/x'
            && str_contains($request['text'], 'Vunnix webhook test');
    });
});

it('returns failure for bad webhook URL', function (): void {
    Http::fake([
        '8.8.8.8/*' => Http::response('not found', 404),
    ]);

    $project = Project::factory()->enabled()->create();
    $user = createWebhookAdmin($project);

    $response = $this->actingAs($user)->postJson('/api/v1/admin/settings/test-webhook', [
        'webhook_url' => 'https://8.8.8.8/fail',
        'platform' => 'slack',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', false);
});

it('validates webhook_url is required', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createWebhookAdmin($project);

    $response = $this->actingAs($user)->postJson('/api/v1/admin/settings/test-webhook', [
        'platform' => 'slack',
    ]);

    $response->assertUnprocessable();
});

it('validates platform must be valid', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = createWebhookAdmin($project);

    $response = $this->actingAs($user)->postJson('/api/v1/admin/settings/test-webhook', [
        'webhook_url' => 'https://hooks.slack.com/x',
        'platform' => 'invalid_platform',
    ]);

    $response->assertUnprocessable();
});

it('returns 403 for non-admin', function (): void {
    $project = Project::factory()->enabled()->create();
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $response = $this->actingAs($user)->postJson('/api/v1/admin/settings/test-webhook', [
        'webhook_url' => 'https://hooks.slack.com/x',
        'platform' => 'slack',
    ]);

    $response->assertForbidden();
});

<?php

use App\Models\ApiKey;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createApiKeyAdminUser(): User
{
    $user = User::factory()->create();
    $project = Project::factory()->create(['enabled' => true]);
    $project->users()->attach($user->id, ['gitlab_access_level' => 40, 'synced_at' => now()]);

    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'admin']);
    $perm = Permission::firstOrCreate(
        ['name' => 'admin.global_config'],
        ['description' => 'Admin settings', 'group' => 'admin']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

// ─── Index ─────────────────────────────────────────────────────

it('admin can list all API keys', function (): void {
    $admin = createApiKeyAdminUser();
    ApiKey::factory()->count(5)->create();

    $this->actingAs($admin)
        ->getJson('/api/v1/admin/api-keys')
        ->assertOk()
        ->assertJsonCount(5, 'data');
});

it('admin index includes user info', function (): void {
    $admin = createApiKeyAdminUser();
    $otherUser = User::factory()->create(['name' => 'John Doe', 'email' => 'john@example.com']);
    ApiKey::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/admin/api-keys')
        ->assertOk();

    expect($response->json('data.0.user.name'))->toBe('John Doe');
    expect($response->json('data.0.user.email'))->toBe('john@example.com');
});

// ─── Destroy ──────────────────────────────────────────────────

it('admin can revoke any user\'s API key', function (): void {
    $admin = createApiKeyAdminUser();
    $otherKey = ApiKey::factory()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/v1/admin/api-keys/{$otherKey->id}")
        ->assertOk()
        ->assertJsonPath('message', 'API key revoked.');

    $otherKey->refresh();
    expect($otherKey->revoked)->toBeTrue();
    expect($otherKey->revoked_at)->not->toBeNull();
});

// ─── Authorization ────────────────────────────────────────────

it('non-admin cannot list all API keys', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/admin/api-keys')
        ->assertStatus(403);
});

it('non-admin cannot revoke API keys via admin endpoint', function (): void {
    $user = User::factory()->create();
    $otherKey = ApiKey::factory()->create();

    $this->actingAs($user)
        ->deleteJson("/api/v1/admin/api-keys/{$otherKey->id}")
        ->assertStatus(403);
});

// ─── Auth required ─────────────────────────────────────────────

it('requires authentication for admin endpoints', function (): void {
    $this->getJson('/api/v1/admin/api-keys')->assertStatus(401);
    $this->deleteJson('/api/v1/admin/api-keys/1')->assertStatus(401);
});

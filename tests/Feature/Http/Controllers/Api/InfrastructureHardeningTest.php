<?php

use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

function grantPermissionOnProject(User $user, Project $project, string $permissionName, string $roleName): void
{
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => $roleName]);
    $permission = Permission::firstOrCreate(
        ['name' => $permissionName],
        ['description' => "{$permissionName} permission", 'group' => explode('.', $permissionName)[0] ?? 'admin']
    );
    $role->permissions()->attach($permission);
    $user->assignRole($role, $project);
}

function createGlobalAdminUser(): array
{
    $project = Project::factory()->enabled()->create();
    $user = User::factory()->create(['oauth_token' => null]);
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    grantPermissionOnProject($user, $project, 'admin.global_config', 'admin');

    return [$user, $project];
}

function resolveRateLimit(Request $request): Limit
{
    $limiter = RateLimiter::limiter('api_key');
    if (! is_callable($limiter)) {
        throw new \RuntimeException('api_key limiter is not registered.');
    }

    /** @var Limit|array<int, Limit> $resolved */
    $resolved = $limiter($request);

    if (is_array($resolved)) {
        return $resolved[0];
    }

    return $resolved;
}

it('uses ip-based limiter bucket for missing or invalid bearer tokens', function (): void {
    $user = User::factory()->create(['oauth_token' => null]);
    $apiKey = app(ApiKeyService::class)->generate($user, 'hardening-key');
    $validToken = $apiKey['plaintext'];

    $invalidA = Request::create('/api/v1/ext/tasks', 'GET', [], [], [], [
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_AUTHORIZATION' => 'Bearer invalid-a',
    ]);
    $invalidB = Request::create('/api/v1/ext/tasks', 'GET', [], [], [], [
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_AUTHORIZATION' => 'Bearer invalid-b',
    ]);
    $valid = Request::create('/api/v1/ext/tasks', 'GET', [], [], [], [
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_AUTHORIZATION' => "Bearer {$validToken}",
    ]);
    $validOtherIp = Request::create('/api/v1/ext/tasks', 'GET', [], [], [], [
        'REMOTE_ADDR' => '203.0.113.11',
        'HTTP_AUTHORIZATION' => "Bearer {$validToken}",
    ]);

    $invalidLimitA = resolveRateLimit($invalidA);
    $invalidLimitB = resolveRateLimit($invalidB);
    $validLimit = resolveRateLimit($valid);
    $validOtherIpLimit = resolveRateLimit($validOtherIp);

    expect($invalidLimitA->key)->toBe($invalidLimitB->key);
    expect($invalidLimitA->key)->toContain('api_key:ip:203.0.113.10');
    expect($validLimit->key)->not->toBe($invalidLimitA->key);
    expect($validLimit->key)->toContain(':ip:203.0.113.10');
    expect($validOtherIpLimit->key)->not->toBe($validLimit->key);
});

it('returns generic health check errors without leaking exception details', function (): void {
    DB::shouldReceive('connection')
        ->andThrow(new \RuntimeException('postgres-credential-leak'));

    $response = $this->getJson('/health');

    $response->assertStatus(503);
    $response->assertJsonPath('checks.postgresql.error', 'Check failed');
    expect($response->getContent())->not->toContain('postgres-credential-leak');
});

it('rejects private and internal webhook test urls', function (): void {
    [$user] = createGlobalAdminUser();

    foreach ([
        'http://127.0.0.1/webhook',
        'http://10.0.0.1/webhook',
        'http://169.254.169.254/latest/meta-data',
        'http://localhost/webhook',
    ] as $url) {
        $this->actingAs($user)
            ->postJson('/api/v1/admin/settings/test-webhook', [
                'webhook_url' => $url,
                'platform' => 'slack',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('webhook_url');
    }
});

it('accepts public webhook test urls', function (): void {
    [$user] = createGlobalAdminUser();

    Http::fake([
        '8.8.8.8/*' => Http::response('ok', 200),
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/admin/settings/test-webhook', [
            'webhook_url' => 'https://8.8.8.8/webhook',
            'platform' => 'generic',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);
});

<?php

use App\Models\Permission;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.gitlab.host' => 'https://gitlab.example.com']);
    config(['services.gitlab.bot_token' => 'test-bot-token']);
    config(['services.gitlab.bot_account_id' => 99]);
    config(['services.gitlab.vunnix_project_id' => null]);
    config(['app.url' => 'https://vunnix.example.com']);
});

function fakeGitLabForSetup(): void
{
    Http::fake([
        // Project lookup by path
        'gitlab.example.com/api/v4/projects/mygroup%2Fmyproject' => Http::response([
            'id' => 42,
            'name' => 'myproject',
            'path_with_namespace' => 'mygroup/myproject',
            'description' => 'A test project',
            'default_branch' => 'main',
        ]),
        // Bot membership check — Maintainer
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response([
            'id' => 99,
            'access_level' => 40,
        ]),
        // Webhook creation
        'gitlab.example.com/api/v4/projects/42/hooks' => Http::response([
            'id' => 555,
            'url' => 'https://vunnix.example.com/webhook',
        ], 201),
        // Pipeline trigger token creation
        'gitlab.example.com/api/v4/projects/42/triggers' => Http::response([
            'id' => 10,
            'token' => 'trigger-token-abc123',
            'description' => 'Vunnix task executor',
        ], 201),
        // Label creation
        'gitlab.example.com/api/v4/projects/42/labels' => Http::response([
            'id' => 1,
        ], 201),
    ]);
}

it('runs full setup successfully', function (): void {
    $user = User::factory()->create(['email' => 'admin@example.com']);

    fakeGitLabForSetup();

    $this->artisan('vunnix:setup', [
        'gitlab_project_path' => 'mygroup/myproject',
        '--admin-email' => 'admin@example.com',
        '--force' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Setup complete');

    // Verify permissions seeded
    expect(Permission::count())->toBe(7);

    // Verify project created and enabled
    $project = Project::where('gitlab_project_id', 42)->first();
    expect($project)->not->toBeNull();
    expect($project->enabled)->toBeTrue();
    expect($project->webhook_id)->toBe(555);

    // Verify roles created
    expect($project->roles()->count())->toBe(3);
    expect($project->roles()->pluck('name')->sort()->values()->all())
        ->toBe(['admin', 'developer', 'viewer']);

    // Verify admin assigned
    expect($user->hasRole('admin', $project))->toBeTrue();
    expect($user->permissionsForProject($project)->count())->toBe(7);
});

it('fails when GITLAB_BOT_TOKEN is missing', function (): void {
    config(['services.gitlab.bot_token' => '']);

    $this->artisan('vunnix:setup', [
        'gitlab_project_path' => 'mygroup/myproject',
    ])
        ->assertFailed()
        ->expectsOutputToContain('GITLAB_BOT_TOKEN');
});

it('fails when GitLab project is not found', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/mygroup%2Fnotfound' => Http::response(
            ['message' => '404 Project Not Found'],
            404
        ),
    ]);

    $this->artisan('vunnix:setup', [
        'gitlab_project_path' => 'mygroup/notfound',
    ])
        ->assertFailed()
        ->expectsOutputToContain('Failed to look up');
});

it('fails when admin email user does not exist', function (): void {
    fakeGitLabForSetup();

    $this->artisan('vunnix:setup', [
        'gitlab_project_path' => 'mygroup/myproject',
        '--admin-email' => 'nobody@example.com',
        '--force' => true,
    ])
        ->assertFailed()
        ->expectsOutputToContain('not found');
});

it('skips enablement when project already enabled', function (): void {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => true,
        'webhook_configured' => true,
        'webhook_id' => 555,
    ]);

    Http::fake([
        'gitlab.example.com/api/v4/projects/mygroup%2Fmyproject' => Http::response([
            'id' => 42,
            'name' => 'myproject',
            'path_with_namespace' => 'mygroup/myproject',
            'description' => '',
            'default_branch' => 'main',
        ]),
    ]);

    $this->artisan('vunnix:setup', [
        'gitlab_project_path' => 'mygroup/myproject',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('already enabled');

    // No webhook creation call should have been made
    Http::assertNotSent(fn ($req): bool => str_contains($req->url(), '/hooks'));
});

it('is idempotent for permissions and roles', function (): void {
    fakeGitLabForSetup();

    // Run setup twice
    $this->artisan('vunnix:setup', [
        'gitlab_project_path' => 'mygroup/myproject',
        '--force' => true,
    ])->assertSuccessful();

    // Re-fake HTTP for second run (webhook endpoint already consumed)
    fakeGitLabForSetup();

    $this->artisan('vunnix:setup', [
        'gitlab_project_path' => 'mygroup/myproject',
        '--force' => true,
    ])->assertSuccessful();

    // Still only 7 permissions, 3 roles
    expect(Permission::count())->toBe(7);
    $project = Project::where('gitlab_project_id', 42)->first();
    expect($project->roles()->count())->toBe(3);
});

it('fails when APP_URL is not configured', function (): void {
    config(['app.url' => 'http://localhost']);

    $this->artisan('vunnix:setup', [
        'gitlab_project_path' => 'mygroup/myproject',
    ])
        ->assertFailed()
        ->expectsOutputToContain('APP_URL is not configured');
});

it('caches gitlab project web url when available', function (): void {
    $user = User::factory()->create(['email' => 'admin@example.com']);

    Http::fake([
        'gitlab.example.com/api/v4/projects/mygroup%2Fmyproject' => Http::response([
            'id' => 42,
            'name' => 'myproject',
            'path_with_namespace' => 'mygroup/myproject',
            'description' => 'A test project',
            'default_branch' => 'main',
            'web_url' => 'https://gitlab.example.com/mygroup/myproject',
        ]),
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response([
            'id' => 99,
            'access_level' => 40,
        ]),
        'gitlab.example.com/api/v4/projects/42/hooks' => Http::response(['id' => 555], 201),
        'gitlab.example.com/api/v4/projects/42/triggers' => Http::response([
            'id' => 10,
            'token' => 'trigger-token-abc123',
            'description' => 'Vunnix task executor',
        ], 201),
        'gitlab.example.com/api/v4/projects/42/labels' => Http::response(['id' => 1], 201),
    ]);

    $this->artisan('vunnix:setup', [
        'gitlab_project_path' => 'mygroup/myproject',
        '--admin-email' => $user->email,
        '--force' => true,
    ])->assertSuccessful();

    $project = Project::where('gitlab_project_id', 42)->firstOrFail();
    expect(Cache::get("project.{$project->id}.gitlab_web_url"))->toBe('https://gitlab.example.com/mygroup/myproject');
});

it('fails when project enablement fails', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/mygroup%2Fmyproject' => Http::response([
            'id' => 42,
            'name' => 'myproject',
            'path_with_namespace' => 'mygroup/myproject',
            'description' => 'A test project',
            'default_branch' => 'main',
        ]),
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response([
            'id' => 99,
            'access_level' => 40,
        ]),
        'gitlab.example.com/api/v4/projects/42/hooks' => Http::response(['message' => 'boom'], 500),
    ]);

    $this->artisan('vunnix:setup', [
        'gitlab_project_path' => 'mygroup/myproject',
        '--force' => true,
    ])
        ->assertFailed()
        ->expectsOutputToContain('Failed: Failed to create GitLab webhook');
});

it('prints warnings emitted by enablement', function (): void {
    Http::fake([
        'gitlab.example.com/api/v4/projects/mygroup%2Fmyproject' => Http::response([
            'id' => 42,
            'name' => 'myproject',
            'path_with_namespace' => 'mygroup/myproject',
            'description' => 'A test project',
            'default_branch' => 'main',
        ]),
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response([
            'id' => 99,
            'access_level' => 40,
        ]),
        'gitlab.example.com/api/v4/projects/42/hooks' => Http::response([
            'id' => 555,
            'url' => 'https://vunnix.example.com/webhook',
        ], 201),
        'gitlab.example.com/api/v4/projects/42/triggers' => Http::response(['message' => 'forbidden'], 403),
        'gitlab.example.com/api/v4/projects/42/labels' => Http::response(['id' => 1], 201),
    ]);

    $this->artisan('vunnix:setup', [
        'gitlab_project_path' => 'mygroup/myproject',
        '--force' => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('[!] Failed to create CI pipeline trigger token:');
});

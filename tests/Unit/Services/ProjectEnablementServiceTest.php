<?php

use App\Models\Project;
use App\Models\ProjectConfig;
use App\Services\ProjectEnablementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['services.gitlab.host' => 'https://gitlab.example.com']);
    config(['services.gitlab.bot_token' => 'test-bot-token']);
    config(['services.gitlab.bot_account_id' => null]); // not pre-configured
    config(['services.gitlab.vunnix_project_id' => 100]);
    config(['app.url' => 'https://vunnix.example.com']);
});

function fakeGitLabForEnable(): void
{
    Http::fake([
        // Bot user lookup
        'gitlab.example.com/api/v4/user' => Http::response([
            'id' => 99,
            'username' => 'vunnix-bot',
        ]),
        // Bot membership check — Maintainer (40)
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response([
            'id' => 99,
            'access_level' => 40,
        ]),
        // Vunnix project visibility check
        'gitlab.example.com/api/v4/projects/100' => Http::response([
            'id' => 100,
            'visibility' => 'internal',
        ]),
        // Webhook creation
        'gitlab.example.com/api/v4/projects/42/hooks' => Http::response([
            'id' => 555,
            'url' => 'https://vunnix.example.com/api/webhook',
        ], 201),
        // Label creation (6 labels)
        'gitlab.example.com/api/v4/projects/42/labels' => Http::response([
            'id' => 1,
            'name' => 'ai::reviewed',
        ], 201),
    ]);
}

it('enables a project successfully', function () {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => false,
        'webhook_configured' => false,
    ]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);

    fakeGitLabForEnable();

    $service = app(ProjectEnablementService::class);
    $result = $service->enable($project);

    expect($result['success'])->toBeTrue();
    expect($result['warnings'])->toBeEmpty();

    $project->refresh();
    expect($project->enabled)->toBeTrue();
    expect($project->webhook_configured)->toBeTrue();
    expect($project->webhook_id)->toBe(555);
    expect($project->projectConfig->webhook_secret)->not->toBeNull();
});

it('fails to enable when bot is not a project member', function () {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => false,
    ]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);

    Http::fake([
        'gitlab.example.com/api/v4/user' => Http::response(['id' => 99]),
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response(
            ['message' => '404 Not found'],
            404
        ),
    ]);

    $service = app(ProjectEnablementService::class);
    $result = $service->enable($project);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('bot account is not a member');

    $project->refresh();
    expect($project->enabled)->toBeFalse();
});

it('fails to enable when bot has insufficient permissions', function () {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => false,
    ]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);

    Http::fake([
        'gitlab.example.com/api/v4/user' => Http::response(['id' => 99]),
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response([
            'id' => 99,
            'access_level' => 30, // Developer, not Maintainer
        ]),
    ]);

    $service = app(ProjectEnablementService::class);
    $result = $service->enable($project);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('Maintainer');

    $project->refresh();
    expect($project->enabled)->toBeFalse();
});

it('warns when Vunnix project is private (D150)', function () {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => false,
    ]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);

    Http::fake([
        'gitlab.example.com/api/v4/user' => Http::response(['id' => 99]),
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response([
            'id' => 99,
            'access_level' => 40,
        ]),
        'gitlab.example.com/api/v4/projects/100' => Http::response([
            'id' => 100,
            'visibility' => 'private',
        ]),
        'gitlab.example.com/api/v4/projects/42/hooks' => Http::response([
            'id' => 555,
        ], 201),
        'gitlab.example.com/api/v4/projects/42/labels' => Http::response([
            'id' => 1,
        ], 201),
    ]);

    $service = app(ProjectEnablementService::class);
    $result = $service->enable($project);

    expect($result['success'])->toBeTrue();
    expect($result['warnings'])->not->toBeEmpty();
    expect(collect($result['warnings'])->contains(fn ($w) => str_contains($w, 'private')))->toBeTrue();
});

it('creates all 6 ai:: labels on enable', function () {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => false,
    ]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);

    fakeGitLabForEnable();

    $service = app(ProjectEnablementService::class);
    $service->enable($project);

    // Verify 6 label creation requests were sent
    $labelRequests = collect(Http::recorded())
        ->filter(fn ($pair) =>
            str_contains($pair[0]->url(), '/labels') &&
            $pair[0]->method() === 'POST'
        );

    expect($labelRequests)->toHaveCount(6);
});

it('skips existing labels without error (idempotent)', function () {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => false,
    ]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);

    Http::fake([
        'gitlab.example.com/api/v4/user' => Http::response(['id' => 99]),
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response([
            'id' => 99,
            'access_level' => 40,
        ]),
        'gitlab.example.com/api/v4/projects/100' => Http::response([
            'id' => 100,
            'visibility' => 'internal',
        ]),
        'gitlab.example.com/api/v4/projects/42/hooks' => Http::response([
            'id' => 555,
        ], 201),
        // All labels already exist
        'gitlab.example.com/api/v4/projects/42/labels' => Http::response(
            ['message' => 'Label already exists'],
            409
        ),
    ]);

    $service = app(ProjectEnablementService::class);
    $result = $service->enable($project);

    expect($result['success'])->toBeTrue();
});

it('disables a project and removes the webhook', function () {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => true,
        'webhook_configured' => true,
        'webhook_id' => 555,
    ]);

    Http::fake([
        'gitlab.example.com/api/v4/projects/42/hooks/555' => Http::response(null, 204),
    ]);

    $service = app(ProjectEnablementService::class);
    $result = $service->disable($project);

    expect($result['success'])->toBeTrue();

    $project->refresh();
    expect($project->enabled)->toBeFalse();
    expect($project->webhook_configured)->toBeFalse();
    expect($project->webhook_id)->toBeNull();
});

it('disables a project without webhook_id gracefully', function () {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => true,
        'webhook_configured' => false,
        'webhook_id' => null,
    ]);

    $service = app(ProjectEnablementService::class);
    $result = $service->disable($project);

    expect($result['success'])->toBeTrue();

    $project->refresh();
    expect($project->enabled)->toBeFalse();
});

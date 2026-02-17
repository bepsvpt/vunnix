<?php

use App\Models\Project;
use App\Services\GitLabClient;
use App\Services\ProjectEnablementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'services.gitlab.host' => 'https://gitlab.example.com',
        'services.gitlab.bot_token' => 'test-bot-pat',
        'services.gitlab.bot_account_id' => null,
        'app.url' => 'https://vunnix.example.com',
    ]);
});

// ─── enable: bot user ID resolution returns null ─────────────────

it('returns error when bot user ID cannot be resolved', function (): void {
    // No bot_account_id configured and getCurrentUser throws
    config(['services.gitlab.bot_account_id' => null]);

    $mock = Mockery::mock(GitLabClient::class);
    $mock->shouldReceive('getCurrentUser')
        ->once()
        ->andThrow(new RuntimeException('Unauthorized'));

    $this->app->instance(GitLabClient::class, $mock);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'Failed to resolve bot user ID')
                && str_contains($context['error'], 'Unauthorized');
        });

    $project = Project::factory()->create();
    $service = app(ProjectEnablementService::class);
    $result = $service->enable($project);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('Could not determine bot account user ID');
});

// ─── enable: webhook creation exception ─────────────────────────

it('returns error when webhook creation throws', function (): void {
    config(['services.gitlab.bot_account_id' => '42']);

    $mock = Mockery::mock(GitLabClient::class);
    $mock->shouldReceive('getProjectMember')
        ->once()
        ->with(Mockery::any(), 42)
        ->andReturn(['access_level' => 40]);

    $mock->shouldReceive('createWebhook')
        ->once()
        ->andThrow(new RuntimeException('403 Forbidden'));

    $this->app->instance(GitLabClient::class, $mock);

    $project = Project::factory()->create();
    $service = app(ProjectEnablementService::class);
    $result = $service->enable($project);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('Failed to create GitLab webhook');
    expect($result['error'])->toContain('403 Forbidden');
});

// ─── enable: label creation exception (idempotent, logs warning) ─

it('continues when label creation throws and logs warning', function (): void {
    config(['services.gitlab.bot_account_id' => '42']);

    $project = Project::factory()->create();

    $mock = Mockery::mock(GitLabClient::class);
    $mock->shouldReceive('getProjectMember')
        ->once()
        ->with($project->gitlab_project_id, 42)
        ->andReturn(['access_level' => 40]);

    $mock->shouldReceive('createWebhook')
        ->once()
        ->andReturn(['id' => 99]);

    $mock->shouldReceive('createPipelineTrigger')
        ->once()
        ->andReturn(['token' => 'trigger-abc']);

    // All 6 label creations throw (e.g., 409 conflict)
    $mock->shouldReceive('createProjectLabel')
        ->times(6)
        ->andThrow(new RuntimeException('409 Label already exists'));

    $this->app->instance(GitLabClient::class, $mock);

    Log::shouldReceive('warning')
        ->times(6)
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'Failed to create label')
                && str_contains($context['error'], '409 Label already exists');
        });

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message): bool {
            return $message === 'Project enabled';
        });

    $service = app(ProjectEnablementService::class);
    $result = $service->enable($project);

    expect($result['success'])->toBeTrue();
    expect($result['warnings'])->toBeEmpty();
});

// ─── disable: webhook deletion exception ─────────────────────────

it('logs warning and continues when webhook deletion throws during disable', function (): void {
    $project = Project::factory()->create([
        'enabled' => true,
        'webhook_configured' => true,
        'webhook_id' => 77,
    ]);

    $mock = Mockery::mock(GitLabClient::class);
    $mock->shouldReceive('deleteWebhook')
        ->once()
        ->with($project->gitlab_project_id, 77)
        ->andThrow(new RuntimeException('404 Not Found'));

    $this->app->instance(GitLabClient::class, $mock);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'Failed to remove webhook')
                && $context['webhook_id'] === 77
                && str_contains($context['error'], '404 Not Found');
        });

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message): bool {
            return $message === 'Project disabled';
        });

    $service = app(ProjectEnablementService::class);
    $result = $service->disable($project);

    expect($result['success'])->toBeTrue();

    $project->refresh();
    expect($project->enabled)->toBeFalse();
    expect($project->webhook_configured)->toBeFalse();
    expect($project->webhook_id)->toBeNull();
});

// ─── resolveBotUserId: API exception returns null ────────────────

it('returns null when getCurrentUser throws during bot user resolution', function (): void {
    config(['services.gitlab.bot_account_id' => null]);

    $mock = Mockery::mock(GitLabClient::class);
    $mock->shouldReceive('getCurrentUser')
        ->once()
        ->andThrow(new RuntimeException('Network error'));

    $this->app->instance(GitLabClient::class, $mock);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'Failed to resolve bot user ID')
                && str_contains($context['error'], 'Network error');
        });

    $project = Project::factory()->create();
    $service = app(ProjectEnablementService::class);
    $result = $service->enable($project);

    // Bot user resolution failed => early abort
    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('Could not determine bot account user ID');
});

// ─── accessLevelName: level < 10 returns the numeric level ──────

it('returns numeric level name for access level below guest', function (): void {
    config(['services.gitlab.bot_account_id' => '42']);

    $mock = Mockery::mock(GitLabClient::class);
    $mock->shouldReceive('getProjectMember')
        ->once()
        ->andReturn(['access_level' => 5]);

    $this->app->instance(GitLabClient::class, $mock);

    $project = Project::factory()->create();
    $service = app(ProjectEnablementService::class);
    $result = $service->enable($project);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('level 5');
    expect($result['error'])->toContain('requires Maintainer');
});

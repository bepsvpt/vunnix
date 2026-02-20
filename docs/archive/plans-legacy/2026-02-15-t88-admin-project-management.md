# T88: Admin Page — Project Management Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build the admin project management page — enable/disable projects with bot membership verification (D129), Vunnix visibility check (D150), webhook auto-creation, and `ai::*` label pre-creation. View webhook health, bot status, recent activity, and active conversations per project.

**Architecture:** A `ProjectEnablementService` orchestrates the multi-step enablement flow (verify bot → check visibility → create webhook → create labels) synchronously within the HTTP request (~1-2s for 4 GitLab API calls). New GitLabClient methods for project metadata, member lookup, and label creation. RESTful admin API routes protected by `admin.global_config` permission. Vue admin page with project list, status indicators, and enable/disable actions.

**Tech Stack:** Laravel 11 (controller, service, form request, API resource), GitLab REST API v4 (projects, members, hooks, labels), Vue 3 (Composition API, `<script setup>`), Pinia, axios, Pest (feature tests), Vitest (component tests)

---

### Task 1: Add GitLab API methods for project metadata and member lookup

**Files:**
- Modify: `app/Services/GitLabClient.php` (add 3 methods after the Webhooks section, ~line 520)
- Test: `tests/Unit/Services/GitLabClientProjectMethodsTest.php`

**Step 1: Write the failing tests**

Create `tests/Unit/Services/GitLabClientProjectMethodsTest.php`:

```php
<?php

use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    config(['services.gitlab.host' => 'https://gitlab.example.com']);
    config(['services.gitlab.bot_token' => 'test-token']);
});

it('fetches project details via getProject', function () {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42' => Http::response([
            'id' => 42,
            'name' => 'My Project',
            'visibility' => 'internal',
            'path_with_namespace' => 'group/my-project',
        ]),
    ]);

    $client = new GitLabClient();
    $result = $client->getProject(42);

    expect($result)
        ->toHaveKey('id', 42)
        ->toHaveKey('visibility', 'internal');

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/api/v4/projects/42') &&
        $req->header('PRIVATE-TOKEN')[0] === 'test-token'
    );
});

it('fetches project member via getProjectMember', function () {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response([
            'id' => 99,
            'username' => 'vunnix-bot',
            'access_level' => 40, // Maintainer
        ]),
    ]);

    $client = new GitLabClient();
    $result = $client->getProjectMember(42, 99);

    expect($result)
        ->toHaveKey('access_level', 40);
});

it('returns null for getProjectMember when user is not a member', function () {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response(
            ['message' => '404 Not found'],
            404
        ),
    ]);

    $client = new GitLabClient();
    $result = $client->getProjectMember(42, 99);

    expect($result)->toBeNull();
});

it('creates a project label via createProjectLabel', function () {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/labels' => Http::response([
            'id' => 1,
            'name' => 'ai::reviewed',
            'color' => '#428BCA',
        ], 201),
    ]);

    $client = new GitLabClient();
    $result = $client->createProjectLabel(42, 'ai::reviewed', '#428BCA', 'Applied when AI review is complete');

    expect($result)
        ->toHaveKey('name', 'ai::reviewed');

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/api/v4/projects/42/labels') &&
        $req->method() === 'POST' &&
        $req->data()['name'] === 'ai::reviewed' &&
        $req->data()['color'] === '#428BCA' &&
        $req->data()['description'] === 'Applied when AI review is complete'
    );
});

it('returns null for createProjectLabel when label already exists (409)', function () {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/labels' => Http::response(
            ['message' => 'Label already exists'],
            409
        ),
    ]);

    $client = new GitLabClient();
    $result = $client->createProjectLabel(42, 'ai::reviewed', '#428BCA');

    expect($result)->toBeNull();
});

it('lists project labels via listProjectLabels', function () {
    Http::fake([
        'gitlab.example.com/api/v4/projects/42/labels*' => Http::response([
            ['id' => 1, 'name' => 'bug', 'color' => '#d9534f'],
            ['id' => 2, 'name' => 'ai::reviewed', 'color' => '#428BCA'],
        ]),
    ]);

    $client = new GitLabClient();
    $result = $client->listProjectLabels(42);

    expect($result)->toHaveCount(2);
    expect($result[1]['name'])->toBe('ai::reviewed');
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/Services/GitLabClientProjectMethodsTest.php`
Expected: FAIL — methods do not exist

**Step 3: Implement the GitLabClient methods**

Add to `app/Services/GitLabClient.php` after the `deleteWebhook` method (after line 520), before the Pipelines section:

```php
// ------------------------------------------------------------------
//  Project Metadata
// ------------------------------------------------------------------

/**
 * Get project details (visibility, name, namespace, etc.).
 */
public function getProject(int $projectId): array
{
    $response = $this->request()->get(
        $this->url("projects/{$projectId}"),
    );

    return $this->handleResponse($response, "getProject #{$projectId}")->json();
}

/**
 * Get a specific member of a project (including inherited members).
 *
 * Uses the /members/all endpoint which includes inherited members
 * from parent groups. Returns null if the user is not a member.
 */
public function getProjectMember(int $projectId, int $userId): ?array
{
    $response = $this->request()->get(
        $this->url("projects/{$projectId}/members/all/{$userId}"),
    );

    if ($response->status() === 404) {
        return null;
    }

    return $this->handleResponse($response, "getProjectMember #{$projectId}/#{$userId}")->json();
}

// ------------------------------------------------------------------
//  Project Labels
// ------------------------------------------------------------------

/**
 * Create a project-level label.
 *
 * Returns null if the label already exists (409 Conflict) — idempotent.
 */
public function createProjectLabel(int $projectId, string $name, string $color, string $description = ''): ?array
{
    $response = $this->request()->post(
        $this->url("projects/{$projectId}/labels"),
        array_filter([
            'name' => $name,
            'color' => $color,
            'description' => $description,
        ]),
    );

    if ($response->status() === 409) {
        return null;
    }

    return $this->handleResponse($response, "createProjectLabel {$name}")->json();
}

/**
 * List all labels for a project.
 *
 * @return array<int, array{id: int, name: string, color: string, description: string|null}>
 */
public function listProjectLabels(int $projectId): array
{
    $response = $this->request()->get(
        $this->url("projects/{$projectId}/labels"),
        ['per_page' => 100],
    );

    return $this->handleResponse($response, "listProjectLabels #{$projectId}")->json();
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Services/GitLabClientProjectMethodsTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Services/GitLabClient.php tests/Unit/Services/GitLabClientProjectMethodsTest.php
git commit --no-gpg-sign -m "T88.1: Add GitLab API methods for project metadata, members, and labels"
```

---

### Task 2: Create ProjectEnablementService

**Files:**
- Create: `app/Services/ProjectEnablementService.php`
- Test: `tests/Unit/Services/ProjectEnablementServiceTest.php`

**Context:** This service orchestrates the multi-step enablement flow. It uses GitLabClient to:
1. Verify bot has Maintainer (40) role on the project (D129)
2. Check Vunnix GitLab project visibility (D150) — warn if private
3. Create webhook with secret token
4. Pre-create 6 `ai::*` labels (idempotent — skip existing)

On disable: remove webhook, set `enabled=false`, preserve data (D60).

The bot account's GitLab user ID must be resolved. Use the GitLab `/user` endpoint (authenticated as bot) to get the bot's own user ID. Cache this in the service since it won't change.

**Step 1: Write the failing tests**

Create `tests/Unit/Services/ProjectEnablementServiceTest.php`:

```php
<?php

use App\Models\Project;
use App\Models\ProjectConfig;
use App\Services\GitLabClient;
use App\Services\ProjectEnablementService;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['services.gitlab.host' => 'https://gitlab.example.com']);
    config(['services.gitlab.bot_token' => 'test-bot-token']);
    config(['services.gitlab.bot_user_id' => null]); // not pre-configured
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
    expect($result['warnings'])->toContain(fn ($w) => str_contains($w, 'private'));
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
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/Services/ProjectEnablementServiceTest.php`
Expected: FAIL — class does not exist

**Step 3: Implement ProjectEnablementService**

Create `app/Services/ProjectEnablementService.php`:

```php
<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProjectEnablementService
{
    private const MAINTAINER_ACCESS_LEVEL = 40;

    private const AI_LABELS = [
        ['name' => 'ai::reviewed',   'color' => '#428BCA', 'description' => 'AI code review completed'],
        ['name' => 'ai::risk-high',  'color' => '#D9534F', 'description' => 'AI assessed high risk'],
        ['name' => 'ai::risk-medium','color' => '#F0AD4E', 'description' => 'AI assessed medium risk'],
        ['name' => 'ai::risk-low',   'color' => '#5CB85C', 'description' => 'AI assessed low risk'],
        ['name' => 'ai::security',   'color' => '#D9534F', 'description' => 'AI flagged security concern'],
        ['name' => 'ai::created',    'color' => '#6F42C1', 'description' => 'Created by AI'],
    ];

    public function __construct(
        private readonly GitLabClient $gitLab,
    ) {}

    /**
     * Enable a project in Vunnix.
     *
     * Steps:
     * 1. Verify bot has Maintainer role (D129)
     * 2. Check Vunnix project visibility (D150)
     * 3. Create webhook with secret token
     * 4. Pre-create ai::* labels
     *
     * @return array{success: bool, error?: string, warnings: array<string>}
     */
    public function enable(Project $project): array
    {
        $warnings = [];

        // 1. Resolve bot user ID and verify membership
        $botUserId = $this->resolveBotUserId();
        if ($botUserId === null) {
            return ['success' => false, 'error' => 'Could not determine bot account user ID. Check GITLAB_BOT_TOKEN configuration.', 'warnings' => []];
        }

        $member = $this->gitLab->getProjectMember($project->gitlab_project_id, $botUserId);

        if ($member === null) {
            return [
                'success' => false,
                'error' => "The Vunnix bot account is not a member of this GitLab project. Add the bot as a Maintainer to project #{$project->gitlab_project_id}.",
                'warnings' => [],
            ];
        }

        if ($member['access_level'] < self::MAINTAINER_ACCESS_LEVEL) {
            $levelName = $this->accessLevelName($member['access_level']);
            return [
                'success' => false,
                'error' => "The bot account has {$levelName} access but requires Maintainer (or higher). Update the bot's role in GitLab project #{$project->gitlab_project_id}.",
                'warnings' => [],
            ];
        }

        // 2. Check Vunnix project visibility (D150)
        $vunnixProjectId = config('services.gitlab.vunnix_project_id');
        if ($vunnixProjectId) {
            try {
                $vunnixProject = $this->gitLab->getProject((int) $vunnixProjectId);
                if (($vunnixProject['visibility'] ?? '') === 'private') {
                    $warnings[] = 'The Vunnix GitLab project has private visibility. CI job tokens from target projects may not be able to pull the executor image. Consider setting it to internal or public (D150).';
                }
            } catch (\Throwable $e) {
                Log::warning('Could not check Vunnix project visibility', ['error' => $e->getMessage()]);
            }
        }

        // 3. Create webhook
        $secret = Str::random(40);
        $webhookUrl = rtrim(config('app.url'), '/') . '/api/webhook';

        try {
            $webhook = $this->gitLab->createWebhook($project->gitlab_project_id, $webhookUrl, $secret, [
                'merge_requests_events' => true,
                'note_events' => true,
                'issues_events' => true,
                'push_events' => true,
            ]);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => 'Failed to create GitLab webhook: ' . $e->getMessage(),
                'warnings' => $warnings,
            ];
        }

        // 4. Pre-create ai::* labels (idempotent — 409 = already exists)
        foreach (self::AI_LABELS as $label) {
            try {
                $this->gitLab->createProjectLabel(
                    $project->gitlab_project_id,
                    $label['name'],
                    $label['color'],
                    $label['description'],
                );
            } catch (\Throwable $e) {
                Log::warning("Failed to create label {$label['name']}", [
                    'project_id' => $project->gitlab_project_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 5. Update project state
        $project->update([
            'enabled' => true,
            'webhook_configured' => true,
            'webhook_id' => $webhook['id'],
        ]);

        // Store webhook secret in config
        $config = $project->projectConfig;
        if ($config) {
            $config->update(['webhook_secret' => $secret]);
        } else {
            $project->projectConfig()->create(['webhook_secret' => $secret]);
        }

        Log::info('Project enabled', ['project_id' => $project->id, 'gitlab_project_id' => $project->gitlab_project_id]);

        return ['success' => true, 'warnings' => $warnings];
    }

    /**
     * Disable a project in Vunnix.
     *
     * Removes the webhook from GitLab, marks project as disabled.
     * Data is preserved in read-only mode (D60).
     *
     * @return array{success: bool, error?: string, warnings: array<string>}
     */
    public function disable(Project $project): array
    {
        // Remove webhook if one was configured
        if ($project->webhook_id) {
            try {
                $this->gitLab->deleteWebhook($project->gitlab_project_id, $project->webhook_id);
            } catch (\Throwable $e) {
                Log::warning('Failed to remove webhook during project disable', [
                    'project_id' => $project->id,
                    'webhook_id' => $project->webhook_id,
                    'error' => $e->getMessage(),
                ]);
                // Continue with disable even if webhook removal fails
            }
        }

        $project->update([
            'enabled' => false,
            'webhook_configured' => false,
            'webhook_id' => null,
        ]);

        Log::info('Project disabled', ['project_id' => $project->id]);

        return ['success' => true, 'warnings' => []];
    }

    /**
     * Get the bot account's GitLab user ID.
     *
     * Uses config value if set, otherwise queries GitLab /user endpoint.
     */
    private function resolveBotUserId(): ?int
    {
        $configured = config('services.gitlab.bot_user_id');
        if ($configured) {
            return (int) $configured;
        }

        try {
            $response = $this->gitLab->getCurrentUser();
            return $response['id'] ?? null;
        } catch (\Throwable $e) {
            Log::error('Failed to resolve bot user ID', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function accessLevelName(int $level): string
    {
        return match (true) {
            $level >= 50 => 'Owner',
            $level >= 40 => 'Maintainer',
            $level >= 30 => 'Developer',
            $level >= 20 => 'Reporter',
            $level >= 10 => 'Guest',
            default => "level {$level}",
        };
    }
}
```

**Note:** This requires a `getCurrentUser()` method on GitLabClient. Add it in the Project Metadata section of GitLabClient:

```php
/**
 * Get the authenticated user (bot account).
 */
public function getCurrentUser(): array
{
    $response = $this->request()->get(
        $this->url('user'),
    );

    return $this->handleResponse($response, 'getCurrentUser')->json();
}
```

Also add `vunnix_project_id` and `bot_user_id` to `config/services.php` under the `gitlab` key:

```php
'gitlab' => [
    // ... existing keys ...
    'bot_user_id' => env('GITLAB_BOT_USER_ID'),
    'vunnix_project_id' => env('GITLAB_VUNNIX_PROJECT_ID'),
],
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Services/ProjectEnablementServiceTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Services/ProjectEnablementService.php tests/Unit/Services/ProjectEnablementServiceTest.php app/Services/GitLabClient.php config/services.php
git commit --no-gpg-sign -m "T88.2: Create ProjectEnablementService with bot verification, webhook, and label creation"
```

---

### Task 3: Create AdminProjectController and API routes

**Files:**
- Create: `app/Http/Controllers/Api/AdminProjectController.php`
- Create: `app/Http/Resources/AdminProjectResource.php`
- Modify: `routes/api.php` (add admin routes)
- Test: `tests/Feature/AdminProjectApiTest.php`

**Context:** RESTful admin API endpoints protected by `admin.global_config` permission. The controller delegates to `ProjectEnablementService` for enable/disable. The `index` endpoint returns all projects (not just user's enabled projects) with status information — webhook health, bot membership status, recent task count, active conversation count.

**Step 1: Write the failing tests**

Create `tests/Feature/AdminProjectApiTest.php`:

```php
<?php

use App\Models\Conversation;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Ensure agent_conversations table exists (SQLite test env)
    if (! Schema::hasTable('agent_conversations')) {
        Schema::create('agent_conversations', function ($table) {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('title');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });
    } elseif (! Schema::hasColumn('agent_conversations', 'project_id')) {
        Schema::table('agent_conversations', function ($table) {
            $table->unsignedBigInteger('project_id')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
    }

    if (! Schema::hasTable('agent_conversation_messages')) {
        Schema::create('agent_conversation_messages', function ($table) {
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

    config(['services.gitlab.host' => 'https://gitlab.example.com']);
    config(['services.gitlab.bot_token' => 'test-bot-token']);
    config(['services.gitlab.vunnix_project_id' => 100]);
});

function createAdmin(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'admin']);
    $perm = Permission::firstOrCreate(
        ['name' => 'admin.global_config'],
        ['description' => 'Admin settings', 'group' => 'admin']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

function createNonAdmin(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'developer']);
    $perm = Permission::firstOrCreate(
        ['name' => 'chat.access'],
        ['description' => 'Chat access', 'group' => 'chat']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

// ─── Index ──────────────────────────────────────────────────────

it('returns project list for admin users', function () {
    $project = Project::factory()->create(['enabled' => true]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $admin = createAdmin($project);

    $this->actingAs($admin)
        ->getJson('/api/v1/admin/projects')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'name', 'slug', 'gitlab_project_id', 'enabled', 'webhook_configured', 'recent_task_count', 'active_conversation_count']],
        ]);
});

it('rejects project list for non-admin users', function () {
    $project = Project::factory()->create(['enabled' => true]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $user = createNonAdmin($project);

    $this->actingAs($user)
        ->getJson('/api/v1/admin/projects')
        ->assertForbidden();
});

it('rejects project list for unauthenticated users', function () {
    $this->getJson('/api/v1/admin/projects')
        ->assertUnauthorized();
});

// ─── Enable ─────────────────────────────────────────────────────

it('enables a project successfully', function () {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => false,
    ]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $admin = createAdmin($project);

    Http::fake([
        'gitlab.example.com/api/v4/user' => Http::response(['id' => 99]),
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response([
            'id' => 99, 'access_level' => 40,
        ]),
        'gitlab.example.com/api/v4/projects/100' => Http::response([
            'id' => 100, 'visibility' => 'internal',
        ]),
        'gitlab.example.com/api/v4/projects/42/hooks' => Http::response([
            'id' => 555,
        ], 201),
        'gitlab.example.com/api/v4/projects/42/labels' => Http::response([
            'id' => 1,
        ], 201),
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/projects/{$project->id}/enable")
        ->assertOk()
        ->assertJsonPath('success', true);

    $project->refresh();
    expect($project->enabled)->toBeTrue();
    expect($project->webhook_id)->toBe(555);
});

it('returns error when bot is not a member on enable', function () {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => false,
    ]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $admin = createAdmin($project);

    Http::fake([
        'gitlab.example.com/api/v4/user' => Http::response(['id' => 99]),
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response(
            ['message' => '404 Not found'],
            404
        ),
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/projects/{$project->id}/enable")
        ->assertUnprocessableEntity()
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['success', 'error']);
});

// ─── Disable ────────────────────────────────────────────────────

it('disables an enabled project', function () {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => true,
        'webhook_configured' => true,
        'webhook_id' => 555,
    ]);
    $admin = createAdmin($project);

    Http::fake([
        'gitlab.example.com/api/v4/projects/42/hooks/555' => Http::response(null, 204),
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/projects/{$project->id}/disable")
        ->assertOk()
        ->assertJsonPath('success', true);

    $project->refresh();
    expect($project->enabled)->toBeFalse();
    expect($project->webhook_id)->toBeNull();
});

it('rejects enable from non-admin', function () {
    $project = Project::factory()->create(['enabled' => false]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $user = createNonAdmin($project);

    $this->actingAs($user)
        ->postJson("/api/v1/admin/projects/{$project->id}/enable")
        ->assertForbidden();
});

// ─── Show ───────────────────────────────────────────────────────

it('returns project details for admin', function () {
    $project = Project::factory()->create(['enabled' => true]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $admin = createAdmin($project);

    $this->actingAs($admin)
        ->getJson("/api/v1/admin/projects/{$project->id}")
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['id', 'name', 'slug', 'gitlab_project_id', 'enabled', 'webhook_configured', 'recent_task_count', 'active_conversation_count'],
        ]);
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/AdminProjectApiTest.php`
Expected: FAIL — routes and controller don't exist

**Step 3: Create AdminProjectResource**

Create `app/Http/Resources/AdminProjectResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Enums\TaskStatus;
use App\Models\Conversation;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gitlab_project_id' => $this->gitlab_project_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'enabled' => $this->enabled,
            'webhook_configured' => $this->webhook_configured,
            'webhook_id' => $this->webhook_id,
            'recent_task_count' => Task::where('project_id', $this->id)
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
            'active_conversation_count' => Conversation::where('project_id', $this->id)
                ->notArchived()
                ->where('updated_at', '>=', now()->subDays(30))
                ->count(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
```

**Step 4: Create AdminProjectController**

Create `app/Http/Controllers/Api/AdminProjectController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminProjectResource;
use App\Models\Project;
use App\Services\ProjectEnablementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminProjectController extends Controller
{
    public function __construct(
        private readonly ProjectEnablementService $enablement,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $projects = Project::orderBy('name')->get();

        return response()->json([
            'data' => AdminProjectResource::collection($projects),
        ]);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'data' => new AdminProjectResource($project),
        ]);
    }

    public function enable(Request $request, Project $project): JsonResponse
    {
        $this->authorizeAdmin($request);

        $result = $this->enablement->enable($project);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'],
                'warnings' => $result['warnings'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'warnings' => $result['warnings'],
            'data' => new AdminProjectResource($project->fresh()),
        ]);
    }

    public function disable(Request $request, Project $project): JsonResponse
    {
        $this->authorizeAdmin($request);

        $result = $this->enablement->disable($project);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new AdminProjectResource($project->fresh()),
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        $hasAdmin = $user->projects()
            ->get()
            ->contains(fn ($project) => $user->hasPermission('admin.global_config', $project));

        if (! $hasAdmin) {
            abort(403, 'Admin access required.');
        }
    }
}
```

**Step 5: Add routes to `routes/api.php`**

Add inside the existing `Route::middleware('auth')` group:

```php
// Admin project management (T88)
Route::get('/admin/projects', [AdminProjectController::class, 'index'])
    ->name('api.admin.projects.index');
Route::get('/admin/projects/{project}', [AdminProjectController::class, 'show'])
    ->name('api.admin.projects.show');
Route::post('/admin/projects/{project}/enable', [AdminProjectController::class, 'enable'])
    ->name('api.admin.projects.enable');
Route::post('/admin/projects/{project}/disable', [AdminProjectController::class, 'disable'])
    ->name('api.admin.projects.disable');
```

Add the import at the top of `routes/api.php`:
```php
use App\Http\Controllers\Api\AdminProjectController;
```

**Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/AdminProjectApiTest.php`
Expected: PASS

**Step 7: Commit**

```bash
git add app/Http/Controllers/Api/AdminProjectController.php app/Http/Resources/AdminProjectResource.php routes/api.php tests/Feature/AdminProjectApiTest.php
git commit --no-gpg-sign -m "T88.3: Create AdminProjectController with enable/disable API endpoints"
```

---

### Task 4: Create admin Pinia store

**Files:**
- Create: `resources/js/stores/admin.js`
- Test: `resources/js/stores/admin.test.js`

**Context:** The admin store manages the project list state, handles API calls for index/enable/disable, and tracks loading/error state. Follow the existing pattern from `dashboard.js` store.

**Step 1: Write the failing test**

Create `resources/js/stores/admin.test.js`:

```javascript
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import axios from 'axios';
import { useAdminStore } from './admin';

vi.mock('axios');

describe('admin store', () => {
    let admin;

    beforeEach(() => {
        setActivePinia(createPinia());
        admin = useAdminStore();
        vi.clearAllMocks();
    });

    describe('fetchProjects', () => {
        it('fetches and stores project list', async () => {
            const projects = [
                { id: 1, name: 'Project A', enabled: true, webhook_configured: true },
                { id: 2, name: 'Project B', enabled: false, webhook_configured: false },
            ];
            axios.get.mockResolvedValue({ data: { data: projects } });

            await admin.fetchProjects();

            expect(admin.projects).toEqual(projects);
            expect(admin.loading).toBe(false);
            expect(axios.get).toHaveBeenCalledWith('/api/v1/admin/projects');
        });

        it('sets loading state during fetch', async () => {
            let resolvePromise;
            axios.get.mockReturnValue(new Promise((resolve) => { resolvePromise = resolve; }));

            const fetchPromise = admin.fetchProjects();
            expect(admin.loading).toBe(true);

            resolvePromise({ data: { data: [] } });
            await fetchPromise;
            expect(admin.loading).toBe(false);
        });

        it('handles fetch error', async () => {
            axios.get.mockRejectedValue(new Error('Network error'));

            await admin.fetchProjects();

            expect(admin.error).toBe('Failed to load projects.');
            expect(admin.loading).toBe(false);
        });
    });

    describe('enableProject', () => {
        it('calls enable API and updates project in list', async () => {
            admin.projects = [
                { id: 1, name: 'Project A', enabled: false },
            ];

            const updatedProject = { id: 1, name: 'Project A', enabled: true, webhook_configured: true };
            axios.post.mockResolvedValue({
                data: { success: true, warnings: [], data: updatedProject },
            });

            const result = await admin.enableProject(1);

            expect(result.success).toBe(true);
            expect(admin.projects[0].enabled).toBe(true);
            expect(axios.post).toHaveBeenCalledWith('/api/v1/admin/projects/1/enable');
        });

        it('returns error details on failure', async () => {
            admin.projects = [{ id: 1, enabled: false }];

            axios.post.mockRejectedValue({
                response: { data: { success: false, error: 'Bot not a member' } },
            });

            const result = await admin.enableProject(1);

            expect(result.success).toBe(false);
            expect(result.error).toBe('Bot not a member');
        });
    });

    describe('disableProject', () => {
        it('calls disable API and updates project in list', async () => {
            admin.projects = [
                { id: 1, name: 'Project A', enabled: true },
            ];

            const updatedProject = { id: 1, name: 'Project A', enabled: false, webhook_configured: false };
            axios.post.mockResolvedValue({
                data: { success: true, data: updatedProject },
            });

            const result = await admin.disableProject(1);

            expect(result.success).toBe(true);
            expect(admin.projects[0].enabled).toBe(false);
            expect(axios.post).toHaveBeenCalledWith('/api/v1/admin/projects/1/disable');
        });
    });
});
```

**Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/stores/admin.test.js`
Expected: FAIL — module not found

**Step 3: Implement the admin store**

Create `resources/js/stores/admin.js`:

```javascript
import { defineStore } from 'pinia';
import { ref } from 'vue';
import axios from 'axios';

export const useAdminStore = defineStore('admin', () => {
    const projects = ref([]);
    const loading = ref(false);
    const error = ref(null);

    async function fetchProjects() {
        loading.value = true;
        error.value = null;
        try {
            const { data } = await axios.get('/api/v1/admin/projects');
            projects.value = data.data;
        } catch (e) {
            error.value = 'Failed to load projects.';
        } finally {
            loading.value = false;
        }
    }

    async function enableProject(projectId) {
        try {
            const { data } = await axios.post(`/api/v1/admin/projects/${projectId}/enable`);
            if (data.success && data.data) {
                const idx = projects.value.findIndex((p) => p.id === projectId);
                if (idx !== -1) {
                    projects.value[idx] = data.data;
                }
            }
            return { success: true, warnings: data.warnings || [] };
        } catch (e) {
            const errorMsg = e.response?.data?.error || 'Failed to enable project.';
            return { success: false, error: errorMsg };
        }
    }

    async function disableProject(projectId) {
        try {
            const { data } = await axios.post(`/api/v1/admin/projects/${projectId}/disable`);
            if (data.success && data.data) {
                const idx = projects.value.findIndex((p) => p.id === projectId);
                if (idx !== -1) {
                    projects.value[idx] = data.data;
                }
            }
            return { success: true };
        } catch (e) {
            const errorMsg = e.response?.data?.error || 'Failed to disable project.';
            return { success: false, error: errorMsg };
        }
    }

    return {
        projects,
        loading,
        error,
        fetchProjects,
        enableProject,
        disableProject,
    };
});
```

**Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/stores/admin.test.js`
Expected: PASS

**Step 5: Commit**

```bash
git add resources/js/stores/admin.js resources/js/stores/admin.test.js
git commit --no-gpg-sign -m "T88.4: Create admin Pinia store for project management"
```

---

### Task 5: Build AdminPage Vue component

**Files:**
- Modify: `resources/js/pages/AdminPage.vue` (replace placeholder)
- Create: `resources/js/components/AdminProjectList.vue`
- Test: `resources/js/pages/AdminPage.test.js`

**Context:** The admin page shows a list of all projects with their status (enabled/disabled, webhook health, bot status, recent activity, active conversations). Each project has enable/disable actions. The page is accessible only to users with `admin.global_config` permission. Use the existing tab UI pattern from `DashboardPage.vue`. For this task, only the "Projects" tab is implemented (T89 adds Roles, T90 adds Settings).

**Step 1: Write the failing tests**

Create `resources/js/pages/AdminPage.test.js`:

```javascript
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import AdminPage from './AdminPage.vue';
import { useAuthStore } from '@/stores/auth';
import { useAdminStore } from '@/stores/admin';

vi.mock('axios');

describe('AdminPage', () => {
    let pinia;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);

        const auth = useAuthStore();
        auth.setUser({
            id: 1,
            name: 'Admin',
            projects: [
                { id: 1, name: 'Test Project', permissions: ['admin.global_config'] },
            ],
        });
    });

    it('renders admin page heading', () => {
        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        expect(wrapper.text()).toContain('Admin');
    });

    it('shows Projects tab', () => {
        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="admin-tab-projects"]').exists()).toBe(true);
    });

    it('fetches projects on mount', () => {
        const admin = useAdminStore();
        const fetchSpy = vi.spyOn(admin, 'fetchProjects').mockResolvedValue();

        mount(AdminPage, { global: { plugins: [pinia] } });

        expect(fetchSpy).toHaveBeenCalled();
    });

    it('displays project list', async () => {
        const admin = useAdminStore();
        admin.projects = [
            { id: 1, name: 'Alpha', slug: 'alpha', enabled: true, webhook_configured: true, recent_task_count: 5, active_conversation_count: 2 },
            { id: 2, name: 'Beta', slug: 'beta', enabled: false, webhook_configured: false, recent_task_count: 0, active_conversation_count: 0 },
        ];
        vi.spyOn(admin, 'fetchProjects').mockResolvedValue();

        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Alpha');
        expect(wrapper.text()).toContain('Beta');
    });

    it('shows enabled badge for enabled projects', async () => {
        const admin = useAdminStore();
        admin.projects = [
            { id: 1, name: 'Alpha', slug: 'alpha', enabled: true, webhook_configured: true, recent_task_count: 0, active_conversation_count: 0 },
        ];
        vi.spyOn(admin, 'fetchProjects').mockResolvedValue();

        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="project-status-1"]').text()).toContain('Enabled');
    });

    it('shows disabled badge for disabled projects', async () => {
        const admin = useAdminStore();
        admin.projects = [
            { id: 1, name: 'Alpha', slug: 'alpha', enabled: false, webhook_configured: false, recent_task_count: 0, active_conversation_count: 0 },
        ];
        vi.spyOn(admin, 'fetchProjects').mockResolvedValue();

        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="project-status-1"]').text()).toContain('Disabled');
    });
});
```

**Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/pages/AdminPage.test.js`
Expected: FAIL — component is still a placeholder

**Step 3: Create AdminProjectList component**

Create `resources/js/components/AdminProjectList.vue`:

```vue
<script setup>
import { ref } from 'vue';
import { useAdminStore } from '@/stores/admin';

const admin = useAdminStore();
const actionInProgress = ref(null); // project id being acted on
const actionError = ref(null);
const actionWarnings = ref([]);

async function handleEnable(projectId) {
    actionInProgress.value = projectId;
    actionError.value = null;
    actionWarnings.value = [];

    const result = await admin.enableProject(projectId);

    if (!result.success) {
        actionError.value = result.error;
    } else if (result.warnings?.length) {
        actionWarnings.value = result.warnings;
    }

    actionInProgress.value = null;
}

async function handleDisable(projectId) {
    if (!confirm('Disable this project? The webhook will be removed, but all data will be preserved.')) {
        return;
    }

    actionInProgress.value = projectId;
    actionError.value = null;
    actionWarnings.value = [];

    const result = await admin.disableProject(projectId);

    if (!result.success) {
        actionError.value = result.error;
    }

    actionInProgress.value = null;
}
</script>

<template>
  <div>
    <!-- Error banner -->
    <div v-if="actionError" class="mb-4 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400" data-testid="action-error">
      {{ actionError }}
    </div>

    <!-- Warnings banner -->
    <div v-if="actionWarnings.length" class="mb-4 rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-800 dark:border-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400" data-testid="action-warnings">
      <p v-for="(warning, i) in actionWarnings" :key="i">{{ warning }}</p>
    </div>

    <!-- Loading state -->
    <div v-if="admin.loading" class="py-8 text-center text-zinc-500">
      Loading projects...
    </div>

    <!-- Empty state -->
    <div v-else-if="admin.projects.length === 0" class="py-8 text-center text-zinc-500">
      No projects found. Projects appear here after users log in via GitLab OAuth.
    </div>

    <!-- Project list -->
    <div v-else class="space-y-3">
      <div
        v-for="project in admin.projects"
        :key="project.id"
        class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700"
        :data-testid="`project-row-${project.id}`"
      >
        <div class="flex items-center justify-between">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-3">
              <h3 class="text-sm font-medium truncate">{{ project.name }}</h3>
              <span
                :data-testid="`project-status-${project.id}`"
                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                :class="project.enabled
                  ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'
                  : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400'"
              >
                {{ project.enabled ? 'Enabled' : 'Disabled' }}
              </span>
              <span
                v-if="project.enabled && project.webhook_configured"
                class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-400"
              >
                Webhook active
              </span>
            </div>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
              GitLab #{{ project.gitlab_project_id }}
              <span v-if="project.slug"> &middot; {{ project.slug }}</span>
            </p>
            <div v-if="project.enabled" class="mt-2 flex gap-4 text-xs text-zinc-500 dark:text-zinc-400">
              <span>{{ project.recent_task_count }} tasks (7d)</span>
              <span>{{ project.active_conversation_count }} active conversations</span>
            </div>
          </div>

          <div class="ml-4 flex-shrink-0">
            <button
              v-if="!project.enabled"
              :disabled="actionInProgress === project.id"
              class="rounded-lg bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
              :data-testid="`enable-btn-${project.id}`"
              @click="handleEnable(project.id)"
            >
              {{ actionInProgress === project.id ? 'Enabling...' : 'Enable' }}
            </button>
            <button
              v-else
              :disabled="actionInProgress === project.id"
              class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800 disabled:opacity-50"
              :data-testid="`disable-btn-${project.id}`"
              @click="handleDisable(project.id)"
            >
              {{ actionInProgress === project.id ? 'Disabling...' : 'Disable' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
```

**Step 4: Update AdminPage.vue**

Replace the existing placeholder `resources/js/pages/AdminPage.vue`:

```vue
<script setup>
import { ref, onMounted } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useAdminStore } from '@/stores/admin';
import AdminProjectList from '@/components/AdminProjectList.vue';

const auth = useAuthStore();
const admin = useAdminStore();

const activeTab = ref('projects');

const tabs = [
    { key: 'projects', label: 'Projects' },
];

onMounted(() => {
    admin.fetchProjects();
});
</script>

<template>
  <div>
    <h1 class="text-2xl font-semibold mb-6">Admin</h1>

    <!-- Tabs -->
    <div class="flex items-center gap-2 mb-6">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        :data-testid="`admin-tab-${tab.key}`"
        class="px-4 py-2 text-sm font-medium rounded-lg border transition-colors"
        :class="activeTab === tab.key
          ? 'border-zinc-500 bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300'
          : 'border-zinc-300 dark:border-zinc-700 text-zinc-500 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800'"
        @click="activeTab = tab.key"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- Tab content -->
    <AdminProjectList v-if="activeTab === 'projects'" />
  </div>
</template>
```

**Step 5: Run test to verify it passes**

Run: `npx vitest run resources/js/pages/AdminPage.test.js`
Expected: PASS

**Step 6: Commit**

```bash
git add resources/js/pages/AdminPage.vue resources/js/components/AdminProjectList.vue resources/js/pages/AdminPage.test.js
git commit --no-gpg-sign -m "T88.5: Build AdminPage with project list, enable/disable actions"
```

---

### Task 6: Integration test — enable flow end-to-end

**Files:**
- Create: `tests/Feature/AdminProjectEnableFlowTest.php`

**Context:** This test verifies the full enablement flow from the M5 verification spec: "Enable project → GitLab API creates webhook with correct URL/secret → `ai::*` labels created. DB updated." Also tests the label pre-creation verification: "Enable project → 6 `ai::*` labels created in GitLab with correct names, colors, descriptions. Labels already exist → no duplicates."

**Step 1: Write the integration test**

Create `tests/Feature/AdminProjectEnableFlowTest.php`:

```php
<?php

use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.gitlab.host' => 'https://gitlab.example.com']);
    config(['services.gitlab.bot_token' => 'test-bot-token']);
    config(['services.gitlab.vunnix_project_id' => 100]);
    config(['app.url' => 'https://vunnix.example.com']);
});

function setupAdmin(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'admin']);
    $perm = Permission::firstOrCreate(
        ['name' => 'admin.global_config'],
        ['description' => 'Admin', 'group' => 'admin']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

it('creates webhook with correct URL, secret, and events on enable', function () {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => false,
    ]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $admin = setupAdmin($project);

    Http::fake([
        'gitlab.example.com/api/v4/user' => Http::response(['id' => 99]),
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response([
            'id' => 99, 'access_level' => 40,
        ]),
        'gitlab.example.com/api/v4/projects/100' => Http::response([
            'id' => 100, 'visibility' => 'internal',
        ]),
        'gitlab.example.com/api/v4/projects/42/hooks' => Http::response([
            'id' => 777,
        ], 201),
        'gitlab.example.com/api/v4/projects/42/labels' => Http::response([
            'id' => 1,
        ], 201),
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/projects/{$project->id}/enable")
        ->assertOk();

    // Verify webhook creation request
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/hooks') || $request->method() !== 'POST') {
            return false;
        }

        $data = $request->data();
        return str_contains($data['url'] ?? '', '/api/webhook')
            && ! empty($data['token'])
            && ($data['merge_requests_events'] ?? false) === true
            && ($data['note_events'] ?? false) === true
            && ($data['issues_events'] ?? false) === true
            && ($data['push_events'] ?? false) === true;
    });

    // Verify DB state
    $project->refresh();
    expect($project->enabled)->toBeTrue();
    expect($project->webhook_configured)->toBeTrue();
    expect($project->webhook_id)->toBe(777);
    expect($project->projectConfig->webhook_secret)->not->toBeNull();
});

it('creates all 6 ai:: labels with correct names and colors', function () {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => false,
    ]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $admin = setupAdmin($project);

    Http::fake([
        'gitlab.example.com/api/v4/user' => Http::response(['id' => 99]),
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response([
            'id' => 99, 'access_level' => 40,
        ]),
        'gitlab.example.com/api/v4/projects/100' => Http::response([
            'id' => 100, 'visibility' => 'internal',
        ]),
        'gitlab.example.com/api/v4/projects/42/hooks' => Http::response([
            'id' => 555,
        ], 201),
        'gitlab.example.com/api/v4/projects/42/labels' => Http::response([
            'id' => 1,
        ], 201),
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/projects/{$project->id}/enable")
        ->assertOk();

    $expectedLabels = [
        'ai::reviewed', 'ai::risk-high', 'ai::risk-medium',
        'ai::risk-low', 'ai::security', 'ai::created',
    ];

    $labelRequests = collect(Http::recorded())
        ->filter(fn ($pair) =>
            str_contains($pair[0]->url(), '/labels') &&
            $pair[0]->method() === 'POST'
        )
        ->map(fn ($pair) => $pair[0]->data()['name']);

    foreach ($expectedLabels as $labelName) {
        expect($labelRequests->contains($labelName))->toBeTrue("Expected label {$labelName} to be created");
    }

    expect($labelRequests)->toHaveCount(6);
});

it('handles already-existing labels without error', function () {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => false,
    ]);
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $admin = setupAdmin($project);

    Http::fake([
        'gitlab.example.com/api/v4/user' => Http::response(['id' => 99]),
        'gitlab.example.com/api/v4/projects/42/members/all/99' => Http::response([
            'id' => 99, 'access_level' => 40,
        ]),
        'gitlab.example.com/api/v4/projects/100' => Http::response([
            'id' => 100, 'visibility' => 'internal',
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

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/projects/{$project->id}/enable")
        ->assertOk()
        ->assertJsonPath('success', true);
});

it('removes webhook and preserves data on disable', function () {
    $project = Project::factory()->create([
        'gitlab_project_id' => 42,
        'enabled' => true,
        'webhook_configured' => true,
        'webhook_id' => 555,
    ]);
    $admin = setupAdmin($project);

    Http::fake([
        'gitlab.example.com/api/v4/projects/42/hooks/555' => Http::response(null, 204),
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/projects/{$project->id}/disable")
        ->assertOk();

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/hooks/555') && $req->method() === 'DELETE'
    );

    $project->refresh();
    expect($project->enabled)->toBeFalse();
    expect($project->webhook_id)->toBeNull();
    // Project still exists (data preserved — D60)
    expect(Project::find($project->id))->not->toBeNull();
});
```

**Step 2: Run tests**

Run: `php artisan test tests/Feature/AdminProjectEnableFlowTest.php`
Expected: PASS (all implementation from Tasks 1-3 is in place)

**Step 3: Commit**

```bash
git add tests/Feature/AdminProjectEnableFlowTest.php
git commit --no-gpg-sign -m "T88.6: Add integration tests for enable/disable flow"
```

---

### Task 7: Run full verification

**Step 1: Run all PHP tests**

Run: `php artisan test --parallel`
Expected: PASS (all existing + new tests)

**Step 2: Run all Vue tests**

Run: `npx vitest run`
Expected: PASS (all existing + new tests)

**Step 3: Run milestone structural verification**

Run: `python3 verify/verify_m5.py`

Note: This script may not exist yet (first M5 task). If it doesn't exist, create a minimal one or skip this step. The key verification is:
- PHP and Vue tests pass
- Admin API endpoints respond correctly
- Enable/disable flow works with all GitLab API interactions

**Step 4: Final commit (if verification script needed updating)**

If any fixes were needed, commit them.

---

### Task 8: Update progress.md and commit

**Step 1: Update `progress.md`**

- Check `[x] T88` box
- Update milestone count: `M5 — Admin & Configuration (1/18)`
- Bold the next task: `**T89:** Admin page — role management`
- Update summary section

**Step 2: Clear `handoff.md`**

Reset to empty template.

**Step 3: Final commit**

```bash
git add progress.md handoff.md
git commit --no-gpg-sign -m "T88: Complete admin page — project management"
```

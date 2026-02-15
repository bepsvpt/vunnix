# T91: Per-Project Configuration Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a per-project configuration system where project-level settings override global defaults, with caching, a resolution service, API endpoints, and an admin UI for editing per-project overrides.

**Architecture:** Extend the existing `ProjectConfig` model (which already has a `settings` JSONB column) with a `ProjectConfigService` that resolves settings via: project override → global default → hardcoded default. The admin UI adds a "Configure" button to each project row in the Projects tab, navigating to a project-specific settings form that shows inherited vs overridden values.

**Tech Stack:** Laravel 11 (Service, Controller, FormRequest, Resource), Pest tests, Vue 3 (`<script setup>`) + Pinia + Vitest

---

### Task 1: Add `ProjectConfigService` with fallback resolution and caching

**Files:**
- Create: `app/Services/ProjectConfigService.php`
- Test: `tests/Unit/Services/ProjectConfigServiceTest.php`

**Step 1: Write the failing test**

```php
<?php
// tests/Unit/Services/ProjectConfigServiceTest.php

use App\Models\GlobalSetting;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Services\ProjectConfigService;
use Illuminate\Support\Facades\Cache;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

// Create the agent_conversations table if needed (same beforeEach as other tests)
beforeEach(function () {
    if (! \Illuminate\Support\Facades\Schema::hasTable('agent_conversations')) {
        \Illuminate\Support\Facades\Schema::create('agent_conversations', function ($table) {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('title');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });
    } elseif (! \Illuminate\Support\Facades\Schema::hasColumn('agent_conversations', 'project_id')) {
        \Illuminate\Support\Facades\Schema::table('agent_conversations', function ($table) {
            $table->unsignedBigInteger('project_id')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
    }

    if (! \Illuminate\Support\Facades\Schema::hasTable('agent_conversation_messages')) {
        \Illuminate\Support\Facades\Schema::create('agent_conversation_messages', function ($table) {
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

it('returns project override when set', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet'],
    ]);

    $service = new ProjectConfigService();
    expect($service->get($project, 'ai_model'))->toBe('sonnet');
});

it('falls back to global setting when no project override', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);
    GlobalSetting::set('ai_model', 'haiku', 'string');

    $service = new ProjectConfigService();
    expect($service->get($project, 'ai_model'))->toBe('haiku');
});

it('falls back to hardcoded default when neither project nor global set', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);

    $service = new ProjectConfigService();
    // GlobalSetting::defaults() has ai_model => 'opus'
    expect($service->get($project, 'ai_model'))->toBe('opus');
});

it('returns explicit default when key has no value anywhere', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);

    $service = new ProjectConfigService();
    expect($service->get($project, 'nonexistent_key', 'fallback'))->toBe('fallback');
});

it('supports dot-notation for nested settings', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['code_review' => ['auto_review' => false]],
    ]);

    $service = new ProjectConfigService();
    expect($service->get($project, 'code_review.auto_review'))->toBe(false);
});

it('caches resolved settings per project', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet'],
    ]);

    $service = new ProjectConfigService();
    $service->get($project, 'ai_model');

    // Second call should use cache
    expect(Cache::has("project_config:{$project->id}"))->toBeTrue();
});

it('invalidates cache when settings are updated', function () {
    $project = Project::factory()->create();
    $config = ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet'],
    ]);

    $service = new ProjectConfigService();
    $service->get($project, 'ai_model'); // populate cache

    $service->set($project, 'ai_model', 'haiku');

    expect($service->get($project, 'ai_model'))->toBe('haiku');
});

it('returns all effective settings for a project', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet', 'timeout_minutes' => 20],
    ]);
    GlobalSetting::set('ai_language', 'ja', 'string');

    $service = new ProjectConfigService();
    $effective = $service->allEffective($project);

    expect($effective['ai_model'])->toEqual(['value' => 'sonnet', 'source' => 'project']);
    expect($effective['ai_language'])->toEqual(['value' => 'ja', 'source' => 'global']);
    expect($effective['timeout_minutes'])->toEqual(['value' => 20, 'source' => 'project']);
    expect($effective['max_tokens'])->toEqual(['value' => 8192, 'source' => 'default']);
});

it('removes a project override via set with null', function () {
    $project = Project::factory()->create();
    $config = ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet'],
    ]);

    $service = new ProjectConfigService();
    $service->set($project, 'ai_model', null);

    // Should fall back to global/default
    expect($service->get($project, 'ai_model'))->toBe('opus');
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ProjectConfigServiceTest`
Expected: FAIL (class not found)

**Step 3: Write the implementation**

```php
<?php
// app/Services/ProjectConfigService.php

namespace App\Services;

use App\Models\GlobalSetting;
use App\Models\Project;
use App\Models\ProjectConfig;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class ProjectConfigService
{
    private const CACHE_PREFIX = 'project_config:';
    private const CACHE_TTL_MINUTES = 60;

    /**
     * Configurable setting keys with their types.
     * Matches the .vunnix.toml schema from §3.7.
     */
    public static function settingKeys(): array
    {
        return [
            'ai_model' => 'string',
            'ai_language' => 'string',
            'timeout_minutes' => 'integer',
            'max_tokens' => 'integer',
            'code_review.auto_review' => 'boolean',
            'code_review.auto_review_on_push' => 'boolean',
            'code_review.severity_threshold' => 'string',
            'feature_dev.enabled' => 'boolean',
            'feature_dev.branch_prefix' => 'string',
            'feature_dev.auto_create_mr' => 'boolean',
            'conversation.enabled' => 'boolean',
            'conversation.max_history_messages' => 'integer',
            'conversation.tool_use_gitlab' => 'boolean',
            'ui_adjustment.dev_server_command' => 'string',
            'ui_adjustment.screenshot_base_url' => 'string',
            'ui_adjustment.screenshot_wait_ms' => 'integer',
            'labels.auto_label' => 'boolean',
            'labels.risk_labels' => 'boolean',
        ];
    }

    /**
     * Get a resolved config value: project override → global → default.
     */
    public function get(Project $project, string $key, mixed $default = null): mixed
    {
        $settings = $this->getProjectSettings($project);

        $value = Arr::get($settings, $key);
        if ($value !== null) {
            return $value;
        }

        // Fall back to global setting (top-level keys only)
        $topKey = explode('.', $key)[0];
        if ($topKey === $key) {
            return GlobalSetting::get($key, $default);
        }

        return $default;
    }

    /**
     * Set a project-level override. Pass null to remove the override.
     */
    public function set(Project $project, string $key, mixed $value): void
    {
        $config = $project->projectConfig;
        if (! $config) {
            $config = $project->projectConfig()->create(['settings' => []]);
        }

        $settings = $config->settings ?? [];

        if ($value === null) {
            Arr::forget($settings, $key);
        } else {
            Arr::set($settings, $key, $value);
        }

        $config->update(['settings' => $settings]);

        Cache::forget(self::CACHE_PREFIX . $project->id);
    }

    /**
     * Bulk-update project settings from a flat key → value map.
     * Keys with null values are removed (reset to global/default).
     */
    public function bulkSet(Project $project, array $overrides): void
    {
        $config = $project->projectConfig;
        if (! $config) {
            $config = $project->projectConfig()->create(['settings' => []]);
        }

        $settings = $config->settings ?? [];

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                Arr::forget($settings, $key);
            } else {
                Arr::set($settings, $key, $value);
            }
        }

        $config->update(['settings' => $settings]);

        Cache::forget(self::CACHE_PREFIX . $project->id);
    }

    /**
     * Get all effective settings for a project with source indicators.
     * Returns: ['key' => ['value' => mixed, 'source' => 'project'|'global'|'default']]
     */
    public function allEffective(Project $project): array
    {
        $projectSettings = $this->getProjectSettings($project);
        $globalDefaults = GlobalSetting::defaults();
        $result = [];

        // Start with hardcoded defaults
        foreach ($globalDefaults as $key => $value) {
            $result[$key] = ['value' => $value, 'source' => 'default'];
        }

        // Layer global DB settings on top
        foreach (array_keys($globalDefaults) as $key) {
            $globalValue = GlobalSetting::get($key);
            if ($globalValue !== ($globalDefaults[$key] ?? null)) {
                $result[$key] = ['value' => $globalValue, 'source' => 'global'];
            }
        }

        // Layer project overrides on top
        foreach (Arr::dot($projectSettings) as $key => $value) {
            $result[$key] = ['value' => $value, 'source' => 'project'];
        }

        return $result;
    }

    /**
     * Get raw project settings from cache or DB.
     */
    private function getProjectSettings(Project $project): array
    {
        return Cache::remember(
            self::CACHE_PREFIX . $project->id,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            function () use ($project) {
                return $project->projectConfig?->settings ?? [];
            }
        );
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=ProjectConfigServiceTest`
Expected: All PASS

**Step 5: Commit**

```bash
git add app/Services/ProjectConfigService.php tests/Unit/Services/ProjectConfigServiceTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T91.1: Add ProjectConfigService with fallback resolution and caching

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Add API endpoints for per-project config CRUD

**Files:**
- Create: `app/Http/Controllers/Api/AdminProjectConfigController.php`
- Create: `app/Http/Requests/Admin/UpdateProjectConfigRequest.php`
- Create: `app/Http/Resources/ProjectConfigResource.php`
- Modify: `routes/api.php` (add 2 routes after line 105)
- Test: `tests/Feature/AdminProjectConfigApiTest.php`

**Step 1: Write the failing test**

```php
<?php
// tests/Feature/AdminProjectConfigApiTest.php

use App\Models\GlobalSetting;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
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
});

function createProjectConfigAdmin(Project $project): User
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

function createProjectConfigNonAdmin(Project $project): User
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

// ─── GET /admin/projects/{project}/config ────────────────────

it('returns effective config for a project', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet', 'timeout_minutes' => 20],
    ]);
    $admin = createProjectConfigAdmin($project);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/admin/projects/{$project->id}/config");

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'settings',
                'effective',
                'setting_keys',
            ],
        ]);

    // Project overrides should appear with source: 'project'
    $effective = $response->json('data.effective');
    expect($effective['ai_model']['source'])->toBe('project');
    expect($effective['ai_model']['value'])->toBe('sonnet');
});

it('returns global defaults when no project overrides', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);
    $admin = createProjectConfigAdmin($project);

    $response = $this->actingAs($admin)
        ->getJson("/api/v1/admin/projects/{$project->id}/config");

    $response->assertOk();

    $effective = $response->json('data.effective');
    expect($effective['ai_model']['source'])->toBe('default');
    expect($effective['ai_model']['value'])->toBe('opus');
});

it('rejects config read for non-admin', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $user = createProjectConfigNonAdmin($project);

    $this->actingAs($user)
        ->getJson("/api/v1/admin/projects/{$project->id}/config")
        ->assertForbidden();
});

it('rejects config read for unauthenticated user', function () {
    $project = Project::factory()->create();

    $this->getJson("/api/v1/admin/projects/{$project->id}/config")
        ->assertUnauthorized();
});

// ─── PUT /admin/projects/{project}/config ────────────────────

it('updates project config overrides', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);
    $admin = createProjectConfigAdmin($project);

    $response = $this->actingAs($admin)
        ->putJson("/api/v1/admin/projects/{$project->id}/config", [
            'settings' => [
                'ai_model' => 'sonnet',
                'timeout_minutes' => 20,
            ],
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    $config = $project->projectConfig->fresh();
    expect($config->settings['ai_model'])->toBe('sonnet');
    expect($config->settings['timeout_minutes'])->toBe(20);
});

it('removes overrides when value is null', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet'],
    ]);
    $admin = createProjectConfigAdmin($project);

    $response = $this->actingAs($admin)
        ->putJson("/api/v1/admin/projects/{$project->id}/config", [
            'settings' => ['ai_model' => null],
        ]);

    $response->assertOk();

    $config = $project->projectConfig->fresh();
    expect($config->settings)->not->toHaveKey('ai_model');
});

it('returns updated effective config after update', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);
    $admin = createProjectConfigAdmin($project);

    $response = $this->actingAs($admin)
        ->putJson("/api/v1/admin/projects/{$project->id}/config", [
            'settings' => ['ai_model' => 'sonnet'],
        ]);

    $response->assertOk()
        ->assertJsonPath('data.effective.ai_model.value', 'sonnet')
        ->assertJsonPath('data.effective.ai_model.source', 'project');
});

it('creates ProjectConfig if it does not exist', function () {
    $project = Project::factory()->create();
    $admin = createProjectConfigAdmin($project);

    $response = $this->actingAs($admin)
        ->putJson("/api/v1/admin/projects/{$project->id}/config", [
            'settings' => ['ai_model' => 'haiku'],
        ]);

    $response->assertOk();

    $config = $project->projectConfig()->first();
    expect($config)->not->toBeNull();
    expect($config->settings['ai_model'])->toBe('haiku');
});

it('rejects config update for non-admin', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create(['project_id' => $project->id]);
    $user = createProjectConfigNonAdmin($project);

    $this->actingAs($user)
        ->putJson("/api/v1/admin/projects/{$project->id}/config", [
            'settings' => ['ai_model' => 'sonnet'],
        ])
        ->assertForbidden();
});

it('validates settings is required', function () {
    $project = Project::factory()->create();
    $admin = createProjectConfigAdmin($project);

    $this->actingAs($admin)
        ->putJson("/api/v1/admin/projects/{$project->id}/config", [])
        ->assertUnprocessable();
});

it('validates settings must be an array', function () {
    $project = Project::factory()->create();
    $admin = createProjectConfigAdmin($project);

    $this->actingAs($admin)
        ->putJson("/api/v1/admin/projects/{$project->id}/config", [
            'settings' => 'not-an-array',
        ])
        ->assertUnprocessable();
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AdminProjectConfigApiTest`
Expected: FAIL (route/controller not found)

**Step 3: Write the implementation**

Create `app/Http/Requests/Admin/UpdateProjectConfigRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller handles authorization
    }

    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
        ];
    }
}
```

Create `app/Http/Resources/ProjectConfigResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Services\ProjectConfigService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $service = app(ProjectConfigService::class);

        return [
            'settings' => $this->settings ?? [],
            'effective' => $service->allEffective($this->project),
            'setting_keys' => ProjectConfigService::settingKeys(),
        ];
    }
}
```

Create `app/Http/Controllers/Api/AdminProjectConfigController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProjectConfigRequest;
use App\Http\Resources\ProjectConfigResource;
use App\Models\Project;
use App\Services\ProjectConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminProjectConfigController extends Controller
{
    public function __construct(
        private readonly ProjectConfigService $configService,
    ) {}

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorizeAdmin($request);

        $config = $project->projectConfig;
        if (! $config) {
            $config = $project->projectConfig()->create(['settings' => []]);
        }

        return response()->json([
            'data' => new ProjectConfigResource($config),
        ]);
    }

    public function update(UpdateProjectConfigRequest $request, Project $project): JsonResponse
    {
        $this->authorizeAdmin($request);

        $this->configService->bulkSet($project, $request->validated()['settings']);

        $config = $project->projectConfig->fresh();

        return response()->json([
            'success' => true,
            'data' => new ProjectConfigResource($config),
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

Add routes to `routes/api.php` (after the existing admin/projects routes, around line 105):

```php
        // Admin per-project config (T91)
        Route::get('/admin/projects/{project}/config', [AdminProjectConfigController::class, 'show'])
            ->name('api.admin.projects.config.show');
        Route::put('/admin/projects/{project}/config', [AdminProjectConfigController::class, 'update'])
            ->name('api.admin.projects.config.update');
```

Don't forget to add the use-import at the top of `routes/api.php`:

```php
use App\Http\Controllers\Api\AdminProjectConfigController;
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=AdminProjectConfigApiTest`
Expected: All PASS

**Step 5: Commit**

```bash
git add app/Http/Controllers/Api/AdminProjectConfigController.php \
  app/Http/Requests/Admin/UpdateProjectConfigRequest.php \
  app/Http/Resources/ProjectConfigResource.php \
  routes/api.php \
  tests/Feature/AdminProjectConfigApiTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T91.2: Add API endpoints for per-project config CRUD

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Add per-project config state and methods to Pinia admin store

**Files:**
- Modify: `resources/js/stores/admin.js` (add project config methods)
- Test: `resources/js/stores/admin.test.js` (existing file — add new tests; create if doesn't exist)

**Step 1: Write the failing test**

Add to or create `resources/js/stores/admin.test.js`:

```js
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useAdminStore } from '@/stores/admin';
import axios from 'axios';

vi.mock('axios');

describe('admin store - project config (T91)', () => {
    let admin;

    beforeEach(() => {
        setActivePinia(createPinia());
        admin = useAdminStore();
        vi.resetAllMocks();
    });

    it('fetchProjectConfig populates projectConfig state', async () => {
        axios.get.mockResolvedValue({
            data: {
                data: {
                    settings: { ai_model: 'sonnet' },
                    effective: {
                        ai_model: { value: 'sonnet', source: 'project' },
                        ai_language: { value: 'en', source: 'default' },
                    },
                    setting_keys: { ai_model: 'string' },
                },
            },
        });

        await admin.fetchProjectConfig(42);

        expect(axios.get).toHaveBeenCalledWith('/api/v1/admin/projects/42/config');
        expect(admin.projectConfig).toEqual({
            settings: { ai_model: 'sonnet' },
            effective: {
                ai_model: { value: 'sonnet', source: 'project' },
                ai_language: { value: 'en', source: 'default' },
            },
            setting_keys: { ai_model: 'string' },
        });
    });

    it('updateProjectConfig sends PUT and updates state', async () => {
        axios.put.mockResolvedValue({
            data: {
                success: true,
                data: {
                    settings: { ai_model: 'haiku' },
                    effective: { ai_model: { value: 'haiku', source: 'project' } },
                    setting_keys: {},
                },
            },
        });

        const result = await admin.updateProjectConfig(42, { ai_model: 'haiku' });

        expect(axios.put).toHaveBeenCalledWith('/api/v1/admin/projects/42/config', {
            settings: { ai_model: 'haiku' },
        });
        expect(result.success).toBe(true);
        expect(admin.projectConfig.settings).toEqual({ ai_model: 'haiku' });
    });

    it('fetchProjectConfig sets loading state', async () => {
        let resolvePromise;
        axios.get.mockReturnValue(new Promise(r => { resolvePromise = r; }));

        const promise = admin.fetchProjectConfig(42);
        expect(admin.projectConfigLoading).toBe(true);

        resolvePromise({ data: { data: { settings: {}, effective: {}, setting_keys: {} } } });
        await promise;

        expect(admin.projectConfigLoading).toBe(false);
    });

    it('fetchProjectConfig sets error on failure', async () => {
        axios.get.mockRejectedValue(new Error('Network error'));

        await admin.fetchProjectConfig(42);

        expect(admin.projectConfigError).toBe('Failed to load project configuration.');
    });

    it('updateProjectConfig returns error on failure', async () => {
        axios.put.mockRejectedValue({
            response: { data: { error: 'Validation failed' } },
        });

        const result = await admin.updateProjectConfig(42, { ai_model: 'bad' });

        expect(result.success).toBe(false);
        expect(result.error).toBe('Validation failed');
    });
});
```

**Step 2: Run tests to verify they fail**

Run: `npx vitest run resources/js/stores/admin.test.js`
Expected: FAIL (missing state/methods)

**Step 3: Implement the store additions**

Add to `resources/js/stores/admin.js` — new state and methods before the `return` statement:

```js
    // ─── Per-project configuration (T91) ────────────────────────
    const projectConfig = ref(null);
    const projectConfigLoading = ref(false);
    const projectConfigError = ref(null);

    async function fetchProjectConfig(projectId) {
        projectConfigLoading.value = true;
        projectConfigError.value = null;
        try {
            const { data } = await axios.get(`/api/v1/admin/projects/${projectId}/config`);
            projectConfig.value = data.data;
        } catch (e) {
            projectConfigError.value = 'Failed to load project configuration.';
        } finally {
            projectConfigLoading.value = false;
        }
    }

    async function updateProjectConfig(projectId, settings) {
        try {
            const { data } = await axios.put(`/api/v1/admin/projects/${projectId}/config`, {
                settings,
            });
            if (data.success && data.data) {
                projectConfig.value = data.data;
            }
            return { success: true };
        } catch (e) {
            return {
                success: false,
                error: e.response?.data?.error || 'Failed to update project configuration.',
            };
        }
    }
```

Add these to the `return` block:

```js
        // Per-project config (T91)
        projectConfig,
        projectConfigLoading,
        projectConfigError,
        fetchProjectConfig,
        updateProjectConfig,
```

**Step 4: Run tests to verify they pass**

Run: `npx vitest run resources/js/stores/admin.test.js`
Expected: All PASS

**Step 5: Commit**

```bash
git add resources/js/stores/admin.js resources/js/stores/admin.test.js
git commit --no-gpg-sign -m "$(cat <<'EOF'
T91.3: Add per-project config state and methods to Pinia admin store

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Create AdminProjectConfig Vue component

**Files:**
- Create: `resources/js/components/AdminProjectConfig.vue`
- Test: `resources/js/components/AdminProjectConfig.test.js`

**Step 1: Write the failing test**

```js
// resources/js/components/AdminProjectConfig.test.js

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import AdminProjectConfig from './AdminProjectConfig.vue';
import { useAdminStore } from '@/stores/admin';

vi.mock('axios');

describe('AdminProjectConfig', () => {
    let pinia;
    let admin;

    const defaultProps = { projectId: 42, projectName: 'My Project' };

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();

        vi.spyOn(admin, 'fetchProjectConfig').mockResolvedValue();
    });

    it('renders project name in heading', () => {
        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        expect(wrapper.text()).toContain('My Project');
    });

    it('fetches config on mount', () => {
        mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        expect(admin.fetchProjectConfig).toHaveBeenCalledWith(42);
    });

    it('shows loading state', () => {
        admin.projectConfigLoading = true;
        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        expect(wrapper.text()).toContain('Loading configuration...');
    });

    it('renders setting fields when config is loaded', async () => {
        admin.projectConfig = {
            settings: { ai_model: 'sonnet' },
            effective: {
                ai_model: { value: 'sonnet', source: 'project' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: {
                ai_model: 'string',
                ai_language: 'string',
                timeout_minutes: 'integer',
                max_tokens: 'integer',
            },
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="config-ai_model"]').exists()).toBe(true);
    });

    it('shows source indicator for overridden values', async () => {
        admin.projectConfig = {
            settings: { ai_model: 'sonnet' },
            effective: {
                ai_model: { value: 'sonnet', source: 'project' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: { ai_model: 'string', ai_language: 'string', timeout_minutes: 'integer', max_tokens: 'integer' },
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        // The overridden field should have a "project" source indicator
        expect(wrapper.find('[data-testid="source-ai_model"]').text()).toContain('Project');
    });

    it('shows source indicator for inherited values', async () => {
        admin.projectConfig = {
            settings: {},
            effective: {
                ai_model: { value: 'opus', source: 'default' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: { ai_model: 'string', ai_language: 'string', timeout_minutes: 'integer', max_tokens: 'integer' },
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="source-ai_model"]').text()).toContain('Default');
    });

    it('emits back event when back button is clicked', async () => {
        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });

        await wrapper.find('[data-testid="back-btn"]').trigger('click');
        expect(wrapper.emitted('back')).toBeTruthy();
    });

    it('calls updateProjectConfig on save', async () => {
        vi.spyOn(admin, 'updateProjectConfig').mockResolvedValue({ success: true });

        admin.projectConfig = {
            settings: {},
            effective: {
                ai_model: { value: 'opus', source: 'default' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: { ai_model: 'string', ai_language: 'string', timeout_minutes: 'integer', max_tokens: 'integer' },
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-config-btn"]').trigger('click');

        expect(admin.updateProjectConfig).toHaveBeenCalledWith(42, expect.any(Object));
    });

    it('shows success message after save', async () => {
        vi.spyOn(admin, 'updateProjectConfig').mockResolvedValue({ success: true });

        admin.projectConfig = {
            settings: {},
            effective: {
                ai_model: { value: 'opus', source: 'default' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: { ai_model: 'string', ai_language: 'string', timeout_minutes: 'integer', max_tokens: 'integer' },
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-config-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="config-success"]').exists()).toBe(true);
    });

    it('shows error on save failure', async () => {
        vi.spyOn(admin, 'updateProjectConfig').mockResolvedValue({
            success: false,
            error: 'Server error',
        });

        admin.projectConfig = {
            settings: {},
            effective: {
                ai_model: { value: 'opus', source: 'default' },
                ai_language: { value: 'en', source: 'default' },
                timeout_minutes: { value: 10, source: 'default' },
                max_tokens: { value: 8192, source: 'default' },
            },
            setting_keys: { ai_model: 'string', ai_language: 'string', timeout_minutes: 'integer', max_tokens: 'integer' },
        };

        const wrapper = mount(AdminProjectConfig, {
            props: defaultProps,
            global: { plugins: [pinia] },
        });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-config-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="config-error"]').text()).toContain('Server error');
    });
});
```

**Step 2: Run tests to verify they fail**

Run: `npx vitest run resources/js/components/AdminProjectConfig.test.js`
Expected: FAIL (component doesn't exist)

**Step 3: Write the component**

```vue
<!-- resources/js/components/AdminProjectConfig.vue -->
<script setup>
import { ref, onMounted, watch, computed } from 'vue';
import { useAdminStore } from '@/stores/admin';

const props = defineProps({
    projectId: { type: Number, required: true },
    projectName: { type: String, required: true },
});

const emit = defineEmits(['back']);

const admin = useAdminStore();
const saving = ref(false);
const saveSuccess = ref(false);
const saveError = ref(null);

// Local form state: key → value (only overridden values)
const form = ref({});

// Top-level setting groups to render in the UI
const topLevelSettings = [
    { key: 'ai_model', label: 'AI Model', type: 'select', options: ['opus', 'sonnet', 'haiku'] },
    { key: 'ai_language', label: 'AI Response Language', type: 'text', placeholder: 'en' },
    { key: 'timeout_minutes', label: 'Task Timeout (minutes)', type: 'number', min: 1, max: 60 },
    { key: 'max_tokens', label: 'Max Tokens', type: 'number', min: 1024, max: 200000 },
];

const codeReviewSettings = [
    { key: 'code_review.auto_review', label: 'Auto-review on MR', type: 'checkbox' },
    { key: 'code_review.auto_review_on_push', label: 'Auto-review on push', type: 'checkbox' },
    { key: 'code_review.severity_threshold', label: 'Severity threshold', type: 'select', options: ['info', 'minor', 'major', 'critical'] },
];

const featureDevSettings = [
    { key: 'feature_dev.enabled', label: 'Feature dev enabled', type: 'checkbox' },
    { key: 'feature_dev.branch_prefix', label: 'Branch prefix', type: 'text', placeholder: 'ai/' },
    { key: 'feature_dev.auto_create_mr', label: 'Auto-create MR', type: 'checkbox' },
];

const conversationSettings = [
    { key: 'conversation.enabled', label: 'Conversation enabled', type: 'checkbox' },
    { key: 'conversation.max_history_messages', label: 'Max history messages', type: 'number', min: 10, max: 500 },
    { key: 'conversation.tool_use_gitlab', label: 'GitLab tool use', type: 'checkbox' },
];

const allSettingGroups = [
    { title: 'AI Configuration', settings: topLevelSettings },
    { title: 'Code Review', settings: codeReviewSettings },
    { title: 'Feature Development', settings: featureDevSettings },
    { title: 'Conversation', settings: conversationSettings },
];

onMounted(() => {
    admin.fetchProjectConfig(props.projectId);
});

// Sync form state when config loads
watch(() => admin.projectConfig, (config) => {
    if (!config) return;
    const newForm = {};
    for (const group of allSettingGroups) {
        for (const setting of group.settings) {
            const effective = config.effective?.[setting.key];
            if (effective) {
                newForm[setting.key] = effective.value;
            }
        }
    }
    form.value = newForm;
}, { immediate: true });

function getSource(key) {
    return admin.projectConfig?.effective?.[key]?.source ?? 'default';
}

function isOverridden(key) {
    return getSource(key) === 'project';
}

function sourceLabel(key) {
    const source = getSource(key);
    if (source === 'project') return 'Project';
    if (source === 'global') return 'Global';
    return 'Default';
}

function resetToDefault(key) {
    const effective = admin.projectConfig?.effective?.[key];
    if (effective) {
        // Get the non-project value (global or default)
        // For now, just set to null to remove override
        form.value[key] = null;
    }
}

async function handleSave() {
    saving.value = true;
    saveSuccess.value = false;
    saveError.value = null;

    // Build settings object: only include keys that differ from defaults
    // null values = remove override
    const settings = {};
    for (const group of allSettingGroups) {
        for (const setting of group.settings) {
            const formValue = form.value[setting.key];
            const effective = admin.projectConfig?.effective?.[setting.key];

            if (formValue === null || formValue === undefined) {
                // Explicitly null = remove override
                if (effective?.source === 'project') {
                    settings[setting.key] = null;
                }
            } else {
                // Cast types appropriately
                let castValue = formValue;
                if (setting.type === 'number') {
                    castValue = Number(formValue);
                } else if (setting.type === 'checkbox') {
                    castValue = Boolean(formValue);
                }
                settings[setting.key] = castValue;
            }
        }
    }

    const result = await admin.updateProjectConfig(props.projectId, settings);
    saving.value = false;

    if (result.success) {
        saveSuccess.value = true;
        setTimeout(() => { saveSuccess.value = false; }, 3000);
    } else {
        saveError.value = result.error;
    }
}
</script>

<template>
  <div>
    <!-- Header with back button -->
    <div class="flex items-center gap-3 mb-6">
      <button
        data-testid="back-btn"
        class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800"
        @click="emit('back')"
      >
        ← Back
      </button>
      <h2 class="text-lg font-medium">{{ projectName }} — Configuration</h2>
    </div>

    <!-- Loading state -->
    <div v-if="admin.projectConfigLoading" class="py-8 text-center text-zinc-500">
      Loading configuration...
    </div>

    <template v-else-if="admin.projectConfig">
      <!-- Success banner -->
      <div v-if="saveSuccess" class="mb-4 rounded-lg border border-green-300 bg-green-50 p-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-400" data-testid="config-success">
        Configuration saved successfully.
      </div>

      <!-- Error banner -->
      <div v-if="saveError" class="mb-4 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400" data-testid="config-error">
        {{ saveError }}
      </div>

      <!-- Info about inheritance -->
      <div class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
        Values inherit from global settings unless overridden. Overrides are shown with a
        <span class="inline-flex items-center rounded-full bg-blue-100 px-1.5 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-400">Project</span>
        badge.
      </div>

      <!-- Settings groups -->
      <div v-for="group in allSettingGroups" :key="group.title" class="mb-6 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <h3 class="text-sm font-medium mb-3">{{ group.title }}</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div
            v-for="setting in group.settings"
            :key="setting.key"
            :data-testid="`config-${setting.key}`"
          >
            <div class="flex items-center gap-2 mb-1">
              <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">
                {{ setting.label }}
              </label>
              <span
                :data-testid="`source-${setting.key}`"
                class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                :class="{
                  'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400': isOverridden(setting.key),
                  'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400': getSource(setting.key) === 'global',
                  'bg-zinc-50 text-zinc-400 dark:bg-zinc-900 dark:text-zinc-500': getSource(setting.key) === 'default',
                }"
              >
                {{ sourceLabel(setting.key) }}
              </span>
            </div>

            <!-- Select field -->
            <select
              v-if="setting.type === 'select'"
              v-model="form[setting.key]"
              class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800"
            >
              <option v-for="opt in setting.options" :key="opt" :value="opt">
                {{ opt.charAt(0).toUpperCase() + opt.slice(1) }}
              </option>
            </select>

            <!-- Text field -->
            <input
              v-else-if="setting.type === 'text'"
              v-model="form[setting.key]"
              type="text"
              class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800"
              :placeholder="setting.placeholder"
            />

            <!-- Number field -->
            <input
              v-else-if="setting.type === 'number'"
              v-model.number="form[setting.key]"
              type="number"
              :min="setting.min"
              :max="setting.max"
              class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800"
            />

            <!-- Checkbox field -->
            <div v-else-if="setting.type === 'checkbox'" class="flex items-center">
              <input
                v-model="form[setting.key]"
                type="checkbox"
                class="h-4 w-4 rounded border-zinc-300 text-blue-600 focus:ring-blue-500 dark:border-zinc-600"
              />
            </div>
          </div>
        </div>
      </div>

      <!-- Save button -->
      <div class="flex items-center gap-3">
        <button
          data-testid="save-config-btn"
          class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          :disabled="saving"
          @click="handleSave"
        >
          {{ saving ? 'Saving...' : 'Save Configuration' }}
        </button>
      </div>
    </template>
  </div>
</template>
```

**Step 4: Run tests to verify they pass**

Run: `npx vitest run resources/js/components/AdminProjectConfig.test.js`
Expected: All PASS

**Step 5: Commit**

```bash
git add resources/js/components/AdminProjectConfig.vue \
  resources/js/components/AdminProjectConfig.test.js
git commit --no-gpg-sign -m "$(cat <<'EOF'
T91.4: Create AdminProjectConfig Vue component with form and tests

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Wire AdminProjectConfig into AdminPage and AdminProjectList

**Files:**
- Modify: `resources/js/components/AdminProjectList.vue` (add Configure button)
- Modify: `resources/js/pages/AdminPage.vue` (add project config view state)
- Test: `resources/js/pages/AdminPage.test.js` (add new tests)
- Test: `resources/js/components/AdminProjectList.test.js` (create or extend — add Configure button test)

**Step 1: Write the failing test for AdminProjectList**

Create `resources/js/components/AdminProjectList.test.js` (if not exists):

```js
// resources/js/components/AdminProjectList.test.js

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import AdminProjectList from './AdminProjectList.vue';
import { useAdminStore } from '@/stores/admin';

vi.mock('axios');

describe('AdminProjectList — Configure button (T91)', () => {
    let pinia;
    let admin;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();
    });

    it('shows Configure button for enabled projects', () => {
        admin.projects = [
            { id: 1, name: 'Project A', slug: 'project-a', gitlab_project_id: 42, enabled: true, webhook_configured: true, recent_task_count: 5, active_conversation_count: 2 },
        ];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="configure-btn-1"]').exists()).toBe(true);
    });

    it('does not show Configure button for disabled projects', () => {
        admin.projects = [
            { id: 1, name: 'Project A', slug: 'project-a', gitlab_project_id: 42, enabled: false, webhook_configured: false, recent_task_count: 0, active_conversation_count: 0 },
        ];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="configure-btn-1"]').exists()).toBe(false);
    });

    it('emits configure event with project data when Configure is clicked', async () => {
        admin.projects = [
            { id: 1, name: 'Project A', slug: 'project-a', gitlab_project_id: 42, enabled: true, webhook_configured: true, recent_task_count: 5, active_conversation_count: 2 },
        ];

        const wrapper = mount(AdminProjectList, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="configure-btn-1"]').trigger('click');

        expect(wrapper.emitted('configure')).toBeTruthy();
        expect(wrapper.emitted('configure')[0][0]).toEqual({ id: 1, name: 'Project A' });
    });
});
```

**Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/components/AdminProjectList.test.js`
Expected: FAIL (no Configure button exists yet)

**Step 3: Update AdminProjectList to add Configure button**

In `resources/js/components/AdminProjectList.vue`, add the emit declaration and the Configure button. The key changes:

1. Add `defineEmits(['configure'])` at the top of `<script setup>`
2. Add a "Configure" button next to the Enable/Disable button for enabled projects

The Configure button goes inside the existing `<div class="ml-4 flex-shrink-0">` container, before the Disable button (for enabled projects):

```vue
<!-- Add inside the button area for enabled projects -->
<button
  v-if="project.enabled"
  class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800 mr-2"
  :data-testid="`configure-btn-${project.id}`"
  @click="emit('configure', { id: project.id, name: project.name })"
>
  Configure
</button>
```

**Step 4: Update AdminPage to handle project config navigation**

In `resources/js/pages/AdminPage.vue`:

1. Import AdminProjectConfig component
2. Add `configuringProject` ref state (null or `{id, name}`)
3. When `AdminProjectList` emits `configure`, set `configuringProject` and show `AdminProjectConfig`
4. When `AdminProjectConfig` emits `back`, clear `configuringProject`

**Step 5: Run all tests**

Run: `npx vitest run resources/js/components/AdminProjectList.test.js resources/js/components/AdminProjectConfig.test.js resources/js/pages/AdminPage.test.js`
Expected: All PASS

**Step 6: Commit**

```bash
git add resources/js/components/AdminProjectList.vue \
  resources/js/components/AdminProjectList.test.js \
  resources/js/pages/AdminPage.vue \
  resources/js/pages/AdminPage.test.js
git commit --no-gpg-sign -m "$(cat <<'EOF'
T91.5: Wire AdminProjectConfig into AdminPage with Configure button

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Add integration test for config resolution end-to-end

**Files:**
- Create: `tests/Feature/ProjectConfigResolutionTest.php`

**Step 1: Write the integration test**

```php
<?php
// tests/Feature/ProjectConfigResolutionTest.php

use App\Models\GlobalSetting;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Models\Role;
use App\Models\User;
use App\Services\ProjectConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
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
});

function createConfigAdmin(Project $project): User
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

it('API update → service resolution reflects new override', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);
    $admin = createConfigAdmin($project);

    // Verify starts with default
    $service = app(ProjectConfigService::class);
    expect($service->get($project, 'ai_model'))->toBe('opus');

    // Update via API
    $this->actingAs($admin)->putJson("/api/v1/admin/projects/{$project->id}/config", [
        'settings' => ['ai_model' => 'sonnet'],
    ])->assertOk();

    // Service should now resolve to project override
    expect($service->get($project, 'ai_model'))->toBe('sonnet');
});

it('global setting → project inherits when no override', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);

    GlobalSetting::set('ai_language', 'ja', 'string');

    $service = app(ProjectConfigService::class);
    expect($service->get($project, 'ai_language'))->toBe('ja');
});

it('project override → takes precedence over global', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_language' => 'de'],
    ]);

    GlobalSetting::set('ai_language', 'ja', 'string');

    $service = app(ProjectConfigService::class);
    expect($service->get($project, 'ai_language'))->toBe('de');
});

it('remove override → falls back to global', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet'],
    ]);
    $admin = createConfigAdmin($project);

    // Remove override
    $this->actingAs($admin)->putJson("/api/v1/admin/projects/{$project->id}/config", [
        'settings' => ['ai_model' => null],
    ])->assertOk();

    $service = app(ProjectConfigService::class);
    expect($service->get($project, 'ai_model'))->toBe('opus');
});

it('cache is invalidated on config update via API', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'sonnet'],
    ]);
    $admin = createConfigAdmin($project);

    $service = app(ProjectConfigService::class);
    $service->get($project, 'ai_model'); // populates cache

    // Update via API
    $this->actingAs($admin)->putJson("/api/v1/admin/projects/{$project->id}/config", [
        'settings' => ['ai_model' => 'haiku'],
    ])->assertOk();

    // Cache should be invalidated — should get new value
    expect($service->get($project, 'ai_model'))->toBe('haiku');
});
```

**Step 2: Run tests**

Run: `php artisan test --filter=ProjectConfigResolutionTest`
Expected: All PASS

**Step 3: Commit**

```bash
git add tests/Feature/ProjectConfigResolutionTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T91.6: Add integration tests for config resolution end-to-end

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Run full verification and finalize

**Step 1: Run all PHP tests**

```bash
php artisan test --parallel
```

Expected: All pass

**Step 2: Run all Vue/JS tests**

```bash
npx vitest run
```

Expected: All pass

**Step 3: Run M5 verification script (if it exists)**

```bash
python3 verify/verify_m5.py
```

If not found, skip — T91 is not the final M5 task.

**Step 4: Update progress.md**

- Check T91 box: `[x]`
- Bold next task: **T92**
- Update milestone count: `4/18`
- Update summary

**Step 5: Clear handoff.md**

Reset to empty template.

**Step 6: Final commit**

```bash
git add -A
git commit --no-gpg-sign -m "$(cat <<'EOF'
T91: Complete per-project configuration system

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

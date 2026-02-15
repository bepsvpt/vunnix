# T93: PRD Template Management — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make the PRD template configurable per project (and globally) with RBAC-controlled access via the `config.manage` permission, replacing the hardcoded template in VunnixAgent.

**Architecture:** Store PRD templates as a `prd_template` key in the existing ProjectConfig/GlobalSetting config hierarchy (project DB → file → global → default). Add dedicated API endpoints for template CRUD, a Vue admin component for editing, and modify VunnixAgent to read the template dynamically. Authorization uses `config.manage` for project-level and `admin.global_config` for global template.

**Tech Stack:** Laravel 11 (controller, service, form request), Vue 3 (Composition API, `<script setup>`), Pinia, Pest testing.

---

### Task 1: Add `prd_template` to ProjectConfigService setting keys

**Files:**
- Modify: `app/Services/ProjectConfigService.php:20-42` (settingKeys method)

**Step 1: Write the failing test**

Add to `tests/Unit/Services/ProjectConfigServiceTest.php`:

```php
it('includes prd_template in setting keys', function () {
    $keys = ProjectConfigService::settingKeys();
    expect($keys)->toHaveKey('prd_template');
    expect($keys['prd_template'])->toBe('text');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter="includes prd_template in setting keys"`
Expected: FAIL — key not found.

**Step 3: Write minimal implementation**

In `ProjectConfigService::settingKeys()`, add after the last entry:

```php
'prd_template' => 'text',
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter="includes prd_template in setting keys"`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Services/ProjectConfigService.php tests/Unit/Services/ProjectConfigServiceTest.php
git commit --no-gpg-sign -m "T93.1: Add prd_template to ProjectConfigService setting keys"
```

---

### Task 2: Add default PRD template to GlobalSetting and a helper to retrieve it

**Files:**
- Modify: `app/Models/GlobalSetting.php` (add `defaultPrdTemplate()` static method)
- Test: `tests/Feature/Agents/VunnixAgentTest.php` (existing file — will add template config tests)

**Step 1: Write the failing test**

Add to a new test file `tests/Unit/Models/GlobalSettingPrdTemplateTest.php`:

```php
<?php

use App\Models\GlobalSetting;

it('returns default PRD template via static method', function () {
    $template = GlobalSetting::defaultPrdTemplate();

    expect($template)->toContain('## Problem')
        ->and($template)->toContain('## Proposed Solution')
        ->and($template)->toContain('## User Stories')
        ->and($template)->toContain('## Acceptance Criteria')
        ->and($template)->toContain('## Out of Scope')
        ->and($template)->toContain('## Technical Notes')
        ->and($template)->toContain('## Open Questions');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter="returns default PRD template via static method"`
Expected: FAIL — method not found.

**Step 3: Write minimal implementation**

Add to `GlobalSetting` model:

```php
/**
 * Default PRD template used when no project or global override exists.
 * Matches the template structure from vunnix.md §4.4.
 */
public static function defaultPrdTemplate(): string
{
    return <<<'TEMPLATE'
# [Feature Title]

## Problem
What problem does this solve? Who is affected?

## Proposed Solution
High-level description of the feature.

## User Stories
- As a [role], I want [action] so that [benefit]

## Acceptance Criteria
- [ ] Criterion 1
- [ ] Criterion 2

## Out of Scope
What this feature does NOT include.

## Technical Notes
Architecture considerations, dependencies, related existing code.

## Open Questions
Unresolved items from the conversation.
TEMPLATE;
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter="returns default PRD template via static method"`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Models/GlobalSetting.php tests/Unit/Models/GlobalSettingPrdTemplateTest.php
git commit --no-gpg-sign -m "T93.2: Add defaultPrdTemplate() to GlobalSetting model"
```

---

### Task 3: Create PrdTemplateController with show/update endpoints

**Files:**
- Create: `app/Http/Controllers/Api/PrdTemplateController.php`
- Create: `app/Http/Requests/UpdatePrdTemplateRequest.php`
- Modify: `routes/api.php` (add 4 routes)

**Step 1: Write the failing tests**

Create `tests/Feature/PrdTemplateApiTest.php`:

```php
<?php

use App\Models\GlobalSetting;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Helpers ──────────────────────────────────────────────────

function createConfigManager(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'config-manager']);
    $perm = Permission::firstOrCreate(
        ['name' => 'config.manage'],
        ['description' => 'Can edit project-level config', 'group' => 'config']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);
    return $user;
}

function createTemplateAdmin(Project $project): User
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

function createUnprivilegedUser(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'viewer']);
    $perm = Permission::firstOrCreate(
        ['name' => 'chat.access'],
        ['description' => 'Chat access', 'group' => 'chat']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);
    return $user;
}

// ─── GET /admin/projects/{project}/prd-template ──────────────

it('returns default PRD template when no override exists', function () {
    $project = Project::factory()->create();
    $user = createConfigManager($project);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/admin/projects/{$project->id}/prd-template");

    $response->assertOk()
        ->assertJsonStructure(['data' => ['template', 'source']])
        ->assertJsonPath('data.source', 'default');

    expect($response->json('data.template'))->toContain('## Problem');
});

it('returns project-level PRD template override', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['prd_template' => '# Custom Template'],
    ]);
    $user = createConfigManager($project);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/admin/projects/{$project->id}/prd-template");

    $response->assertOk()
        ->assertJsonPath('data.template', '# Custom Template')
        ->assertJsonPath('data.source', 'project');
});

it('returns global PRD template when set and no project override', function () {
    $project = Project::factory()->create();
    GlobalSetting::set('prd_template', '# Global Template', 'string', 'PRD template');
    $user = createConfigManager($project);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/admin/projects/{$project->id}/prd-template");

    $response->assertOk()
        ->assertJsonPath('data.template', '# Global Template')
        ->assertJsonPath('data.source', 'global');
});

it('rejects PRD template read for user without config.manage', function () {
    $project = Project::factory()->create();
    $user = createUnprivilegedUser($project);

    $this->actingAs($user)
        ->getJson("/api/v1/admin/projects/{$project->id}/prd-template")
        ->assertForbidden();
});

it('rejects PRD template read for unauthenticated user', function () {
    $project = Project::factory()->create();

    $this->getJson("/api/v1/admin/projects/{$project->id}/prd-template")
        ->assertUnauthorized();
});

// ─── PUT /admin/projects/{project}/prd-template ──────────────

it('saves project-level PRD template override', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create(['project_id' => $project->id, 'settings' => []]);
    $user = createConfigManager($project);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/admin/projects/{$project->id}/prd-template", [
            'template' => '# My Custom PRD\n\n## Requirements',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.source', 'project');

    $config = $project->projectConfig->fresh();
    expect($config->settings['prd_template'])->toBe('# My Custom PRD\n\n## Requirements');
});

it('removes project PRD template override when template is null', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['prd_template' => '# Old Template'],
    ]);
    $user = createConfigManager($project);

    $response = $this->actingAs($user)
        ->putJson("/api/v1/admin/projects/{$project->id}/prd-template", [
            'template' => null,
        ]);

    $response->assertOk();

    $config = $project->projectConfig->fresh();
    expect($config->settings)->not->toHaveKey('prd_template');
});

it('rejects PRD template update for user without config.manage', function () {
    $project = Project::factory()->create();
    $user = createUnprivilegedUser($project);

    $this->actingAs($user)
        ->putJson("/api/v1/admin/projects/{$project->id}/prd-template", [
            'template' => '# Hacked',
        ])
        ->assertForbidden();
});

it('validates template must be a string or null', function () {
    $project = Project::factory()->create();
    $user = createConfigManager($project);

    $this->actingAs($user)
        ->putJson("/api/v1/admin/projects/{$project->id}/prd-template", [
            'template' => ['not', 'a', 'string'],
        ])
        ->assertUnprocessable();
});

// ─── GET /admin/prd-template (global) ────────────────────────

it('returns global default PRD template', function () {
    $project = Project::factory()->create();
    $admin = createTemplateAdmin($project);

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/admin/prd-template');

    $response->assertOk()
        ->assertJsonStructure(['data' => ['template', 'source']])
        ->assertJsonPath('data.source', 'default');

    expect($response->json('data.template'))->toContain('## Problem');
});

it('returns global PRD template override when set', function () {
    $project = Project::factory()->create();
    GlobalSetting::set('prd_template', '# Global Custom', 'string', 'PRD template');
    $admin = createTemplateAdmin($project);

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/admin/prd-template');

    $response->assertOk()
        ->assertJsonPath('data.template', '# Global Custom')
        ->assertJsonPath('data.source', 'global');
});

it('rejects global PRD template read without admin.global_config', function () {
    $project = Project::factory()->create();
    $user = createConfigManager($project);

    $this->actingAs($user)
        ->getJson('/api/v1/admin/prd-template')
        ->assertForbidden();
});

// ─── PUT /admin/prd-template (global) ────────────────────────

it('saves global PRD template override', function () {
    $project = Project::factory()->create();
    $admin = createTemplateAdmin($project);

    $response = $this->actingAs($admin)
        ->putJson('/api/v1/admin/prd-template', [
            'template' => '# Org-Wide PRD',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    expect(GlobalSetting::get('prd_template'))->toBe('# Org-Wide PRD');
});

it('resets global PRD template to default when null', function () {
    $project = Project::factory()->create();
    GlobalSetting::set('prd_template', '# Old Global', 'string', 'PRD');
    $admin = createTemplateAdmin($project);

    $response = $this->actingAs($admin)
        ->putJson('/api/v1/admin/prd-template', [
            'template' => null,
        ]);

    $response->assertOk();
    // After deletion, should fall back to hardcoded default
    expect(GlobalSetting::get('prd_template'))->toBeNull();
});

it('rejects global PRD template update without admin.global_config', function () {
    $project = Project::factory()->create();
    $user = createConfigManager($project);

    $this->actingAs($user)
        ->putJson('/api/v1/admin/prd-template', [
            'template' => '# Hacked',
        ])
        ->assertForbidden();
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/PrdTemplateApiTest.php`
Expected: FAIL — controller and routes don't exist.

**Step 3: Write the controller, form request, and routes**

Create `app/Http/Requests/UpdatePrdTemplateRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePrdTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled in controller
    }

    public function rules(): array
    {
        return [
            'template' => ['present', 'nullable', 'string', 'max:65535'],
        ];
    }
}
```

Create `app/Http/Controllers/Api/PrdTemplateController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePrdTemplateRequest;
use App\Models\GlobalSetting;
use App\Models\Project;
use App\Services\ProjectConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrdTemplateController extends Controller
{
    public function __construct(
        private readonly ProjectConfigService $configService,
    ) {}

    /**
     * GET /admin/projects/{project}/prd-template
     * Returns the effective PRD template for a project with source indicator.
     */
    public function showProject(Request $request, Project $project): JsonResponse
    {
        $this->authorizeConfigManage($request, $project);

        return response()->json([
            'data' => $this->resolveTemplate($project),
        ]);
    }

    /**
     * PUT /admin/projects/{project}/prd-template
     * Set or remove the project-level PRD template override.
     */
    public function updateProject(UpdatePrdTemplateRequest $request, Project $project): JsonResponse
    {
        $this->authorizeConfigManage($request, $project);

        $this->configService->set($project, 'prd_template', $request->validated('template'));

        return response()->json([
            'success' => true,
            'data' => $this->resolveTemplate($project),
        ]);
    }

    /**
     * GET /admin/prd-template
     * Returns the global PRD template (override or default).
     */
    public function showGlobal(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $globalOverride = GlobalSetting::get('prd_template');

        return response()->json([
            'data' => [
                'template' => $globalOverride ?? GlobalSetting::defaultPrdTemplate(),
                'source' => $globalOverride ? 'global' : 'default',
            ],
        ]);
    }

    /**
     * PUT /admin/prd-template
     * Set or remove the global PRD template override.
     */
    public function updateGlobal(UpdatePrdTemplateRequest $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $template = $request->validated('template');

        if ($template === null) {
            GlobalSetting::where('key', 'prd_template')->delete();
        } else {
            GlobalSetting::set('prd_template', $template, 'string', 'PRD output template');
        }

        $globalOverride = GlobalSetting::get('prd_template');

        return response()->json([
            'success' => true,
            'data' => [
                'template' => $globalOverride ?? GlobalSetting::defaultPrdTemplate(),
                'source' => $globalOverride ? 'global' : 'default',
            ],
        ]);
    }

    /**
     * Resolve the effective template for a project: project → global → default.
     */
    private function resolveTemplate(Project $project): array
    {
        $projectTemplate = $this->configService->get($project, 'prd_template');
        if ($projectTemplate !== null) {
            return ['template' => $projectTemplate, 'source' => 'project'];
        }

        $globalOverride = GlobalSetting::get('prd_template');
        if ($globalOverride !== null) {
            return ['template' => $globalOverride, 'source' => 'global'];
        }

        return ['template' => GlobalSetting::defaultPrdTemplate(), 'source' => 'default'];
    }

    /**
     * Authorize project-level template access via config.manage permission.
     */
    private function authorizeConfigManage(Request $request, Project $project): void
    {
        $user = $request->user();

        if (! $user->hasPermission('config.manage', $project)) {
            abort(403, 'You need the config.manage permission to manage PRD templates.');
        }
    }

    /**
     * Authorize global template access via admin.global_config permission.
     */
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

Add routes to `routes/api.php` inside the `auth` middleware group, after the admin per-project config block:

```php
// PRD template management (T93)
Route::get('/admin/projects/{project}/prd-template', [PrdTemplateController::class, 'showProject'])
    ->name('api.admin.projects.prd-template.show');
Route::put('/admin/projects/{project}/prd-template', [PrdTemplateController::class, 'updateProject'])
    ->name('api.admin.projects.prd-template.update');
Route::get('/admin/prd-template', [PrdTemplateController::class, 'showGlobal'])
    ->name('api.admin.prd-template.show');
Route::put('/admin/prd-template', [PrdTemplateController::class, 'updateGlobal'])
    ->name('api.admin.prd-template.update');
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/PrdTemplateApiTest.php`
Expected: All PASS

**Step 5: Commit**

```bash
git add app/Http/Controllers/Api/PrdTemplateController.php app/Http/Requests/UpdatePrdTemplateRequest.php routes/api.php tests/Feature/PrdTemplateApiTest.php
git commit --no-gpg-sign -m "T93.3: Add PRD template API endpoints with config.manage RBAC"
```

---

### Task 4: Make VunnixAgent read PRD template from config hierarchy

**Files:**
- Modify: `app/Agents/VunnixAgent.php:223-265` (prdTemplateSection method)
- Modify: `app/Services/ConversationService.php:104-110` (inject project context)
- Test: `tests/Feature/Agents/VunnixAgentTest.php`

**Step 1: Write the failing tests**

Add to `tests/Feature/Agents/VunnixAgentTest.php`:

```php
// ─── PRD Template Configuration (T93) ───────────────────────────

it('uses default PRD template when no override exists', function () {
    $agent = new VunnixAgent;
    $instructions = $agent->instructions();

    expect($instructions)->toContain('[PRD Output Template]')
        ->and($instructions)->toContain('## Problem')
        ->and($instructions)->toContain('## Proposed Solution');
});

it('uses project-level PRD template when set', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['prd_template' => '# Custom PRD\n\n## Requirements\nList requirements here.'],
    ]);

    $agent = new VunnixAgent;
    $agent->setProject($project);
    $instructions = $agent->instructions();

    expect($instructions)->toContain('# Custom PRD')
        ->and($instructions)->toContain('## Requirements')
        ->and($instructions)->not->toContain('## Proposed Solution');
});

it('uses global PRD template when set and no project override', function () {
    GlobalSetting::set('prd_template', '# Global PRD\n\n## Business Case', 'string');

    $agent = new VunnixAgent;
    $instructions = $agent->instructions();

    expect($instructions)->toContain('# Global PRD')
        ->and($instructions)->toContain('## Business Case');
});

it('project PRD template takes precedence over global', function () {
    $project = Project::factory()->create();
    GlobalSetting::set('prd_template', '# Global PRD', 'string');
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['prd_template' => '# Project PRD'],
    ]);

    $agent = new VunnixAgent;
    $agent->setProject($project);
    $instructions = $agent->instructions();

    expect($instructions)->toContain('# Project PRD')
        ->and($instructions)->not->toContain('# Global PRD');
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="uses project-level PRD template when set"`
Expected: FAIL — `setProject()` method not found.

**Step 3: Write minimal implementation**

Modify `VunnixAgent.php`:

Add a `$project` property and `setProject()` method:

```php
protected ?Project $project = null;

public function setProject(Project $project): void
{
    $this->project = $project;
}
```

Add the import:

```php
use App\Models\Project;
use App\Models\ProjectConfig;
```

Modify `prdTemplateSection()` to read from config:

```php
protected function prdTemplateSection(): string
{
    $template = $this->resolveTemplate();

    return <<<PROMPT
[PRD Output Template]
When a Product Manager is planning a feature, guide the conversation toward filling this standardized PRD template. Fill sections progressively as the conversation develops — not as a one-shot dump. Update and refine sections as the PM provides more detail.

**Template:**

{$template}

**Progressive filling rules:**
1. Start by understanding the Problem — ask clarifying questions until the problem is concrete.
2. Once the problem is clear, propose a solution and draft User Stories.
3. Work through Acceptance Criteria collaboratively — suggest criteria based on codebase context.
4. Populate Technical Notes using codebase context gathered via your tools (BrowseRepoTree, ReadFile, SearchCode). Include relevant architecture considerations, existing dependencies, and related code paths.
5. Track unresolved items in Open Questions — revisit them before finalizing.
6. Present the evolving draft to the PM after significant updates, showing which sections are complete and which need more input.

**Completion:**
When the PM confirms the PRD is ready, use the `create_issue` action type via DispatchAction to create the complete PRD as a GitLab Issue. The Issue description should contain the full template with all sections filled.
PROMPT;
}

/**
 * Resolve PRD template: project config → global setting → hardcoded default.
 */
protected function resolveTemplate(): string
{
    if ($this->project) {
        $configService = app(ProjectConfigService::class);
        $projectTemplate = $configService->get($this->project, 'prd_template');
        if ($projectTemplate !== null) {
            return $projectTemplate;
        }
    }

    $globalTemplate = GlobalSetting::get('prd_template');
    if ($globalTemplate !== null) {
        return $globalTemplate;
    }

    return GlobalSetting::defaultPrdTemplate();
}
```

Add the import at the top:

```php
use App\Services\ProjectConfigService;
```

**Step 4: Wire project context into ConversationService**

Modify `ConversationService::streamResponse()` to pass the project to the agent:

```php
public function streamResponse(Conversation $conversation, User $user, string $content): StreamableAgentResponse
{
    $agent = VunnixAgent::make();

    // Inject project context for per-project config (T93: PRD template)
    if ($conversation->project_id) {
        $project = Project::find($conversation->project_id);
        if ($project) {
            $agent->setProject($project);
        }
    }

    $agent->continue($conversation->id, $user);

    return $agent->stream($content);
}
```

**Step 5: Run all VunnixAgent tests**

Run: `php artisan test tests/Feature/Agents/VunnixAgentTest.php`
Expected: All PASS (existing tests continue to work with default template, new tests pass with overrides)

**Step 6: Commit**

```bash
git add app/Agents/VunnixAgent.php app/Services/ConversationService.php tests/Feature/Agents/VunnixAgentTest.php
git commit --no-gpg-sign -m "T93.4: Wire VunnixAgent to read PRD template from config hierarchy"
```

---

### Task 5: Add PRD template management to admin Pinia store

**Files:**
- Modify: `resources/js/stores/admin.js`
- Test: Add tests in existing admin store test or inline

**Step 1: Add store methods**

Add to `resources/js/stores/admin.js` after the projectConfig section:

```javascript
// ─── PRD template management (T93) ──────────────────────────
const prdTemplate = ref(null);
const prdTemplateLoading = ref(false);
const prdTemplateError = ref(null);
const globalPrdTemplate = ref(null);
const globalPrdTemplateLoading = ref(false);

async function fetchPrdTemplate(projectId) {
    prdTemplateLoading.value = true;
    prdTemplateError.value = null;
    try {
        const { data } = await axios.get(`/api/v1/admin/projects/${projectId}/prd-template`);
        prdTemplate.value = data.data;
    } catch (e) {
        prdTemplateError.value = 'Failed to load PRD template.';
    } finally {
        prdTemplateLoading.value = false;
    }
}

async function updatePrdTemplate(projectId, template) {
    try {
        const { data } = await axios.put(`/api/v1/admin/projects/${projectId}/prd-template`, {
            template,
        });
        if (data.success && data.data) {
            prdTemplate.value = data.data;
        }
        return { success: true };
    } catch (e) {
        return {
            success: false,
            error: e.response?.data?.error || 'Failed to update PRD template.',
        };
    }
}

async function fetchGlobalPrdTemplate() {
    globalPrdTemplateLoading.value = true;
    try {
        const { data } = await axios.get('/api/v1/admin/prd-template');
        globalPrdTemplate.value = data.data;
    } catch (e) {
        // Supplementary — don't block
    } finally {
        globalPrdTemplateLoading.value = false;
    }
}

async function updateGlobalPrdTemplate(template) {
    try {
        const { data } = await axios.put('/api/v1/admin/prd-template', {
            template,
        });
        if (data.success && data.data) {
            globalPrdTemplate.value = data.data;
        }
        return { success: true };
    } catch (e) {
        return {
            success: false,
            error: e.response?.data?.error || 'Failed to update global PRD template.',
        };
    }
}
```

Export the new state and methods in the return statement:

```javascript
// PRD template (T93)
prdTemplate,
prdTemplateLoading,
prdTemplateError,
globalPrdTemplate,
globalPrdTemplateLoading,
fetchPrdTemplate,
updatePrdTemplate,
fetchGlobalPrdTemplate,
updateGlobalPrdTemplate,
```

**Step 2: Commit**

```bash
git add resources/js/stores/admin.js
git commit --no-gpg-sign -m "T93.5: Add PRD template management to admin Pinia store"
```

---

### Task 6: Create AdminPrdTemplate Vue component

**Files:**
- Create: `resources/js/components/AdminPrdTemplate.vue`

**Step 1: Create the component**

```vue
<script setup>
import { ref, onMounted, watch } from 'vue';
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
const templateContent = ref('');
const resetToDefault = ref(false);

onMounted(() => {
    admin.fetchPrdTemplate(props.projectId);
});

// Sync form state when template loads
watch(() => admin.prdTemplate, (data) => {
    if (!data) return;
    templateContent.value = data.template || '';
}, { immediate: true });

function sourceLabel() {
    const source = admin.prdTemplate?.source ?? 'default';
    if (source === 'project') return 'Project';
    if (source === 'global') return 'Global';
    return 'Default';
}

function isOverridden() {
    return admin.prdTemplate?.source === 'project';
}

async function handleSave() {
    saving.value = true;
    saveSuccess.value = false;
    saveError.value = null;

    const template = resetToDefault.value ? null : templateContent.value;
    const result = await admin.updatePrdTemplate(props.projectId, template);
    saving.value = false;

    if (result.success) {
        saveSuccess.value = true;
        resetToDefault.value = false;
        // Update local state from store
        if (admin.prdTemplate) {
            templateContent.value = admin.prdTemplate.template || '';
        }
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
        &larr; Back
      </button>
      <h2 class="text-lg font-medium">{{ projectName }} &mdash; PRD Template</h2>
      <span
        data-testid="template-source"
        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
        :class="{
          'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400': isOverridden(),
          'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400': admin.prdTemplate?.source === 'global',
          'bg-zinc-50 text-zinc-400 dark:bg-zinc-900 dark:text-zinc-500': admin.prdTemplate?.source === 'default',
        }"
      >
        {{ sourceLabel() }}
      </span>
    </div>

    <!-- Loading state -->
    <div v-if="admin.prdTemplateLoading" class="py-8 text-center text-zinc-500">
      Loading PRD template...
    </div>

    <template v-else-if="admin.prdTemplate">
      <!-- Success banner -->
      <div v-if="saveSuccess" class="mb-4 rounded-lg border border-green-300 bg-green-50 p-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-400" data-testid="template-success">
        PRD template saved successfully.
      </div>

      <!-- Error banner -->
      <div v-if="saveError" class="mb-4 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400" data-testid="template-error">
        {{ saveError }}
      </div>

      <!-- Info about inheritance -->
      <div class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
        This template guides the AI when Product Managers plan features via chat.
        The AI fills sections progressively during the conversation. If no project override is set,
        the global template is used. If no global override, the built-in default is used.
      </div>

      <!-- Reset to default checkbox -->
      <div v-if="isOverridden()" class="mb-4 flex items-center gap-2">
        <input
          v-model="resetToDefault"
          type="checkbox"
          data-testid="reset-default-checkbox"
          class="h-4 w-4 rounded border-zinc-300 text-blue-600 focus:ring-blue-500 dark:border-zinc-600"
        />
        <label class="text-sm text-zinc-600 dark:text-zinc-400">
          Remove project override (revert to {{ admin.prdTemplate?.source === 'project' ? 'global/default' : 'default' }})
        </label>
      </div>

      <!-- Template editor -->
      <div class="mb-4 rounded-lg border border-zinc-200 dark:border-zinc-700">
        <textarea
          v-model="templateContent"
          data-testid="template-editor"
          :disabled="resetToDefault"
          rows="20"
          class="w-full rounded-lg border-0 bg-transparent p-4 font-mono text-sm focus:ring-0 disabled:opacity-50 dark:text-zinc-300"
          placeholder="Enter your PRD template in Markdown..."
        ></textarea>
      </div>

      <!-- Save button -->
      <div class="flex items-center gap-3">
        <button
          data-testid="save-template-btn"
          class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          :disabled="saving"
          @click="handleSave"
        >
          {{ saving ? 'Saving...' : 'Save Template' }}
        </button>
      </div>
    </template>
  </div>
</template>
```

**Step 2: Commit**

```bash
git add resources/js/components/AdminPrdTemplate.vue
git commit --no-gpg-sign -m "T93.6: Create AdminPrdTemplate Vue component"
```

---

### Task 7: Wire AdminPrdTemplate into AdminPage

**Files:**
- Modify: `resources/js/pages/AdminPage.vue`

**Step 1: Add template editing mode**

The AdminPage already has a pattern for `configuringProject` that switches from project list to project config. Add a similar `editingTemplate` mode that shows the PRD template editor for a project.

Add to imports:

```javascript
import AdminPrdTemplate from '@/components/AdminPrdTemplate.vue';
```

Add state variable:

```javascript
const editingTemplate = ref(null); // { id, name }
```

Add template button to project config or project list that navigates to template editing. In the template section, add a conditional render for `editingTemplate`:

```vue
<AdminPrdTemplate
    v-else-if="editingTemplate"
    :project-id="editingTemplate.id"
    :project-name="editingTemplate.name"
    @back="editingTemplate = null"
/>
```

**Step 2: Commit**

```bash
git add resources/js/pages/AdminPage.vue
git commit --no-gpg-sign -m "T93.7: Wire AdminPrdTemplate into AdminPage"
```

---

### Task 8: Add AdminPrdTemplate component test

**Files:**
- Create: `resources/js/components/AdminPrdTemplate.test.js`

**Step 1: Write the test**

```javascript
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import AdminPrdTemplate from './AdminPrdTemplate.vue';
import { useAdminStore } from '@/stores/admin';
import axios from 'axios';

vi.mock('axios');

describe('AdminPrdTemplate', () => {
    let pinia;
    let admin;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();
        axios.get.mockResolvedValue({
            data: {
                data: {
                    template: '# Default\n\n## Problem\nDescribe the problem.',
                    source: 'default',
                },
            },
        });
        axios.put.mockResolvedValue({
            data: {
                success: true,
                data: {
                    template: '# Updated',
                    source: 'project',
                },
            },
        });
    });

    it('renders project name and back button', () => {
        const wrapper = mount(AdminPrdTemplate, {
            props: { projectId: 1, projectName: 'My Project' },
            global: { plugins: [pinia] },
        });
        expect(wrapper.text()).toContain('My Project');
        expect(wrapper.text()).toContain('PRD Template');
        expect(wrapper.find('[data-testid="back-btn"]').exists()).toBe(true);
    });

    it('emits back event when back button clicked', async () => {
        const wrapper = mount(AdminPrdTemplate, {
            props: { projectId: 1, projectName: 'Test' },
            global: { plugins: [pinia] },
        });
        await wrapper.find('[data-testid="back-btn"]').trigger('click');
        expect(wrapper.emitted('back')).toBeTruthy();
    });

    it('shows loading state while fetching', () => {
        admin.prdTemplateLoading = true;
        admin.prdTemplate = null;
        const wrapper = mount(AdminPrdTemplate, {
            props: { projectId: 1, projectName: 'Test' },
            global: { plugins: [pinia] },
        });
        expect(wrapper.text()).toContain('Loading PRD template');
    });

    it('displays source badge', async () => {
        admin.prdTemplate = { template: '# Test', source: 'project' };
        admin.prdTemplateLoading = false;
        const wrapper = mount(AdminPrdTemplate, {
            props: { projectId: 1, projectName: 'Test' },
            global: { plugins: [pinia] },
        });
        expect(wrapper.find('[data-testid="template-source"]').text()).toBe('Project');
    });

    it('shows textarea with template content', async () => {
        admin.prdTemplate = { template: '# My Template', source: 'default' };
        admin.prdTemplateLoading = false;
        const wrapper = mount(AdminPrdTemplate, {
            props: { projectId: 1, projectName: 'Test' },
            global: { plugins: [pinia] },
        });
        const textarea = wrapper.find('[data-testid="template-editor"]');
        expect(textarea.exists()).toBe(true);
    });

    it('shows reset checkbox only when project override exists', async () => {
        admin.prdTemplate = { template: '# Test', source: 'default' };
        admin.prdTemplateLoading = false;
        const wrapper = mount(AdminPrdTemplate, {
            props: { projectId: 1, projectName: 'Test' },
            global: { plugins: [pinia] },
        });
        expect(wrapper.find('[data-testid="reset-default-checkbox"]').exists()).toBe(false);

        // Set to project override
        admin.prdTemplate = { template: '# Custom', source: 'project' };
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="reset-default-checkbox"]').exists()).toBe(true);
    });

    it('has a save button', () => {
        admin.prdTemplate = { template: '# Test', source: 'default' };
        admin.prdTemplateLoading = false;
        const wrapper = mount(AdminPrdTemplate, {
            props: { projectId: 1, projectName: 'Test' },
            global: { plugins: [pinia] },
        });
        expect(wrapper.find('[data-testid="save-template-btn"]').exists()).toBe(true);
    });
});
```

**Step 2: Run the tests**

Run: `npx vitest run resources/js/components/AdminPrdTemplate.test.js`
Expected: All PASS

**Step 3: Commit**

```bash
git add resources/js/components/AdminPrdTemplate.test.js
git commit --no-gpg-sign -m "T93.8: Add AdminPrdTemplate component tests"
```

---

### Task 9: Run full verification

**Step 1: Run PHP tests**

```bash
php artisan test --parallel
```

Expected: All pass.

**Step 2: Run Vue tests**

```bash
npx vitest run
```

Expected: All pass.

**Step 3: Run M5 verification script (if it exists)**

```bash
python3 verify/verify_m5.py
```

If it doesn't exist yet, structural checks pass implicitly.

**Step 4: Final commit (progress + handoff)**

Update `progress.md`: check T93 box, bold T94, update summary.
Clear `handoff.md`.

```bash
git add progress.md handoff.md CLAUDE.md
git commit --no-gpg-sign -m "T93: Mark complete, update progress"
```

# T90: Admin Page — Global Settings Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build the global settings tab in the Admin page, allowing admins to view and edit AI model, language, timeout, token limit, pricing, API key status (display-only per D153), and team chat webhook configuration.

**Architecture:** New `AdminSettingsController` with `index` (GET all settings + API key status) and `update` (PUT bulk-update settings). Frontend adds a `Settings` tab to the existing `AdminPage.vue` with a new `AdminGlobalSettings.vue` component. The Pinia admin store gets `settings`, `fetchSettings()`, and `updateSettings()` additions.

**Tech Stack:** Laravel 11 (Controller, FormRequest, Resource), Vue 3 (Composition API, `<script setup>`), Pinia, Pest (feature tests), Vitest (component tests)

---

### Task 1: Create AdminSettingsController with index endpoint

**Files:**
- Create: `app/Http/Controllers/Api/AdminSettingsController.php`
- Create: `app/Http/Resources/GlobalSettingResource.php`
- Modify: `routes/api.php`

**Step 1: Write the failing test**

Create `tests/Feature/AdminSettingsApiTest.php`:

```php
<?php

use App\Models\GlobalSetting;
use App\Models\Permission;
use App\Models\Project;
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

function createSettingsAdmin(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'admin']);
    $perm = Permission::firstOrCreate(
        ['name' => 'admin.global_config'],
        ['description' => 'Can edit global Vunnix settings', 'group' => 'admin']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

function createNonSettingsAdmin(Project $project): User
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

// ─── GET /admin/settings ────────────────────────────────────────

it('returns settings list for admin', function () {
    $project = Project::factory()->create();
    $user = createSettingsAdmin($project);

    GlobalSetting::set('ai_model', 'opus', 'string', 'Default AI model');
    GlobalSetting::set('ai_language', 'en', 'string', 'AI response language');

    $response = $this->actingAs($user)->getJson('/api/v1/admin/settings');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['key', 'value', 'type', 'description'],
            ],
            'api_key_configured',
            'defaults',
        ]);
});

it('includes api_key_configured status', function () {
    $project = Project::factory()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->getJson('/api/v1/admin/settings');

    $response->assertOk()
        ->assertJsonPath('api_key_configured', fn ($v) => is_bool($v));
});

it('includes defaults for reference', function () {
    $project = Project::factory()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->getJson('/api/v1/admin/settings');

    $response->assertOk()
        ->assertJsonPath('defaults.ai_model', 'opus')
        ->assertJsonPath('defaults.ai_language', 'en')
        ->assertJsonPath('defaults.timeout_minutes', 10)
        ->assertJsonPath('defaults.max_tokens', 8192);
});

it('returns 403 for non-admin user', function () {
    $project = Project::factory()->create();
    $user = createNonSettingsAdmin($project);

    $response = $this->actingAs($user)->getJson('/api/v1/admin/settings');

    $response->assertForbidden();
});

it('returns 401 for unauthenticated request', function () {
    $response = $this->getJson('/api/v1/admin/settings');

    $response->assertUnauthorized();
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdminSettingsApiTest`
Expected: FAIL — route not defined

**Step 3: Create GlobalSettingResource**

Create `app/Http/Resources/GlobalSettingResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GlobalSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'type' => $this->type,
            'description' => $this->description,
            'bot_pat_created_at' => $this->bot_pat_created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
```

**Step 4: Create AdminSettingsController**

Create `app/Http/Controllers/Api/AdminSettingsController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GlobalSettingResource;
use App\Models\GlobalSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeSettingsAdmin($request);

        $settings = GlobalSetting::orderBy('key')->get();

        return response()->json([
            'data' => GlobalSettingResource::collection($settings),
            'api_key_configured' => ! empty(config('services.anthropic.api_key') ?: env('ANTHROPIC_API_KEY')),
            'defaults' => GlobalSetting::defaults(),
        ]);
    }

    private function authorizeSettingsAdmin(Request $request): void
    {
        $user = $request->user();

        $hasAdmin = $user->projects()
            ->get()
            ->contains(fn ($project) => $user->hasPermission('admin.global_config', $project));

        if (! $hasAdmin) {
            abort(403, 'Settings management access required.');
        }
    }
}
```

**Step 5: Add route to api.php**

Add after line 124 (admin users route), inside the `auth` middleware group:

```php
        // Admin global settings (T90)
        Route::get('/admin/settings', [AdminSettingsController::class, 'index'])
            ->name('api.admin.settings.index');
```

Add the import at the top of `api.php`:

```php
use App\Http\Controllers\Api\AdminSettingsController;
```

**Step 6: Run test to verify it passes**

Run: `php artisan test --filter=AdminSettingsApiTest`
Expected: All 5 tests PASS

**Step 7: Commit**

```bash
git add app/Http/Controllers/Api/AdminSettingsController.php \
    app/Http/Resources/GlobalSettingResource.php \
    routes/api.php \
    tests/Feature/AdminSettingsApiTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T90.1: Add AdminSettingsController with GET /admin/settings endpoint

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Add update endpoint to AdminSettingsController

**Files:**
- Modify: `app/Http/Controllers/Api/AdminSettingsController.php`
- Create: `app/Http/Requests/Admin/UpdateSettingsRequest.php`
- Modify: `routes/api.php`
- Modify: `tests/Feature/AdminSettingsApiTest.php`

**Step 1: Write the failing tests**

Append to `tests/Feature/AdminSettingsApiTest.php`:

```php
// ─── PUT /admin/settings ────────────────────────────────────────

it('updates a single setting', function () {
    $project = Project::factory()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'ai_model', 'value' => 'sonnet', 'type' => 'string'],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    expect(GlobalSetting::get('ai_model'))->toBe('sonnet');
});

it('updates multiple settings at once', function () {
    $project = Project::factory()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'ai_model', 'value' => 'sonnet', 'type' => 'string'],
            ['key' => 'timeout_minutes', 'value' => 15, 'type' => 'integer'],
            ['key' => 'ai_language', 'value' => 'ja', 'type' => 'string'],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    expect(GlobalSetting::get('ai_model'))->toBe('sonnet');
    expect(GlobalSetting::get('timeout_minutes'))->toBe(15);
    expect(GlobalSetting::get('ai_language'))->toBe('ja');
});

it('updates json-type settings', function () {
    $project = Project::factory()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'ai_prices', 'value' => ['input' => 3.0, 'output' => 15.0], 'type' => 'json'],
        ],
    ]);

    $response->assertOk();

    $prices = GlobalSetting::get('ai_prices');
    expect($prices)->toBeArray();
    expect($prices['input'])->toBe(3.0);
    expect($prices['output'])->toBe(15.0);
});

it('updates bot_pat_created_at via settings', function () {
    $project = Project::factory()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'bot_pat_created_at', 'value' => '2026-01-15T00:00:00Z'],
        ],
    ]);

    $response->assertOk();

    $setting = GlobalSetting::where('key', 'bot_pat_created_at')->first();
    expect($setting)->not->toBeNull();
});

it('updates team chat webhook settings', function () {
    $project = Project::factory()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'team_chat_webhook_url', 'value' => 'https://hooks.slack.com/services/T00/B00/xxx', 'type' => 'string'],
            ['key' => 'team_chat_platform', 'value' => 'slack', 'type' => 'string'],
        ],
    ]);

    $response->assertOk();
    expect(GlobalSetting::get('team_chat_webhook_url'))->toBe('https://hooks.slack.com/services/T00/B00/xxx');
    expect(GlobalSetting::get('team_chat_platform'))->toBe('slack');
});

it('rejects update with empty settings array', function () {
    $project = Project::factory()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [],
    ]);

    $response->assertUnprocessable();
});

it('rejects update without settings key', function () {
    $project = Project::factory()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->putJson('/api/v1/admin/settings', []);

    $response->assertUnprocessable();
});

it('returns 403 for non-admin on update', function () {
    $project = Project::factory()->create();
    $user = createNonSettingsAdmin($project);

    $response = $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'ai_model', 'value' => 'sonnet', 'type' => 'string'],
        ],
    ]);

    $response->assertForbidden();
});

it('returns updated settings list after update', function () {
    $project = Project::factory()->create();
    $user = createSettingsAdmin($project);

    $response = $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'ai_model', 'value' => 'sonnet', 'type' => 'string'],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['key', 'value', 'type'],
            ],
        ]);
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AdminSettingsApiTest`
Expected: New tests FAIL — PUT route not defined

**Step 3: Create UpdateSettingsRequest**

Create `app/Http/Requests/Admin/UpdateSettingsRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller handles authorization
    }

    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.key' => ['required', 'string', 'max:100'],
            'settings.*.value' => ['present'],
            'settings.*.type' => ['sometimes', 'string', 'in:string,boolean,integer,json'],
        ];
    }
}
```

**Step 4: Add update method to AdminSettingsController**

Add to `AdminSettingsController.php`:

```php
use App\Http\Requests\Admin\UpdateSettingsRequest;

public function update(UpdateSettingsRequest $request): JsonResponse
{
    $this->authorizeSettingsAdmin($request);

    foreach ($request->validated()['settings'] as $item) {
        $key = $item['key'];
        $value = $item['value'];
        $type = $item['type'] ?? 'string';

        if ($key === 'bot_pat_created_at') {
            // Special case: bot_pat_created_at is stored as a timestamp column, not in value
            GlobalSetting::updateOrCreate(
                ['key' => $key],
                ['bot_pat_created_at' => $value, 'value' => $value, 'type' => 'string']
            );
            continue;
        }

        GlobalSetting::set($key, $value, $type);
    }

    $settings = GlobalSetting::orderBy('key')->get();

    return response()->json([
        'success' => true,
        'data' => GlobalSettingResource::collection($settings),
    ]);
}
```

**Step 5: Add route**

Add after the GET settings route in `api.php`:

```php
        Route::put('/admin/settings', [AdminSettingsController::class, 'update'])
            ->name('api.admin.settings.update');
```

**Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=AdminSettingsApiTest`
Expected: All tests PASS

**Step 7: Commit**

```bash
git add app/Http/Controllers/Api/AdminSettingsController.php \
    app/Http/Requests/Admin/UpdateSettingsRequest.php \
    routes/api.php \
    tests/Feature/AdminSettingsApiTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T90.2: Add PUT /admin/settings endpoint for bulk settings update

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Add settings state to Pinia admin store

**Files:**
- Modify: `resources/js/stores/admin.js`

**Step 1: Write the failing test**

Create `resources/js/stores/admin.settings.test.js`:

```javascript
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useAdminStore } from '@/stores/admin';
import axios from 'axios';

vi.mock('axios');

describe('Admin Store — Settings (T90)', () => {
    let store;

    beforeEach(() => {
        setActivePinia(createPinia());
        store = useAdminStore();
        vi.clearAllMocks();
    });

    it('initializes settings as empty array', () => {
        expect(store.settings).toEqual([]);
    });

    it('initializes apiKeyConfigured as false', () => {
        expect(store.apiKeyConfigured).toBe(false);
    });

    it('initializes settingsDefaults as empty object', () => {
        expect(store.settingsDefaults).toEqual({});
    });

    describe('fetchSettings', () => {
        it('loads settings from API', async () => {
            axios.get.mockResolvedValue({
                data: {
                    data: [
                        { key: 'ai_model', value: 'opus', type: 'string', description: 'Default AI model' },
                    ],
                    api_key_configured: true,
                    defaults: { ai_model: 'opus', ai_language: 'en', timeout_minutes: 10, max_tokens: 8192 },
                },
            });

            await store.fetchSettings();

            expect(axios.get).toHaveBeenCalledWith('/api/v1/admin/settings');
            expect(store.settings).toHaveLength(1);
            expect(store.settings[0].key).toBe('ai_model');
            expect(store.apiKeyConfigured).toBe(true);
            expect(store.settingsDefaults.ai_model).toBe('opus');
        });

        it('sets settingsError on failure', async () => {
            axios.get.mockRejectedValue(new Error('Network error'));

            await store.fetchSettings();

            expect(store.settingsError).toBe('Failed to load settings.');
        });

        it('sets settingsLoading during fetch', async () => {
            let resolvePromise;
            axios.get.mockReturnValue(new Promise((resolve) => { resolvePromise = resolve; }));

            const fetchPromise = store.fetchSettings();
            expect(store.settingsLoading).toBe(true);

            resolvePromise({ data: { data: [], api_key_configured: false, defaults: {} } });
            await fetchPromise;

            expect(store.settingsLoading).toBe(false);
        });
    });

    describe('updateSettings', () => {
        it('sends PUT request and updates store', async () => {
            axios.put.mockResolvedValue({
                data: {
                    success: true,
                    data: [
                        { key: 'ai_model', value: 'sonnet', type: 'string', description: 'Default AI model' },
                    ],
                },
            });

            const result = await store.updateSettings([
                { key: 'ai_model', value: 'sonnet', type: 'string' },
            ]);

            expect(axios.put).toHaveBeenCalledWith('/api/v1/admin/settings', {
                settings: [{ key: 'ai_model', value: 'sonnet', type: 'string' }],
            });
            expect(result.success).toBe(true);
            expect(store.settings[0].value).toBe('sonnet');
        });

        it('returns error on failure', async () => {
            axios.put.mockRejectedValue({
                response: { data: { error: 'Validation failed' } },
            });

            const result = await store.updateSettings([
                { key: 'ai_model', value: '', type: 'string' },
            ]);

            expect(result.success).toBe(false);
            expect(result.error).toBe('Validation failed');
        });
    });
});
```

**Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/stores/admin.settings.test.js`
Expected: FAIL — `store.settings` undefined, `store.fetchSettings` not a function

**Step 3: Add settings state and methods to admin store**

Add to `resources/js/stores/admin.js` (after the role management block, before the `return` statement):

```javascript
    // ─── Global settings state (T90) ─────────────────────────────
    const settings = ref([]);
    const settingsLoading = ref(false);
    const settingsError = ref(null);
    const apiKeyConfigured = ref(false);
    const settingsDefaults = ref({});

    async function fetchSettings() {
        settingsLoading.value = true;
        settingsError.value = null;
        try {
            const { data } = await axios.get('/api/v1/admin/settings');
            settings.value = data.data;
            apiKeyConfigured.value = data.api_key_configured;
            settingsDefaults.value = data.defaults;
        } catch (e) {
            settingsError.value = 'Failed to load settings.';
        } finally {
            settingsLoading.value = false;
        }
    }

    async function updateSettings(settingsList) {
        try {
            const { data } = await axios.put('/api/v1/admin/settings', {
                settings: settingsList,
            });
            if (data.success && data.data) {
                settings.value = data.data;
            }
            return { success: true };
        } catch (e) {
            return { success: false, error: e.response?.data?.error || 'Failed to update settings.' };
        }
    }
```

Add these to the return object:

```javascript
        // Global settings (T90)
        settings,
        settingsLoading,
        settingsError,
        apiKeyConfigured,
        settingsDefaults,
        fetchSettings,
        updateSettings,
```

**Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/stores/admin.settings.test.js`
Expected: All tests PASS

**Step 5: Commit**

```bash
git add resources/js/stores/admin.js \
    resources/js/stores/admin.settings.test.js
git commit --no-gpg-sign -m "$(cat <<'EOF'
T90.3: Add settings state and methods to Pinia admin store

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Create AdminGlobalSettings Vue component

**Files:**
- Create: `resources/js/components/AdminGlobalSettings.vue`

**Step 1: Write the failing test**

Create `resources/js/components/AdminGlobalSettings.test.js`:

```javascript
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import AdminGlobalSettings from './AdminGlobalSettings.vue';
import { useAdminStore } from '@/stores/admin';

vi.mock('axios');

describe('AdminGlobalSettings', () => {
    let pinia;
    let admin;

    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        admin = useAdminStore();

        vi.spyOn(admin, 'fetchSettings').mockResolvedValue();
    });

    it('renders settings heading', () => {
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        expect(wrapper.text()).toContain('Global Settings');
    });

    it('fetches settings on mount', () => {
        mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        expect(admin.fetchSettings).toHaveBeenCalled();
    });

    it('shows loading state', () => {
        admin.settingsLoading = true;
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        expect(wrapper.text()).toContain('Loading settings...');
    });

    it('shows API key configured status', async () => {
        admin.apiKeyConfigured = true;
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="api-key-status"]').text()).toContain('Configured');
    });

    it('shows API key not configured status', async () => {
        admin.apiKeyConfigured = false;
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="api-key-status"]').text()).toContain('Not configured');
    });

    it('renders editable settings fields', async () => {
        admin.settings = [
            { key: 'ai_model', value: 'opus', type: 'string', description: 'Default AI model' },
            { key: 'ai_language', value: 'en', type: 'string', description: 'AI response language' },
            { key: 'timeout_minutes', value: 10, type: 'integer', description: 'Task timeout' },
        ];
        admin.settingsDefaults = { ai_model: 'opus', ai_language: 'en', timeout_minutes: 10, max_tokens: 8192 };

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="setting-ai_model"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="setting-ai_language"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="setting-timeout_minutes"]').exists()).toBe(true);
    });

    it('shows save button', () => {
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="save-settings-btn"]').exists()).toBe(true);
    });

    it('calls updateSettings on save', async () => {
        vi.spyOn(admin, 'updateSettings').mockResolvedValue({ success: true });
        admin.settingsDefaults = { ai_model: 'opus', ai_language: 'en', timeout_minutes: 10, max_tokens: 8192 };

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-settings-btn"]').trigger('click');

        expect(admin.updateSettings).toHaveBeenCalled();
    });

    it('shows success message after save', async () => {
        vi.spyOn(admin, 'updateSettings').mockResolvedValue({ success: true });
        admin.settingsDefaults = { ai_model: 'opus', ai_language: 'en', timeout_minutes: 10, max_tokens: 8192 };

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-settings-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="settings-success"]').exists()).toBe(true);
    });

    it('shows error message on save failure', async () => {
        vi.spyOn(admin, 'updateSettings').mockResolvedValue({ success: false, error: 'Validation failed' });
        admin.settingsDefaults = { ai_model: 'opus', ai_language: 'en', timeout_minutes: 10, max_tokens: 8192 };

        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="save-settings-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="settings-error"]').text()).toContain('Validation failed');
    });

    it('renders team chat webhook section', async () => {
        admin.settingsDefaults = { ai_model: 'opus' };
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="section-team-chat"]').exists()).toBe(true);
    });

    it('renders bot PAT created date field', async () => {
        admin.settingsDefaults = { ai_model: 'opus' };
        const wrapper = mount(AdminGlobalSettings, { global: { plugins: [pinia] } });
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="setting-bot_pat_created_at"]').exists()).toBe(true);
    });
});
```

**Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/components/AdminGlobalSettings.test.js`
Expected: FAIL — component not found

**Step 3: Create AdminGlobalSettings component**

Create `resources/js/components/AdminGlobalSettings.vue`:

```vue
<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import { useAdminStore } from '@/stores/admin';

const admin = useAdminStore();
const saving = ref(false);
const saveSuccess = ref(false);
const saveError = ref(null);

// Local form state — initialized from store settings or defaults
const form = ref({
    ai_model: 'opus',
    ai_language: 'en',
    timeout_minutes: 10,
    max_tokens: 8192,
    ai_prices_input: 5.0,
    ai_prices_output: 25.0,
    team_chat_webhook_url: '',
    team_chat_platform: 'slack',
    bot_pat_created_at: '',
});

const platformOptions = [
    { value: 'slack', label: 'Slack' },
    { value: 'mattermost', label: 'Mattermost' },
    { value: 'google_chat', label: 'Google Chat' },
    { value: 'generic', label: 'Generic Webhook' },
];

onMounted(() => {
    admin.fetchSettings();
});

// Sync form state when settings load from API
watch(() => admin.settings, (newSettings) => {
    for (const s of newSettings) {
        if (s.key === 'ai_prices' && typeof s.value === 'object') {
            form.value.ai_prices_input = s.value.input ?? 5.0;
            form.value.ai_prices_output = s.value.output ?? 25.0;
        } else if (s.key in form.value) {
            form.value[s.key] = s.value;
        }
    }
}, { immediate: true });

// Also seed from defaults when no DB overrides exist
watch(() => admin.settingsDefaults, (defaults) => {
    if (defaults && Object.keys(defaults).length > 0) {
        for (const [key, value] of Object.entries(defaults)) {
            if (key === 'ai_prices' && typeof value === 'object') {
                if (!admin.settings.find(s => s.key === 'ai_prices')) {
                    form.value.ai_prices_input = value.input ?? 5.0;
                    form.value.ai_prices_output = value.output ?? 25.0;
                }
            } else if (key in form.value && !admin.settings.find(s => s.key === key)) {
                form.value[key] = value;
            }
        }
    }
}, { immediate: true });

async function handleSave() {
    saving.value = true;
    saveSuccess.value = false;
    saveError.value = null;

    const settingsList = [
        { key: 'ai_model', value: form.value.ai_model, type: 'string' },
        { key: 'ai_language', value: form.value.ai_language, type: 'string' },
        { key: 'timeout_minutes', value: Number(form.value.timeout_minutes), type: 'integer' },
        { key: 'max_tokens', value: Number(form.value.max_tokens), type: 'integer' },
        { key: 'ai_prices', value: { input: Number(form.value.ai_prices_input), output: Number(form.value.ai_prices_output) }, type: 'json' },
        { key: 'team_chat_webhook_url', value: form.value.team_chat_webhook_url, type: 'string' },
        { key: 'team_chat_platform', value: form.value.team_chat_platform, type: 'string' },
    ];

    // Only include bot_pat_created_at if it has a value
    if (form.value.bot_pat_created_at) {
        settingsList.push({ key: 'bot_pat_created_at', value: form.value.bot_pat_created_at });
    }

    const result = await admin.updateSettings(settingsList);
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
    <h2 class="text-lg font-medium mb-4">Global Settings</h2>

    <!-- Loading state -->
    <div v-if="admin.settingsLoading" class="py-8 text-center text-zinc-500">
      Loading settings...
    </div>

    <template v-else>
      <!-- Success banner -->
      <div v-if="saveSuccess" class="mb-4 rounded-lg border border-green-300 bg-green-50 p-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-400" data-testid="settings-success">
        Settings saved successfully.
      </div>

      <!-- Error banner -->
      <div v-if="saveError" class="mb-4 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400" data-testid="settings-error">
        {{ saveError }}
      </div>

      <!-- API Key Status (display-only per D153) -->
      <div class="mb-6 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <h3 class="text-sm font-medium mb-2">Claude API Key</h3>
        <div data-testid="api-key-status" class="flex items-center gap-2">
          <span
            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
            :class="admin.apiKeyConfigured
              ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'
              : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400'"
          >
            {{ admin.apiKeyConfigured ? 'Configured' : 'Not configured' }}
          </span>
          <span class="text-xs text-zinc-500 dark:text-zinc-400">
            Managed via environment variable (ANTHROPIC_API_KEY)
          </span>
        </div>
      </div>

      <!-- AI Settings -->
      <div class="mb-6 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <h3 class="text-sm font-medium mb-3">AI Configuration</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div data-testid="setting-ai_model">
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Default AI Model</label>
            <select v-model="form.ai_model" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800">
              <option value="opus">Opus</option>
              <option value="sonnet">Sonnet</option>
              <option value="haiku">Haiku</option>
            </select>
          </div>
          <div data-testid="setting-ai_language">
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">AI Response Language</label>
            <input v-model="form.ai_language" type="text" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" placeholder="en" />
          </div>
          <div data-testid="setting-timeout_minutes">
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Task Timeout (minutes)</label>
            <input v-model.number="form.timeout_minutes" type="number" min="1" max="60" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" />
          </div>
          <div data-testid="setting-max_tokens">
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Max Tokens</label>
            <input v-model.number="form.max_tokens" type="number" min="1024" max="200000" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" />
          </div>
        </div>
      </div>

      <!-- Pricing -->
      <div class="mb-6 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <h3 class="text-sm font-medium mb-3">Cost Tracking Prices ($ per million tokens)</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div data-testid="setting-ai_prices_input">
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Input Price</label>
            <input v-model.number="form.ai_prices_input" type="number" step="0.01" min="0" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" />
          </div>
          <div data-testid="setting-ai_prices_output">
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Output Price</label>
            <input v-model.number="form.ai_prices_output" type="number" step="0.01" min="0" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" />
          </div>
        </div>
      </div>

      <!-- Team Chat Notifications -->
      <div class="mb-6 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-testid="section-team-chat">
        <h3 class="text-sm font-medium mb-3">Team Chat Notifications</h3>
        <div class="space-y-3">
          <div>
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Webhook URL</label>
            <input v-model="form.team_chat_webhook_url" type="url" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" placeholder="https://hooks.slack.com/services/..." data-testid="setting-team_chat_webhook_url" />
          </div>
          <div>
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Platform</label>
            <select v-model="form.team_chat_platform" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="setting-team_chat_platform">
              <option v-for="opt in platformOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Bot PAT Rotation (D144) -->
      <div class="mb-6 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <h3 class="text-sm font-medium mb-3">Bot Personal Access Token</h3>
        <div data-testid="setting-bot_pat_created_at">
          <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">PAT Creation Date</label>
          <input v-model="form.bot_pat_created_at" type="date" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" />
          <p class="mt-1 text-xs text-zinc-400">Used for rotation reminder at 5.5 months (T116)</p>
        </div>
      </div>

      <!-- Save button -->
      <div class="flex items-center gap-3">
        <button
          class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          data-testid="save-settings-btn"
          :disabled="saving"
          @click="handleSave"
        >
          {{ saving ? 'Saving...' : 'Save Settings' }}
        </button>
      </div>
    </template>
  </div>
</template>
```

**Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/components/AdminGlobalSettings.test.js`
Expected: All tests PASS

**Step 5: Commit**

```bash
git add resources/js/components/AdminGlobalSettings.vue \
    resources/js/components/AdminGlobalSettings.test.js
git commit --no-gpg-sign -m "$(cat <<'EOF'
T90.4: Create AdminGlobalSettings Vue component with form and tests

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Wire Settings tab into AdminPage

**Files:**
- Modify: `resources/js/pages/AdminPage.vue`
- Modify: `resources/js/pages/AdminPage.test.js`

**Step 1: Write the failing test**

Add to `resources/js/pages/AdminPage.test.js`:

```javascript
    it('shows Settings tab', () => {
        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        expect(wrapper.find('[data-testid="admin-tab-settings"]').exists()).toBe(true);
    });

    it('switches to Settings tab content on click', async () => {
        const admin = useAdminStore();
        vi.spyOn(admin, 'fetchSettings').mockResolvedValue();

        const wrapper = mount(AdminPage, { global: { plugins: [pinia] } });
        await wrapper.find('[data-testid="admin-tab-settings"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Global Settings');
    });
```

**Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/pages/AdminPage.test.js`
Expected: FAIL — Settings tab not found

**Step 3: Update AdminPage.vue**

In `<script setup>`, add the import:

```javascript
import AdminGlobalSettings from '@/components/AdminGlobalSettings.vue';
```

Add the tab to the tabs array:

```javascript
const tabs = [
    { key: 'projects', label: 'Projects' },
    { key: 'roles', label: 'Roles' },
    { key: 'assignments', label: 'Assignments' },
    { key: 'settings', label: 'Settings' },
];
```

Add the component rendering in template (after the Assignments `v-else-if`):

```html
    <AdminGlobalSettings v-else-if="activeTab === 'settings'" />
```

**Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/pages/AdminPage.test.js`
Expected: All tests PASS

**Step 5: Commit**

```bash
git add resources/js/pages/AdminPage.vue \
    resources/js/pages/AdminPage.test.js
git commit --no-gpg-sign -m "$(cat <<'EOF'
T90.5: Wire Settings tab into AdminPage

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Add integration test — settings update affects config

**Files:**
- Modify: `tests/Feature/AdminSettingsApiTest.php`

**Step 1: Write the integration test**

Append to `tests/Feature/AdminSettingsApiTest.php`:

```php
// ─── Integration: settings update → config reads ────────────────

it('change AI model → GlobalSetting::get returns new value', function () {
    $project = Project::factory()->create();
    $user = createSettingsAdmin($project);

    // Set initial value
    GlobalSetting::set('ai_model', 'opus', 'string');

    // Update via API
    $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'ai_model', 'value' => 'sonnet', 'type' => 'string'],
        ],
    ])->assertOk();

    // Verify model reads new value (cache should be invalidated)
    expect(GlobalSetting::get('ai_model'))->toBe('sonnet');
});

it('change language → GlobalSetting::get returns new language', function () {
    $project = Project::factory()->create();
    $user = createSettingsAdmin($project);

    GlobalSetting::set('ai_language', 'en', 'string');

    $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'ai_language', 'value' => 'ja', 'type' => 'string'],
        ],
    ])->assertOk();

    expect(GlobalSetting::get('ai_language'))->toBe('ja');
});

it('settings persist across index calls', function () {
    $project = Project::factory()->create();
    $user = createSettingsAdmin($project);

    // Set settings
    $this->actingAs($user)->putJson('/api/v1/admin/settings', [
        'settings' => [
            ['key' => 'ai_model', 'value' => 'haiku', 'type' => 'string'],
            ['key' => 'timeout_minutes', 'value' => 20, 'type' => 'integer'],
        ],
    ])->assertOk();

    // Fetch and verify
    $response = $this->actingAs($user)->getJson('/api/v1/admin/settings');
    $response->assertOk();

    $data = collect($response->json('data'));
    $model = $data->firstWhere('key', 'ai_model');
    $timeout = $data->firstWhere('key', 'timeout_minutes');

    expect($model['value'])->toBe('haiku');
    expect($timeout['value'])->toBe(20);
});
```

**Step 2: Run all tests**

Run: `php artisan test --filter=AdminSettingsApiTest`
Expected: All tests PASS

**Step 3: Commit**

```bash
git add tests/Feature/AdminSettingsApiTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T90.6: Add integration tests for settings update → config propagation

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Run full verification and finalize

**Files:**
- Modify: `progress.md`
- Modify: `handoff.md`

**Step 1: Run full test suite**

```bash
php artisan test --parallel
```

Expected: All tests PASS

**Step 2: Run Vue tests**

```bash
npx vitest run
```

Expected: All tests PASS

**Step 3: Run M5 verification script (if it exists)**

```bash
python3 verify/verify_m5.py 2>/dev/null || echo "M5 verification script not yet created"
```

**Step 4: Update progress.md**

- Check `[x]` for T90
- Bold T91 as next task
- Update milestone count: `M5 — Admin & Configuration (3/18)`
- Update summary: `Tasks Complete: 91 / 116 (78.4%)`

**Step 5: Clear handoff.md**

Reset to empty template.

**Step 6: Commit**

```bash
git add progress.md handoff.md
git commit --no-gpg-sign -m "$(cat <<'EOF'
T90: Complete admin page — global settings

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

**Step 7: Stop.** Do not start the next task.

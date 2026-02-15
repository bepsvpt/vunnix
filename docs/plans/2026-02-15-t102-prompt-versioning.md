# T102: Prompt Versioning — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enable retrospective analysis of prompt changes by tracking prompt versions across all components and exposing version-based filtering in the dashboard.

**Architecture:** The infrastructure is mostly in place — `prompt_version` JSONB column exists on `tasks`, executor skill files and CLAUDE.md already have version headers, and schema classes have `VERSION` constants. Remaining work: add `PROMPT_VERSION` constant to VunnixAgent, expose `prompt_version` in API resources, add a dedicated API endpoint for distinct prompt versions, add dashboard UI filtering by prompt version on the Quality view, and write tests + verification checks.

**Tech Stack:** Laravel 11 (PHP), Vue 3 (Composition API + Pinia), Pest (PHP tests), Vitest (JS tests)

---

### Task 1: Add PROMPT_VERSION constant to VunnixAgent

**Files:**
- Modify: `app/Agents/VunnixAgent.php:64-70`

**Step 1: Write the failing test**

Create `tests/Unit/Agents/VunnixAgentPromptVersionTest.php`:

```php
<?php

use App\Agents\VunnixAgent;

it('exposes a PROMPT_VERSION constant', function () {
    expect(VunnixAgent::PROMPT_VERSION)->toBe('1.0');
});

it('PROMPT_VERSION is a non-empty string', function () {
    expect(VunnixAgent::PROMPT_VERSION)
        ->toBeString()
        ->not->toBeEmpty();
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Agents/VunnixAgentPromptVersionTest.php`
Expected: FAIL — constant does not exist

**Step 3: Write minimal implementation**

Add the constant to `app/Agents/VunnixAgent.php` after the `DEFAULT_MODEL` constant (after line 70):

```php
/**
 * Conversation Engine prompt version.
 *
 * Tracks the system prompt version for retrospective analysis (§14.8, D103).
 * Bump this when the CE system prompt changes meaningfully.
 */
public const PROMPT_VERSION = '1.0';
```

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Agents/VunnixAgentPromptVersionTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add tests/Unit/Agents/VunnixAgentPromptVersionTest.php app/Agents/VunnixAgent.php
git commit --no-gpg-sign -m "T102.1: Add PROMPT_VERSION constant to VunnixAgent

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 2: Expose prompt_version in ExternalTaskResource

**Files:**
- Modify: `app/Http/Resources/ExternalTaskResource.php:12-32`
- Test: `tests/Feature/Http/Controllers/Api/ExternalEndpointsTest.php`

**Step 1: Write the failing test**

Add to the existing `ExternalEndpointsTest.php` file:

```php
it('includes prompt_version in task detail response', function () {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['role' => 'engineer', 'synced_at' => now()]);

    $task = Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Completed,
        'prompt_version' => [
            'skill' => 'frontend-review:1.0',
            'claude_md' => 'executor:1.0',
            'schema' => 'review:1.0',
        ],
    ]);

    $apiKey = createExternalEndpointsAdminUser($user);

    $this->withHeaders(['Authorization' => "Bearer {$apiKey}"])
        ->getJson("/api/v1/ext/tasks/{$task->id}")
        ->assertOk()
        ->assertJsonPath('data.prompt_version.skill', 'frontend-review:1.0')
        ->assertJsonPath('data.prompt_version.claude_md', 'executor:1.0')
        ->assertJsonPath('data.prompt_version.schema', 'review:1.0');
});
```

Note: The helper `createExternalEndpointsAdminUser` should already exist in this test file. If not, use whatever API key setup pattern the existing tests use. Examine the file first.

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter="includes prompt_version in task detail response"`
Expected: FAIL — `prompt_version` not in response

**Step 3: Write minimal implementation**

In `app/Http/Resources/ExternalTaskResource.php`, add `prompt_version` to the returned array:

```php
'prompt_version' => $this->prompt_version,
```

Add it after the `'retry_count'` line, before `'started_at'`.

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter="includes prompt_version in task detail response"`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Http/Resources/ExternalTaskResource.php tests/Feature/Http/Controllers/Api/ExternalEndpointsTest.php
git commit --no-gpg-sign -m "T102.2: Expose prompt_version in ExternalTaskResource

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 3: Add prompt_version filter to ExternalTaskController

**Files:**
- Modify: `app/Http/Controllers/Api/ExternalTaskController.php:19-66`
- Test: `tests/Feature/Http/Controllers/Api/ExternalEndpointsTest.php`

**Step 1: Write the failing test**

Add to `ExternalEndpointsTest.php`:

```php
it('filters tasks by prompt_version skill', function () {
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['role' => 'engineer', 'synced_at' => now()]);

    // Create tasks with different prompt versions
    Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Completed,
        'prompt_version' => [
            'skill' => 'frontend-review:1.0',
            'claude_md' => 'executor:1.0',
            'schema' => 'review:1.0',
        ],
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Completed,
        'prompt_version' => [
            'skill' => 'frontend-review:1.1',
            'claude_md' => 'executor:1.0',
            'schema' => 'review:1.0',
        ],
    ]);

    $apiKey = createExternalEndpointsAdminUser($user);

    $this->withHeaders(['Authorization' => "Bearer {$apiKey}"])
        ->getJson('/api/v1/ext/tasks?prompt_version=frontend-review:1.0')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.prompt_version.skill', 'frontend-review:1.0');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter="filters tasks by prompt_version skill"`
Expected: FAIL — filter not implemented

**Step 3: Write minimal implementation**

In `app/Http/Controllers/Api/ExternalTaskController.php`, add the validation rule:

```php
'prompt_version' => ['nullable', 'string', 'max:255'],
```

Then add the filter query after the existing `date_to` filter block:

```php
if ($promptVersion = $request->input('prompt_version')) {
    $query->whereJsonContains('prompt_version->skill', $promptVersion);
}
```

Note: `whereJsonContains` works on PostgreSQL with JSONB and SQLite with JSON1. For the `skill` key, we do an exact match on the value inside the JSON object.

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter="filters tasks by prompt_version skill"`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Http/Controllers/Api/ExternalTaskController.php tests/Feature/Http/Controllers/Api/ExternalEndpointsTest.php
git commit --no-gpg-sign -m "T102.3: Add prompt_version filter to task list API

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 4: Add prompt versions list API endpoint

**Files:**
- Create: `app/Http/Controllers/Api/PromptVersionController.php`
- Modify: `routes/api.php`

This endpoint returns distinct prompt versions from completed tasks. The dashboard will call it to populate its filter dropdown.

**Step 1: Write the failing test**

Create `tests/Feature/Http/Controllers/Api/PromptVersionControllerTest.php`:

```php
<?php

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

function createPromptVersionTestUser(): array
{
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['role' => 'engineer', 'synced_at' => now()]);
    return [$user, $project];
}

it('returns distinct prompt version skills from completed tasks', function () {
    [$user, $project] = createPromptVersionTestUser();

    Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'frontend-review:1.0', 'claude_md' => 'executor:1.0', 'schema' => 'review:1.0'],
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'frontend-review:1.1', 'claude_md' => 'executor:1.0', 'schema' => 'review:1.0'],
    ]);
    // Duplicate — should not appear twice
    Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'frontend-review:1.0', 'claude_md' => 'executor:1.0', 'schema' => 'review:1.0'],
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/prompt-versions');

    $response->assertOk()
        ->assertJsonStructure(['data'])
        ->assertJsonCount(2, 'data');

    $skills = collect($response->json('data'))->pluck('skill')->all();
    expect($skills)->toContain('frontend-review:1.0')
        ->toContain('frontend-review:1.1');
});

it('scopes prompt versions to user-accessible projects', function () {
    [$user, $project] = createPromptVersionTestUser();

    // Task on accessible project
    Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'backend-review:1.0', 'claude_md' => 'executor:1.0', 'schema' => 'review:1.0'],
    ]);

    // Task on inaccessible project
    $otherProject = Project::factory()->enabled()->create();
    Task::factory()->create([
        'project_id' => $otherProject->id,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'security-audit:2.0', 'claude_md' => 'executor:1.0', 'schema' => 'review:1.0'],
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/prompt-versions');

    $response->assertOk()
        ->assertJsonCount(1, 'data');

    $skills = collect($response->json('data'))->pluck('skill')->all();
    expect($skills)->toContain('backend-review:1.0')
        ->not->toContain('security-audit:2.0');
});

it('excludes tasks with null prompt_version', function () {
    [$user, $project] = createPromptVersionTestUser();

    Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Completed,
        'prompt_version' => null,
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'mixed-review:1.0', 'claude_md' => 'executor:1.0', 'schema' => 'review:1.0'],
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/prompt-versions');

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('requires authentication', function () {
    $this->getJson('/api/v1/prompt-versions')
        ->assertStatus(401);
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Http/Controllers/Api/PromptVersionControllerTest.php`
Expected: FAIL — route not found (404)

**Step 3: Write minimal implementation**

Create `app/Http/Controllers/Api/PromptVersionController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PromptVersionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $projectIds = $request->user()
            ->projects()
            ->where('enabled', true)
            ->pluck('projects.id');

        $driver = DB::connection()->getDriverName();

        // Extract distinct prompt_version->skill values from completed tasks
        if ($driver === 'pgsql') {
            $versions = Task::whereIn('project_id', $projectIds)
                ->where('status', TaskStatus::Completed)
                ->whereNotNull('prompt_version')
                ->selectRaw("DISTINCT prompt_version->>'skill' as skill, prompt_version->>'claude_md' as claude_md, prompt_version->>'schema' as schema")
                ->orderByRaw("prompt_version->>'skill'")
                ->get();
        } else {
            // SQLite fallback for tests
            $versions = Task::whereIn('project_id', $projectIds)
                ->where('status', TaskStatus::Completed)
                ->whereNotNull('prompt_version')
                ->selectRaw("DISTINCT json_extract(prompt_version, '$.skill') as skill, json_extract(prompt_version, '$.claude_md') as claude_md, json_extract(prompt_version, '$.schema') as schema")
                ->orderByRaw("json_extract(prompt_version, '$.skill')")
                ->get();
        }

        return response()->json([
            'data' => $versions->map(fn ($v) => [
                'skill' => $v->skill,
                'claude_md' => $v->claude_md,
                'schema' => $v->schema,
            ])->values(),
        ]);
    }
}
```

Add route to `routes/api.php` inside the authenticated middleware group (alongside other dashboard endpoints):

```php
Route::get('/prompt-versions', \App\Http\Controllers\Api\PromptVersionController::class)
    ->name('api.prompt-versions');
```

Look for the dashboard route group and add this near `dashboard/quality`.

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Http/Controllers/Api/PromptVersionControllerTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Http/Controllers/Api/PromptVersionController.php routes/api.php tests/Feature/Http/Controllers/Api/PromptVersionControllerTest.php
git commit --no-gpg-sign -m "T102.4: Add prompt versions list API endpoint

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 5: Add prompt_version filter to DashboardQualityController

**Files:**
- Modify: `app/Http/Controllers/Api/DashboardQualityController.php`

The quality API should accept an optional `?prompt_version=frontend-review:1.0` parameter to segment metrics by prompt version.

**Step 1: Write the failing test**

Create `tests/Feature/Http/Controllers/Api/DashboardQualityPromptVersionTest.php`:

```php
<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

function createQualityPromptVersionUser(): array
{
    $user = User::factory()->create();
    $project = Project::factory()->enabled()->create();
    $project->users()->attach($user->id, ['role' => 'engineer', 'synced_at' => now()]);
    return [$user, $project];
}

it('filters quality metrics by prompt_version', function () {
    [$user, $project] = createQualityPromptVersionUser();

    // v1.0 review: 1 critical, 1 major
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'frontend-review:1.0', 'claude_md' => 'executor:1.0', 'schema' => 'review:1.0'],
        'result' => [
            'summary' => [
                'total_findings' => 2,
                'findings_by_severity' => ['critical' => 1, 'major' => 1, 'minor' => 0],
            ],
        ],
    ]);

    // v1.1 review: 0 critical, 0 major, 3 minor
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'frontend-review:1.1', 'claude_md' => 'executor:1.0', 'schema' => 'review:1.0'],
        'result' => [
            'summary' => [
                'total_findings' => 3,
                'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 3],
            ],
        ],
    ]);

    // Filter to v1.0 only
    $response = $this->actingAs($user)
        ->getJson('/api/v1/dashboard/quality?prompt_version=frontend-review:1.0');

    $response->assertOk()
        ->assertJsonPath('data.total_reviews', 1)
        ->assertJsonPath('data.total_findings', 2)
        ->assertJsonPath('data.severity_distribution.critical', 1)
        ->assertJsonPath('data.severity_distribution.major', 1)
        ->assertJsonPath('data.severity_distribution.minor', 0);
});

it('returns all reviews when no prompt_version filter is set', function () {
    [$user, $project] = createQualityPromptVersionUser();

    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'frontend-review:1.0', 'claude_md' => 'executor:1.0', 'schema' => 'review:1.0'],
        'result' => [
            'summary' => [
                'total_findings' => 2,
                'findings_by_severity' => ['critical' => 1, 'major' => 1, 'minor' => 0],
            ],
        ],
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'type' => TaskType::CodeReview,
        'status' => TaskStatus::Completed,
        'prompt_version' => ['skill' => 'frontend-review:1.1', 'claude_md' => 'executor:1.0', 'schema' => 'review:1.0'],
        'result' => [
            'summary' => [
                'total_findings' => 3,
                'findings_by_severity' => ['critical' => 0, 'major' => 0, 'minor' => 3],
            ],
        ],
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/dashboard/quality');

    $response->assertOk()
        ->assertJsonPath('data.total_reviews', 2)
        ->assertJsonPath('data.total_findings', 5);
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Http/Controllers/Api/DashboardQualityPromptVersionTest.php`
Expected: FAIL — filter not applied, returns all 2 reviews for the filtered case

**Step 3: Write minimal implementation**

Modify `app/Http/Controllers/Api/DashboardQualityController.php`:

Add validation at the top of `__invoke`:

```php
$request->validate([
    'prompt_version' => ['nullable', 'string', 'max:255'],
]);

$promptVersion = $request->input('prompt_version');
```

In the fallback `else` block where `$reviewTasks` is built, add the prompt_version filter:

```php
$reviewQuery = Task::whereIn('project_id', $projectIds)
    ->where('type', TaskType::CodeReview)
    ->where('status', TaskStatus::Completed)
    ->whereNotNull('result');

if ($promptVersion) {
    $reviewQuery->whereJsonContains('prompt_version->skill', $promptVersion);
}

$reviewTasks = $reviewQuery->get();
```

Also apply the filter to the materialized view path (the `if ($reviewMetrics ...)` branch). When `prompt_version` is specified, skip the materialized view and use the direct query path instead (materialized views don't support per-query filtering):

```php
if (! $promptVersion && $reviewMetrics && (int) $reviewMetrics->task_count > 0) {
    // ... materialized view path (unchanged)
} else {
    // ... direct query path (with prompt_version filter)
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Http/Controllers/Api/DashboardQualityPromptVersionTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Http/Controllers/Api/DashboardQualityController.php tests/Feature/Http/Controllers/Api/DashboardQualityPromptVersionTest.php
git commit --no-gpg-sign -m "T102.5: Add prompt_version filter to quality metrics API

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 6: Add prompt version filter to dashboard store (Pinia)

**Files:**
- Modify: `resources/js/stores/dashboard.js`

**Step 1: Write the failing test**

Create `resources/js/stores/dashboard.prompt-version.test.js`:

```javascript
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useDashboardStore } from './dashboard';
import axios from 'axios';

vi.mock('axios');

describe('dashboard store — prompt version filter', () => {
    let store;

    beforeEach(() => {
        setActivePinia(createPinia());
        store = useDashboardStore();
        axios.get.mockResolvedValue({ data: { data: [] } });
    });

    it('has promptVersionFilter ref initialized to null', () => {
        expect(store.promptVersionFilter).toBe(null);
    });

    it('has promptVersions ref initialized to empty array', () => {
        expect(store.promptVersions).toEqual([]);
    });

    it('fetchPromptVersions populates promptVersions', async () => {
        const mockVersions = [
            { skill: 'frontend-review:1.0', claude_md: 'executor:1.0', schema: 'review:1.0' },
            { skill: 'frontend-review:1.1', claude_md: 'executor:1.0', schema: 'review:1.0' },
        ];
        axios.get.mockResolvedValueOnce({ data: { data: mockVersions } });

        await store.fetchPromptVersions();

        expect(store.promptVersions).toEqual(mockVersions);
        expect(axios.get).toHaveBeenCalledWith('/api/v1/prompt-versions');
    });

    it('fetchQuality passes prompt_version param when filter is set', async () => {
        store.promptVersionFilter = 'frontend-review:1.0';
        axios.get.mockResolvedValueOnce({ data: { data: {} } });

        await store.fetchQuality();

        expect(axios.get).toHaveBeenCalledWith('/api/v1/dashboard/quality', {
            params: { prompt_version: 'frontend-review:1.0' },
        });
    });

    it('fetchQuality sends no prompt_version when filter is null', async () => {
        store.promptVersionFilter = null;
        axios.get.mockResolvedValueOnce({ data: { data: {} } });

        await store.fetchQuality();

        expect(axios.get).toHaveBeenCalledWith('/api/v1/dashboard/quality', {
            params: {},
        });
    });

    it('$reset clears promptVersionFilter and promptVersions', () => {
        store.promptVersionFilter = 'test:1.0';
        store.promptVersions = [{ skill: 'test:1.0' }];

        store.$reset();

        expect(store.promptVersionFilter).toBe(null);
        expect(store.promptVersions).toEqual([]);
    });
});
```

**Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/stores/dashboard.prompt-version.test.js`
Expected: FAIL — `promptVersionFilter` and `promptVersions` undefined

**Step 3: Write minimal implementation**

In `resources/js/stores/dashboard.js`:

1. Add new refs after `projectFilter`:
```javascript
const promptVersionFilter = ref(null); // null = all versions, or skill string like 'frontend-review:1.0'
const promptVersions = ref([]); // Available prompt versions for filter dropdown
```

2. Add `fetchPromptVersions` function:
```javascript
async function fetchPromptVersions() {
    try {
        const response = await axios.get('/api/v1/prompt-versions');
        promptVersions.value = response.data.data;
    } catch (e) {
        // Supplementary — don't block dashboard
    }
}
```

3. Modify `fetchQuality` to pass the filter:
```javascript
async function fetchQuality() {
    qualityLoading.value = true;
    try {
        const params = {};
        if (promptVersionFilter.value) {
            params.prompt_version = promptVersionFilter.value;
        }
        const response = await axios.get('/api/v1/dashboard/quality', { params });
        quality.value = response.data.data;
    } finally {
        qualityLoading.value = false;
    }
}
```

4. Add to `$reset`:
```javascript
promptVersionFilter.value = null;
promptVersions.value = [];
```

5. Add to the return object:
```javascript
promptVersionFilter,
promptVersions,
fetchPromptVersions,
```

**Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/stores/dashboard.prompt-version.test.js`
Expected: PASS

**Step 5: Commit**

```bash
git add resources/js/stores/dashboard.js resources/js/stores/dashboard.prompt-version.test.js
git commit --no-gpg-sign -m "T102.6: Add prompt version filter to dashboard Pinia store

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 7: Add prompt version filter dropdown to DashboardQuality.vue

**Files:**
- Modify: `resources/js/components/DashboardQuality.vue`

**Step 1: Write the failing test**

Add tests to `resources/js/components/DashboardQuality.test.js` (the file should already exist from T95):

```javascript
// Add these tests to the existing describe block or at file level

it('renders prompt version filter dropdown', async () => {
    // Setup mocks so dashboard.quality is populated
    axios.get.mockImplementation((url) => {
        if (url === '/api/v1/dashboard/quality') {
            return Promise.resolve({
                data: {
                    data: {
                        acceptance_rate: null,
                        severity_distribution: { critical: 0, major: 0, minor: 0 },
                        total_findings: 0,
                        total_reviews: 0,
                        avg_findings_per_review: null,
                    },
                },
            });
        }
        if (url === '/api/v1/prompt-versions') {
            return Promise.resolve({
                data: {
                    data: [
                        { skill: 'frontend-review:1.0', claude_md: 'executor:1.0', schema: 'review:1.0' },
                        { skill: 'frontend-review:1.1', claude_md: 'executor:1.0', schema: 'review:1.0' },
                    ],
                },
            });
        }
        if (url === '/api/v1/dashboard/overreliance-alerts') {
            return Promise.resolve({ data: { data: [] } });
        }
        return Promise.resolve({ data: { data: [] } });
    });

    const wrapper = mount(DashboardQuality, { global: { plugins: [pinia] } });
    await flushPromises();

    expect(wrapper.find('[data-testid="prompt-version-filter"]').exists()).toBe(true);
});
```

Note: Adapt imports and setup to match the existing test file's patterns. The test file already has `pinia`, `mount`, `flushPromises` setup.

**Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/components/DashboardQuality.test.js --reporter=verbose`
Expected: FAIL — testid not found

**Step 3: Write minimal implementation**

In `resources/js/components/DashboardQuality.vue`:

Add to `<script setup>`:
```javascript
import { watch } from 'vue';

// Fetch prompt versions on mount
onMounted(() => {
    dashboard.fetchQuality();
    dashboard.fetchOverrelianceAlerts();
    dashboard.fetchPromptVersions();
});

// Re-fetch quality when prompt version filter changes
watch(() => dashboard.promptVersionFilter, () => {
    dashboard.fetchQuality();
});
```

Note: `onMounted` already exists — merge the `fetchPromptVersions` call into it.

Add the dropdown UI right before the "Quality cards" section (after the loading state div, before `<div v-else-if="quality" class="space-y-6">`). Actually, place it inside the `v-else-if="quality"` block at the top:

```html
<!-- Prompt version filter (T102) -->
<div class="flex items-center gap-3 mb-4" v-if="dashboard.promptVersions.length > 0">
    <label class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
        Prompt Version
    </label>
    <select
        data-testid="prompt-version-filter"
        class="text-sm rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 px-3 py-1.5 focus:ring-2 focus:ring-zinc-400 focus:border-zinc-400"
        :value="dashboard.promptVersionFilter"
        @change="dashboard.promptVersionFilter = $event.target.value || null"
    >
        <option value="">All Versions</option>
        <option
            v-for="pv in dashboard.promptVersions"
            :key="pv.skill"
            :value="pv.skill"
        >
            {{ pv.skill }}
        </option>
    </select>
</div>
```

Also add `watch` to the existing import from `vue` (it should already import `computed` and `onMounted`).

**Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/components/DashboardQuality.test.js`
Expected: PASS

**Step 5: Commit**

```bash
git add resources/js/components/DashboardQuality.vue resources/js/components/DashboardQuality.test.js
git commit --no-gpg-sign -m "T102.7: Add prompt version filter dropdown to Quality dashboard

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 8: Add T102 checks to verify_m5.py

**Files:**
- Modify: `verify/verify_m5.py`

**Step 1: Add verification checks**

Append the T102 section before the Summary line (before `checker.summary()`):

```python
# ============================================================
#  T102: Prompt versioning
# ============================================================
section("T102: Prompt Versioning")

# VunnixAgent PROMPT_VERSION constant
checker.check(
    "VunnixAgent has PROMPT_VERSION constant",
    file_contains("app/Agents/VunnixAgent.php", "PROMPT_VERSION"),
)
checker.check(
    "VunnixAgent PROMPT_VERSION is string value",
    file_contains("app/Agents/VunnixAgent.php", "PROMPT_VERSION = '1.0'"),
)

# Executor skill files have version headers
checker.check(
    "frontend-review skill has version header",
    file_contains("executor/.claude/skills/frontend-review.md", "version:"),
)
checker.check(
    "backend-review skill has version header",
    file_contains("executor/.claude/skills/backend-review.md", "version:"),
)
checker.check(
    "security-audit skill has version header",
    file_contains("executor/.claude/skills/security-audit.md", "version:"),
)
checker.check(
    "executor CLAUDE.md has version header",
    file_contains("executor/.claude/CLAUDE.md", "version:"),
)

# Schema VERSION constants
checker.check(
    "CodeReviewSchema has VERSION constant",
    file_contains("app/Schemas/CodeReviewSchema.php", "VERSION = '1.0'"),
)
checker.check(
    "FeatureDevSchema has VERSION constant",
    file_contains("app/Schemas/FeatureDevSchema.php", "VERSION = '1.0'"),
)
checker.check(
    "ActionDispatchSchema has VERSION constant",
    file_contains("app/Schemas/ActionDispatchSchema.php", "VERSION = '1.0'"),
)

# Task model stores prompt_version
checker.check(
    "Task model has prompt_version in fillable",
    file_contains("app/Models/Task.php", "'prompt_version'"),
)
checker.check(
    "Task model casts prompt_version to array",
    file_contains("app/Models/Task.php", "'prompt_version' => 'array'"),
)
checker.check(
    "Tasks migration has prompt_version column",
    file_contains("database/migrations/2024_01_01_000008_create_tasks_table.php", "prompt_version"),
)

# ExternalTaskResource exposes prompt_version
checker.check(
    "ExternalTaskResource includes prompt_version",
    file_contains("app/Http/Resources/ExternalTaskResource.php", "prompt_version"),
)

# Prompt versions API endpoint
checker.check(
    "PromptVersionController exists",
    file_exists("app/Http/Controllers/Api/PromptVersionController.php"),
)
checker.check(
    "Prompt versions route registered",
    file_contains("routes/api.php", "prompt-versions"),
)

# Quality dashboard filtering
checker.check(
    "DashboardQualityController accepts prompt_version param",
    file_contains("app/Http/Controllers/Api/DashboardQualityController.php", "prompt_version"),
)
checker.check(
    "ExternalTaskController accepts prompt_version param",
    file_contains("app/Http/Controllers/Api/ExternalTaskController.php", "prompt_version"),
)

# Dashboard store
checker.check(
    "Dashboard store has promptVersionFilter",
    file_contains("resources/js/stores/dashboard.js", "promptVersionFilter"),
)
checker.check(
    "Dashboard store has promptVersions",
    file_contains("resources/js/stores/dashboard.js", "promptVersions"),
)
checker.check(
    "Dashboard store has fetchPromptVersions",
    file_contains("resources/js/stores/dashboard.js", "fetchPromptVersions"),
)

# Dashboard Quality UI
checker.check(
    "DashboardQuality has prompt version filter UI",
    file_contains("resources/js/components/DashboardQuality.vue", "prompt-version-filter"),
)

# Tests
checker.check(
    "VunnixAgent prompt version test exists",
    file_exists("tests/Unit/Agents/VunnixAgentPromptVersionTest.php"),
)
checker.check(
    "PromptVersionController test exists",
    file_exists("tests/Feature/Http/Controllers/Api/PromptVersionControllerTest.php"),
)
checker.check(
    "Dashboard quality prompt version test exists",
    file_exists("tests/Feature/Http/Controllers/Api/DashboardQualityPromptVersionTest.php"),
)
checker.check(
    "Dashboard store prompt version test exists",
    file_exists("resources/js/stores/dashboard.prompt-version.test.js"),
)
```

**Step 2: Run verification**

Run: `python3 verify/verify_m5.py`
Expected: All T102 checks pass (if all previous tasks are committed)

**Step 3: Commit**

```bash
git add verify/verify_m5.py
git commit --no-gpg-sign -m "T102.8: Add T102 verification checks to verify_m5.py

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 9: Run full verification and make final commit

**Step 1: Run Laravel tests**

```bash
php artisan test --parallel
```

Expected: All tests pass

**Step 2: Run M5 verification**

```bash
python3 verify/verify_m5.py
```

Expected: All checks pass including T102 section

**Step 3: Update progress.md**

Mark T102 as complete `[x]`, bold the next task (T103), update the summary counts:

- M5 progress: 15/18
- Tasks complete: 103 / 116 (88.8%)
- Current Task: T103
- Last Verified: T102

**Step 4: Update handoff.md**

Clear handoff.md back to empty template (task complete).

**Step 5: Final commit**

```bash
git add progress.md handoff.md CLAUDE.md
git commit --no-gpg-sign -m "T102: Complete prompt versioning — mark task done

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Architecture Notes for Implementor

### What already exists (DO NOT recreate):
- `prompt_version` JSONB column in `tasks` table (migration `2024_01_01_000008`)
- `prompt_version` in Task model `$fillable` and `$casts`
- Version headers in all 7 executor skill files (`version: "1.0"`, `updated: "2026-02-14"`)
- Version header in `executor/.claude/CLAUDE.md`
- `VERSION = '1.0'` constants in `CodeReviewSchema`, `FeatureDevSchema`, `ActionDispatchSchema`
- `StoreTaskResultRequest` validation for `prompt_version.skill`, `.claude_md`, `.schema`
- `TaskResultController` stores `prompt_version` on task (line 62)

### What this plan adds:
1. `PROMPT_VERSION = '1.0'` constant on `VunnixAgent`
2. `prompt_version` exposed in `ExternalTaskResource`
3. `?prompt_version=` filter on `ExternalTaskController::index`
4. `GET /api/v1/prompt-versions` endpoint for distinct versions
5. `?prompt_version=` filter on `DashboardQualityController`
6. Pinia store: `promptVersionFilter`, `promptVersions`, `fetchPromptVersions()`
7. `DashboardQuality.vue` filter dropdown UI
8. Verification checks in `verify_m5.py`

### Testing approach:
- Pure unit test for `PROMPT_VERSION` constant (no TestCase, no DB)
- Feature tests with `RefreshDatabase` for API endpoints
- Vitest store tests with mocked axios
- Vitest component test extending existing `DashboardQuality.test.js`

### Database compatibility:
- `whereJsonContains` works on both PostgreSQL and SQLite
- `PromptVersionController` uses driver detection for `json_extract` (SQLite) vs `->>'key'` (PostgreSQL)
- This follows the existing pattern from `DashboardOverviewController` and the `strftime`/`TO_CHAR` learning in CLAUDE.md

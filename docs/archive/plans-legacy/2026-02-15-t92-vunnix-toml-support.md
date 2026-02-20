# T92: Optional .vunnix.toml Support — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Read an optional `.vunnix.toml` config file from a project's GitLab repo via API on task dispatch, inserting it as a new layer in the 4-level config hierarchy (global defaults → global DB → file → project DB).

**Architecture:** A new `VunnixTomlService` reads `.vunnix.toml` via `GitLabClient::getFile()`, parses it with `yosymfony/toml`, and flattens the nested TOML structure into dot-notation keys matching `ProjectConfigService::settingKeys()`. The `ProjectConfigService::get()` method gains a new optional `$ref` parameter so callers (like `TaskDispatcher`) can supply a git ref. File-sourced config sits between global DB settings and project DB overrides — DB always wins. File reads are cached per project+ref with a short TTL (5 minutes). Task dispatch stores the resolved file config in the task's `result` JSONB for auditability.

**Tech Stack:** PHP 8.2, Laravel 12, `yosymfony/toml` ^1.0, Pest testing

---

### Task 1: Install yosymfony/toml

**Files:**
- Modify: `composer.json`

**Step 1: Install TOML parser**

Run: `composer require yosymfony/toml`

**Step 2: Verify installation**

Run: `php -r "require 'vendor/autoload.php'; echo Yosymfony\Toml\Toml::parse('[general]\nkey = \"value\"')['general']['key'];"`
Expected: `value`

**Step 3: Commit**

```bash
git add composer.json composer.lock
git commit --no-gpg-sign -m "$(cat <<'EOF'
T92.1: Install yosymfony/toml for .vunnix.toml parsing

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Create VunnixTomlService — unit tests

**Files:**
- Create: `tests/Unit/Services/VunnixTomlServiceTest.php`

**Step 1: Write the failing tests**

These tests validate TOML parsing, key flattening, key filtering, and error handling — all as pure unit tests with Mockery (no Laravel container needed for parsing logic).

```php
<?php

use App\Services\GitLabClient;
use App\Services\VunnixTomlService;
use App\Exceptions\GitLabApiException;

// Note: No uses(TestCase::class) — this is a pure unit test.
// VunnixTomlService is instantiated directly with a mocked GitLabClient.

afterEach(function () {
    Mockery::close();
});

it('parses valid .vunnix.toml and returns flattened settings', function () {
    $tomlContent = <<<'TOML'
[general]
model = "sonnet"
max_tokens = 4096
timeout_minutes = 5

[code_review]
auto_review = false
severity_threshold = "critical"

[feature_dev]
enabled = true
branch_prefix = "bot/"
TOML;

    $gitLabClient = Mockery::mock(GitLabClient::class);
    $gitLabClient->shouldReceive('getFile')
        ->with(100, '.vunnix.toml', 'abc123')
        ->once()
        ->andReturn([
            'content' => base64_encode($tomlContent),
            'encoding' => 'base64',
        ]);

    $service = new VunnixTomlService($gitLabClient);
    $result = $service->read(100, 'abc123');

    expect($result)->toBe([
        'ai_model' => 'sonnet',
        'max_tokens' => 4096,
        'timeout_minutes' => 5,
        'code_review.auto_review' => false,
        'code_review.severity_threshold' => 'critical',
        'feature_dev.enabled' => true,
        'feature_dev.branch_prefix' => 'bot/',
    ]);
});

it('maps [general] keys to top-level config keys', function () {
    $tomlContent = <<<'TOML'
[general]
model = "haiku"
language = "ja"
TOML;

    $gitLabClient = Mockery::mock(GitLabClient::class);
    $gitLabClient->shouldReceive('getFile')
        ->once()
        ->andReturn([
            'content' => base64_encode($tomlContent),
            'encoding' => 'base64',
        ]);

    $service = new VunnixTomlService($gitLabClient);
    $result = $service->read(100, 'main');

    // [general].model → ai_model, [general].language → ai_language
    expect($result)->toBe([
        'ai_model' => 'haiku',
        'ai_language' => 'ja',
    ]);
});

it('ignores unknown keys not in settingKeys()', function () {
    $tomlContent = <<<'TOML'
[general]
model = "opus"
unknown_key = "should be ignored"

[code_review]
auto_review = true
nonexistent_field = 42

[totally_unknown_section]
foo = "bar"
TOML;

    $gitLabClient = Mockery::mock(GitLabClient::class);
    $gitLabClient->shouldReceive('getFile')
        ->once()
        ->andReturn([
            'content' => base64_encode($tomlContent),
            'encoding' => 'base64',
        ]);

    $service = new VunnixTomlService($gitLabClient);
    $result = $service->read(100, 'main');

    expect($result)->toBe([
        'ai_model' => 'opus',
        'code_review.auto_review' => true,
    ]);
});

it('returns empty array when file does not exist (404)', function () {
    $gitLabClient = Mockery::mock(GitLabClient::class);
    $gitLabClient->shouldReceive('getFile')
        ->once()
        ->andThrow(new GitLabApiException('File not found', 404));

    $service = new VunnixTomlService($gitLabClient);
    $result = $service->read(100, 'main');

    expect($result)->toBe([]);
});

it('returns empty array when TOML is malformed', function () {
    $gitLabClient = Mockery::mock(GitLabClient::class);
    $gitLabClient->shouldReceive('getFile')
        ->once()
        ->andReturn([
            'content' => base64_encode('this is [not valid toml ==='),
            'encoding' => 'base64',
        ]);

    $service = new VunnixTomlService($gitLabClient);
    $result = $service->read(100, 'main');

    expect($result)->toBe([]);
});

it('returns empty array on GitLab API error (non-404)', function () {
    $gitLabClient = Mockery::mock(GitLabClient::class);
    $gitLabClient->shouldReceive('getFile')
        ->once()
        ->andThrow(new GitLabApiException('Server error', 500));

    $service = new VunnixTomlService($gitLabClient);
    $result = $service->read(100, 'main');

    expect($result)->toBe([]);
});

it('handles all supported setting keys from TOML sections', function () {
    $tomlContent = <<<'TOML'
[general]
model = "opus"
language = "en"
timeout_minutes = 10
max_tokens = 8192

[code_review]
auto_review = true
auto_review_on_push = false
severity_threshold = "major"

[feature_dev]
enabled = true
branch_prefix = "ai/"
auto_create_mr = true

[conversation]
enabled = true
max_history_messages = 100
tool_use_gitlab = true

[ui_adjustment]
dev_server_command = "npm run dev"
screenshot_base_url = "http://localhost:3000"
screenshot_wait_ms = 3000

[labels]
auto_label = true
risk_labels = true
TOML;

    $gitLabClient = Mockery::mock(GitLabClient::class);
    $gitLabClient->shouldReceive('getFile')
        ->once()
        ->andReturn([
            'content' => base64_encode($tomlContent),
            'encoding' => 'base64',
        ]);

    $service = new VunnixTomlService($gitLabClient);
    $result = $service->read(100, 'main');

    expect($result)->toHaveCount(17)
        ->and($result['ai_model'])->toBe('opus')
        ->and($result['ai_language'])->toBe('en')
        ->and($result['code_review.auto_review'])->toBe(true)
        ->and($result['feature_dev.branch_prefix'])->toBe('ai/')
        ->and($result['conversation.enabled'])->toBe(true)
        ->and($result['ui_adjustment.screenshot_wait_ms'])->toBe(3000)
        ->and($result['labels.auto_label'])->toBe(true);
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/Services/VunnixTomlServiceTest.php`
Expected: FAIL — `VunnixTomlService` class not found

---

### Task 3: Create VunnixTomlService — implementation

**Files:**
- Create: `app/Services/VunnixTomlService.php`

**Step 1: Implement VunnixTomlService**

```php
<?php

namespace App\Services;

use App\Exceptions\GitLabApiException;
use Illuminate\Support\Facades\Log;
use Yosymfony\Toml\Toml;

class VunnixTomlService
{
    private const FILE_PATH = '.vunnix.toml';

    /**
     * Map from TOML [general] keys to flat config keys.
     * Keys in other sections map as "{section}.{key}".
     */
    private const GENERAL_KEY_MAP = [
        'model' => 'ai_model',
        'language' => 'ai_language',
        'timeout_minutes' => 'timeout_minutes',
        'max_tokens' => 'max_tokens',
    ];

    public function __construct(
        private readonly GitLabClient $gitLabClient,
    ) {}

    /**
     * Read and parse .vunnix.toml from a GitLab project repo.
     *
     * Returns a flat key→value map using dot-notation matching
     * ProjectConfigService::settingKeys(). Unknown keys are ignored.
     * Returns empty array if file is missing, unreadable, or malformed.
     *
     * @return array<string, mixed>
     */
    public function read(int $gitlabProjectId, string $ref = 'main'): array
    {
        try {
            $fileData = $this->gitLabClient->getFile($gitlabProjectId, self::FILE_PATH, $ref);
        } catch (GitLabApiException $e) {
            if ($e->getCode() !== 404) {
                Log::warning('VunnixTomlService: failed to read .vunnix.toml', [
                    'project_id' => $gitlabProjectId,
                    'ref' => $ref,
                    'error' => $e->getMessage(),
                ]);
            }

            return [];
        }

        $content = $this->decodeContent($fileData);
        if ($content === null) {
            return [];
        }

        return $this->parseAndFlatten($content, $gitlabProjectId);
    }

    private function decodeContent(array $fileData): ?string
    {
        $encoding = $fileData['encoding'] ?? 'base64';
        $raw = $fileData['content'] ?? '';

        if ($encoding === 'base64') {
            $decoded = base64_decode($raw, true);

            return $decoded !== false ? $decoded : null;
        }

        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAndFlatten(string $tomlContent, int $gitlabProjectId): array
    {
        try {
            $parsed = Toml::parse($tomlContent);
        } catch (\Throwable $e) {
            Log::warning('VunnixTomlService: malformed .vunnix.toml', [
                'project_id' => $gitlabProjectId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $allowedKeys = array_keys(ProjectConfigService::settingKeys());
        $result = [];

        foreach ($parsed as $section => $values) {
            if (! is_array($values)) {
                continue;
            }

            if ($section === 'general') {
                foreach ($values as $key => $value) {
                    $mappedKey = self::GENERAL_KEY_MAP[$key] ?? null;
                    if ($mappedKey !== null && in_array($mappedKey, $allowedKeys, true)) {
                        $result[$mappedKey] = $value;
                    }
                }
            } else {
                foreach ($values as $key => $value) {
                    $flatKey = "{$section}.{$key}";
                    if (in_array($flatKey, $allowedKeys, true)) {
                        $result[$flatKey] = $value;
                    }
                }
            }
        }

        return $result;
    }
}
```

**Step 2: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Services/VunnixTomlServiceTest.php`
Expected: All 7 tests PASS

**Step 3: Commit**

```bash
git add app/Services/VunnixTomlService.php tests/Unit/Services/VunnixTomlServiceTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T92.2: Create VunnixTomlService with TOML parsing and key mapping

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Integrate file config into ProjectConfigService — tests

**Files:**
- Modify: `tests/Unit/Services/ProjectConfigServiceTest.php`

**Step 1: Add tests for the new 4-level hierarchy**

Add these tests at the end of the existing file. They test that file config is used when no project override exists, and that project DB overrides always win over file config.

```php
it('uses file config when no project override exists', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);

    $service = new ProjectConfigService();
    $result = $service->getWithFileConfig($project, 'ai_model', ['ai_model' => 'sonnet']);

    expect($result)->toBe('sonnet');
});

it('project DB override takes precedence over file config', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'haiku'],
    ]);

    $service = new ProjectConfigService();
    $result = $service->getWithFileConfig($project, 'ai_model', ['ai_model' => 'sonnet']);

    expect($result)->toBe('haiku');
});

it('file config takes precedence over global setting', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);
    GlobalSetting::set('ai_model', 'haiku', 'string');

    $service = new ProjectConfigService();
    $result = $service->getWithFileConfig($project, 'ai_model', ['ai_model' => 'sonnet']);

    expect($result)->toBe('sonnet');
});

it('file config for nested key works with dot-notation', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => [],
    ]);

    $service = new ProjectConfigService();
    $result = $service->getWithFileConfig($project, 'code_review.auto_review', [
        'code_review.auto_review' => false,
    ]);

    expect($result)->toBe(false);
});

it('allEffective includes file source indicator', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'haiku'], // DB override
    ]);

    $service = new ProjectConfigService();
    $effective = $service->allEffective($project, [
        'code_review.auto_review' => false,  // file config (no DB override)
        'ai_model' => 'sonnet',              // file config (but DB overrides)
    ]);

    // DB override wins over file
    expect($effective['ai_model'])->toEqual(['value' => 'haiku', 'source' => 'project']);
    // File config used (no DB override)
    expect($effective['code_review.auto_review'])->toEqual(['value' => false, 'source' => 'file']);
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/Services/ProjectConfigServiceTest.php`
Expected: FAIL — `getWithFileConfig` method not found, `allEffective` doesn't accept 2nd arg

---

### Task 5: Integrate file config into ProjectConfigService — implementation

**Files:**
- Modify: `app/Services/ProjectConfigService.php`

**Step 1: Add `getWithFileConfig()` method and update `allEffective()`**

Add a new method `getWithFileConfig()` that accepts pre-fetched file config. Update `allEffective()` to accept an optional file config parameter.

The key design decision: we do NOT have `get()` fetch the file itself. The file is fetched once at task dispatch time, and the flat array is passed in. This avoids coupling the config service to GitLab I/O and keeps it testable without HTTP mocking.

Add these methods to `ProjectConfigService`:

```php
/**
 * Get a resolved config value with file config layer:
 * project override → file config → global → default.
 *
 * @param array<string, mixed> $fileConfig Pre-fetched flat .vunnix.toml settings
 */
public function getWithFileConfig(Project $project, string $key, array $fileConfig, mixed $default = null): mixed
{
    $settings = $this->getProjectSettings($project);

    // 1. Project DB override (highest priority)
    $value = Arr::get($settings, $key);
    if ($value !== null) {
        return $value;
    }

    // 2. File config (.vunnix.toml)
    if (array_key_exists($key, $fileConfig)) {
        return $fileConfig[$key];
    }

    // 3. Global setting (top-level keys only)
    $topKey = explode('.', $key)[0];
    if ($topKey === $key) {
        return GlobalSetting::get($key, $default);
    }

    return $default;
}
```

Update `allEffective()` signature to accept optional file config:

```php
/**
 * Get all effective settings for a project with source indicators.
 * Returns: ['key' => ['value' => mixed, 'source' => 'project'|'file'|'global'|'default']]
 *
 * @param array<string, mixed> $fileConfig Pre-fetched flat .vunnix.toml settings
 */
public function allEffective(Project $project, array $fileConfig = []): array
```

In the method body, add file config layer between global and project layers:

```php
// Layer file config on top of globals (before project overrides)
foreach ($fileConfig as $key => $value) {
    $result[$key] = ['value' => $value, 'source' => 'file'];
}
```

**Step 2: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Services/ProjectConfigServiceTest.php`
Expected: All tests PASS (old + new)

**Step 3: Run integration tests to confirm no regression**

Run: `php artisan test tests/Feature/ProjectConfigResolutionTest.php`
Expected: All tests PASS

**Step 4: Commit**

```bash
git add app/Services/ProjectConfigService.php tests/Unit/Services/ProjectConfigServiceTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T92.3: Add file config layer to ProjectConfigService resolution

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Wire VunnixTomlService into TaskDispatcher — tests

**Files:**
- Modify: `tests/Feature/Services/TaskDispatcherTest.php`

**Step 1: Add tests for .vunnix.toml integration in task dispatch**

Add at the end of the file:

```php
// ─── T92: .vunnix.toml file config ──────────────────────────────

it('reads .vunnix.toml from repo and stores file_config in task result', function () {
    $project = Project::factory()->create(['gitlab_project_id' => 100]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token',
    ]);

    $tomlContent = base64_encode("[general]\nmodel = \"sonnet\"\n\n[code_review]\nauto_review = false");

    Http::fake([
        '*/api/v4/projects/100/repository/files/.vunnix.toml*' => Http::response([
            'content' => $tomlContent,
            'encoding' => 'base64',
        ]),
        '*/api/v4/projects/100/trigger/pipeline' => Http::response([
            'id' => 1000,
            'status' => 'pending',
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::FeatureDev,
        'project_id' => $project->id,
        'mr_iid' => null,
        'issue_iid' => 5,
        'commit_sha' => 'abc123',
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->result['file_config'])->toBe([
        'ai_model' => 'sonnet',
        'code_review.auto_review' => false,
    ]);
});

it('gracefully handles missing .vunnix.toml (404)', function () {
    $project = Project::factory()->create(['gitlab_project_id' => 100]);
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'ci_trigger_token' => 'test-trigger-token',
    ]);

    Http::fake([
        '*/api/v4/projects/100/repository/files/.vunnix.toml*' => Http::response(['message' => '404 File Not Found'], 404),
        '*/api/v4/projects/100/trigger/pipeline' => Http::response([
            'id' => 1000,
            'status' => 'pending',
        ]),
    ]);

    $task = Task::factory()->queued()->create([
        'type' => TaskType::FeatureDev,
        'project_id' => $project->id,
        'mr_iid' => null,
        'issue_iid' => 5,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Running)
        ->and($task->result)->not->toHaveKey('file_config');
});

it('does not read .vunnix.toml for server-side tasks', function () {
    Http::fake();

    $task = Task::factory()->queued()->create([
        'type' => TaskType::PrdCreation,
        'mr_iid' => null,
        'issue_iid' => 10,
    ]);

    $dispatcher = app(TaskDispatcher::class);
    $dispatcher->dispatch($task);

    // Server-side tasks don't trigger GitLab file reads
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), '.vunnix.toml');
    });
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Services/TaskDispatcherTest.php --filter="vunnix.toml"`
Expected: FAIL — TaskDispatcher doesn't read .vunnix.toml yet

---

### Task 7: Wire VunnixTomlService into TaskDispatcher — implementation

**Files:**
- Modify: `app/Services/TaskDispatcher.php`

**Step 1: Inject VunnixTomlService and read file on dispatch**

Update `TaskDispatcher::__construct()` to accept `VunnixTomlService`:

```php
public function __construct(
    private readonly GitLabClient $gitLabClient,
    private readonly StrategyResolver $strategyResolver,
    private readonly TaskTokenService $taskTokenService,
    private readonly VunnixTomlService $vunnixTomlService,
) {}
```

In `dispatchToRunner()`, after line 100 (strategy stored in result), before the trigger token check, add:

```php
// T92: Read optional .vunnix.toml from repo
$fileConfig = $this->vunnixTomlService->read(
    $task->project->gitlab_project_id,
    $task->commit_sha ?? 'main',
);

if (! empty($fileConfig)) {
    $task->result = array_merge($task->result ?? [], [
        'file_config' => $fileConfig,
    ]);
}
```

**Step 2: Run the full TaskDispatcher test suite**

Run: `php artisan test tests/Feature/Services/TaskDispatcherTest.php`
Expected: All tests PASS

Note: Existing tests that use `Http::fake()` without a `.vunnix.toml` endpoint will trigger a real HTTP call that returns a `ConnectionException` which `GitLabApiException` catches — this falls through to the `[]` return. **If this causes failures**, add a blanket `'*/repository/files/*' => Http::response([], 404)` to the existing fakes. Check after running.

**Step 3: Commit**

```bash
git add app/Services/TaskDispatcher.php tests/Feature/Services/TaskDispatcherTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T92.4: Wire VunnixTomlService into TaskDispatcher for file config on dispatch

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Integration test — full config resolution with file layer

**Files:**
- Modify: `tests/Feature/ProjectConfigResolutionTest.php`

**Step 1: Add integration test for full 4-level hierarchy**

```php
it('resolves config with 4-level hierarchy: default → global → file → project DB', function () {
    $project = Project::factory()->create();
    ProjectConfig::factory()->create([
        'project_id' => $project->id,
        'settings' => ['ai_model' => 'haiku'], // DB override for ai_model only
    ]);

    // Global override for ai_language
    GlobalSetting::set('ai_language', 'ja', 'string');

    // File config provides: ai_model (will be overridden by DB),
    // code_review.auto_review (no DB override — file wins),
    // ai_language (has global but no DB — file wins)
    $fileConfig = [
        'ai_model' => 'sonnet',                 // overridden by project DB
        'code_review.auto_review' => false,      // file wins (no DB override)
        'ai_language' => 'de',                   // file wins over global
    ];

    $service = app(ProjectConfigService::class);

    // Project DB overrides file → 'haiku'
    expect($service->getWithFileConfig($project, 'ai_model', $fileConfig))->toBe('haiku');
    // File config wins (no project DB override) → false
    expect($service->getWithFileConfig($project, 'code_review.auto_review', $fileConfig))->toBe(false);
    // File config wins over global → 'de'
    expect($service->getWithFileConfig($project, 'ai_language', $fileConfig))->toBe('de');
    // No file config, no DB → falls back to global default → 8192
    expect($service->getWithFileConfig($project, 'max_tokens', $fileConfig))->toBe(8192);
});
```

**Step 2: Run integration tests**

Run: `php artisan test tests/Feature/ProjectConfigResolutionTest.php`
Expected: All tests PASS

**Step 3: Commit**

```bash
git add tests/Feature/ProjectConfigResolutionTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T92.5: Add integration test for 4-level config hierarchy with file layer

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Run full verification

**Step 1: Run full test suite**

Run: `php artisan test --parallel`
Expected: All tests PASS

**Step 2: Run M5 structural verification**

Run: `python3 verify/verify_m5.py`
Expected: PASS (or update the script if it needs T92 checks)

**Step 3: Final commit (if verify script needed updates)**

Only if `verify_m5.py` needed changes.

---

### Task 10: Update progress.md and handoff.md

**Step 1: Mark T92 complete in progress.md**

- Check `[x]` for T92
- Bold the next task (T93)
- Update milestone count: `5/18`
- Update summary task count: `93 / 116 (80.2%)`

**Step 2: Clear handoff.md**

Reset to empty template.

**Step 3: Commit**

```bash
git add progress.md handoff.md
git commit --no-gpg-sign -m "$(cat <<'EOF'
T92: Complete optional .vunnix.toml support

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

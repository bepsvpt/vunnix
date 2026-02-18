# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Vunnix is an AI-powered development platform for self-hosted GitLab Free — conversational AI + event-driven code review orchestrator.

## Status

Codebase feature-complete (107/107 code tasks across M1–M5). Remaining tasks (T106–T114) are manual operations: VM deployment, GitLab setup, pilot verification, monitoring, and rollout.

## Specification

Complete specification: **`docs/spec/vunnix-v1.md`** (155 decisions, 116 tasks, 7 milestones, ~3,900 lines).

All task descriptions, dependencies, acceptance criteria, and verification specs are in §21 (Implementation Roadmap). Test strategy is in §22. The Discussion Log contains rationale for all 155 decisions.

## Commands

| Command | Description |
|---|---|
| `composer setup` | Full setup: install deps, create .env, generate key, build frontend |
| `composer dev` | Start dev environment (server + queue + logs + Vite, all concurrent) |
| `composer test` | Run PHP tests (clears config cache first) |
| `php artisan test --parallel` | Run full PHP suite (required — single-process OOMs at 900+ tests) |
| `php artisan test --filter=ClassName` | Run a specific test file |
| `npm test` | Run Vue/JS tests (Vitest) |
| `composer test:coverage` | PHP tests with coverage (text + HTML report in coverage/php/) |
| `npm run test:coverage` | JS tests with coverage (text + HTML report in coverage/js/) |
| `npm run dev` | Vite dev server only (frontend) |
| `npm run build` | Production frontend build |
| `composer analyse` | Run PHPStan static analysis (level 8 + strict rules) |
| `composer audit` | Scan PHP dependencies for known security vulnerabilities (built-in) |
| `composer audit:all` | Scan both PHP and JS dependencies for known vulnerabilities |
| `composer rector` | Run Rector in dry-run mode (preview changes without applying) |
| `composer rector:fix` | Apply Rector auto-fixes (type declarations, dead code, code quality) |
| `composer ide-helper:models` | Regenerate Eloquent model PHPDoc annotations (requires DB connection) |
| `npm run typecheck` | Run vue-tsc type checking on frontend (strict mode) |
| `npm run lint` | Run ESLint on frontend (check for violations) |
| `npm run lint:fix` | Run ESLint with auto-fix on frontend |
| `composer format` | Run Laravel Pint (auto-fix code style) |
| `composer format:check` | Check code style without fixing (CI mode) |
| `docker compose up -d` | Start Docker services (PostgreSQL, Redis) |
| `docker compose down && docker compose up -d` | Full restart (required after code changes — see Learnings) |

**Prerequisites:** Docker services must be running. See `docs/local-dev-setup.md` for first-time setup.

## Tech Stack

| Component | Technology |
|---|---|
| **Backend** | Laravel 12 + Octane (FrankenPHP driver) |
| **Frontend** | Vue 3 SPA (Composition API, `<script setup lang="ts">`) + TypeScript (strict) + Vite + Pinia + Vue Router + Zod |
| **Database** | PostgreSQL 18 (JSONB, materialized views, full-text search via tsvector + GIN) |
| **Cache / Queue** | Redis (separate DBs for cache, session, queue) |
| **Real-time** | Laravel Reverb (WebSocket) + SSE (chat streaming) |
| **AI (Chat)** | Laravel AI SDK (`laravel/ai`) — Agent classes with tools, middleware, structured output |
| **AI (Executor)** | `claude` CLI in Docker image on GitLab Runner |
| **Testing (PHP)** | Pest (`pestphp/pest`) — unit, feature, integration, browser |
| **Testing (Vue)** | Vitest + Vue Test Utils |
| **Testing (E2E)** | Pest Browser Testing (powered by Playwright) |
| **Markdown** | `markdown-it` + `@shikijs/markdown-it` for syntax highlighting |

## Architecture

```
app/
├── Agents/              # AI SDK Agent classes (Conversation Engine)
│   └── Tools/           # AI SDK Tool classes (GitLab browse, search, etc.)
├── Http/Controllers/    # API + webhook controllers
│   └── Api/             # /api/v1/ resource controllers
├── Http/Requests/       # FormRequest validation classes
├── Http/Resources/      # Eloquent API Resource transformers
├── Services/            # Business logic (GitLabClient, EventRouter, CostCalculation, etc.)
├── Jobs/                # Queue jobs (result posting, label mapping, etc.)
├── Models/              # Eloquent models
└── Policies/            # Authorization policies
resources/js/
├── components/          # Reusable Vue components
├── pages/               # Page-level components (Chat, Dashboard, Admin)
├── stores/              # Pinia stores
├── router/              # Vue Router config
├── composables/         # Shared composition functions
├── lib/                 # Utilities (markdown renderer, axios config)
└── types/               # TypeScript types — Zod schemas (api.ts), enums (enums.ts), barrel (index.ts)
tests/
├── Unit/                # Pure unit tests (Mockery, no Laravel container)
└── Feature/             # Laravel feature tests (HTTP, DB, queue)
```

### Data Flows

**Chat pipeline:** User message → `ConversationController::stream()` → `ConversationService::streamResponse()` → `VunnixAgent::stream()` (with tools: BrowseRepoTree, ReadFile, SearchCode, ListIssues, ReadIssue, ListMergeRequests, ReadMergeRequest, ReadMRDiff, DispatchAction) → Claude API → `StreamableAgentResponse` (SSE: `text_delta`, `tool_call`, `tool_result` events). The AI SDK's `RemembersConversations` trait persists messages. `PruneConversationHistory` middleware summarizes old turns when conversation exceeds 20 turns.

**Code review pipeline:** GitLab webhook → `WebhookController` → parse into typed `WebhookEvent` DTO (MergeRequestOpened, NoteOnMR, IssueLabelChanged, PushToMRBranch, etc.) → `EventRouter::route()` classifies intent (auto_review, on_demand_review, improve, ask_command, issue_discussion, feature_dev, incremental_review) → `EventDeduplicator` (latest-wins, D140) → permission check → `TaskDispatchService::dispatch()` creates Task model → `ProcessTask` job → `TaskDispatcher` chooses execution mode:
- **Server-side** (PrdCreation): immediate `ProcessTaskResult`
- **Runner** (CodeReview, FeatureDev, UiAdjustment, IssueDiscussion, DeepAnalysis): triggers GitLab CI pipeline with `VUNNIX_*` env vars → executor posts result back → `ProcessTaskResult`

`ProcessTaskResult` then dispatches downstream jobs by task type: `PostSummaryComment` → `PostInlineThreads` → `PostLabelsAndStatus` (code review), `PostAnswerComment` (@ai ask), `PostIssueComment` (@ai on issues), `PostFeatureDevResult` (feature dev), `CreateGitLabIssue` (PRD creation).

**Real-time:** Task status transitions fire `TaskStatusChanged` event → Reverb broadcasts to `task.{id}` and `project.{projectId}.activity` channels → Vue SPA receives via Laravel Echo. `MetricsUpdated` broadcasts dashboard metric refreshes.

**Queue topology (D134):** `vunnix-server` (immediate I/O-bound jobs) + `vunnix-runner-{high,normal,low}` (CI pipeline tasks).

## CI/CD

GitHub Actions (`.github/workflows/`):
- **`tests.yml`** — Pint formatting check, ESLint, PHPStan (level 8), PHP tests (parallel, real PostgreSQL 18, pcov coverage), JS tests (Vitest with coverage)
- **`build-app.yml`** — Docker image build & publish to GHCR (`ghcr.io/bepsvpt/vunnix/app`) on release
- **`build-executor.yml`** — Executor Docker image build & publish on release

PHP tests in CI run against real PostgreSQL 18 (not SQLite). Coverage reports uploaded as artifacts.

## Coding Standards

### PHP (Laravel)

- **Style:** PSR-12, enforce with Laravel Pint (auto-formatted via Claude Code hook + CI)
- **Static analysis:** PHPStan level 8 + strict rules + Larastan
- **Validation:** Always use FormRequest classes for HTTP input validation
- **Services:** Business logic in Service classes, not controllers
- **API responses:** Use Eloquent API Resources
- **Authorization:** Gate/Policy for all authorization checks
- **No raw SQL** — use Eloquent / Query Builder
- **Naming:** PascalCase for classes, camelCase for methods/variables, snake_case for DB columns

### Vue 3 + TypeScript

- **Style:** Composition API with `<script setup lang="ts">` (no Options API)
- **TypeScript:** Strict mode (`strict: true`), `verbatimModuleSyntax: true`, `vue-tsc` for type checking
- **Types:** Zod schemas in `resources/js/types/api.ts` as single source of truth for API response types — provides both compile-time types (`z.infer<>`) and runtime validation (`.parse()`)
- **No `any`:** Banned via ESLint rule `ts/no-explicit-any: error` — use `unknown` + type guards
- **Props:** Use type-based `defineProps<T>()` (not runtime `defineProps({})`)
- **State:** Pinia stores (typed)
- **Formatting:** ESLint (`@antfu/eslint-config` — linting + formatting via ESLint Stylistic, no Prettier)
- **Routing:** Vue Router with history mode (FrankenPHP serves `index.html` for non-API routes)
- **Responsive:** Desktop-first with fluid layouts and breakpoints (D135)

### API Design

- All endpoints under `/api/v1/`
- Cursor-based pagination for all list endpoints (`?cursor=&per_page=25`)
- JSON responses with consistent error format

### Testing

- **PHP:** Pest (unit + feature + integration + browser)
- **Vue:** Vitest + Vue Test Utils
- **External APIs:** `Http::fake()` for GitLab, AI SDK `HasFakes` for Claude
- **Database:** Real PostgreSQL with `RefreshDatabase` or `DatabaseTransactions` (no SQLite)
- **Redis:** Real Redis test database (not array driver)
- **Broadcasting:** `Event::fake()` for Reverb
- **Queue:** `Queue::fake()` or sync driver
- **No live API calls** in automated tests

## Commit Strategy

### Format

```
{imperative description}

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
```

### Rules

- Never commit broken state — every commit should pass verification
- Every code change must include corresponding test updates

## Key Decisions (Quick Reference)

| Decision | Summary |
|---|---|
| **D72** | PostgreSQL (not MySQL) |
| **D69** | FrankenPHP serves both app and static files |
| **D73** | Laravel AI SDK for all AI provider calls |
| **D63** | No third-party GitLab API wrapper — use Laravel HTTP Client |
| **D91** | Claude Opus 4.6 for everything (no model tiering) |
| **D105** | Extended thinking always on for Task Executor |
| **D134** | Separate Redis queues: `vunnix-server` (immediate) + `vunnix-runner` (CI pipeline) |
| **D153** | Anthropic API key in `.env` only — never stored in database |

For all 155 decisions, see the Discussion Log in `docs/spec/vunnix-v1.md`.

## Learnings

Persistent lessons discovered during development. Write each as an actionable rule, not a story. Periodically prune resolved items (one-time configs, fixes already baked into code).

### Database & Migrations

- **PostgreSQL-only migrations must guard against SQLite:** Migrations using `tsvector`, GIN indexes, PL/pgSQL triggers, or `ALTER TABLE` on SDK-provided tables must check `DB::connection()->getDriverName() === 'pgsql'` and `Schema::hasTable(...)` before running. The test environment uses SQLite `:memory:` (phpunit.xml), and the Laravel AI SDK's `agent_conversations` migration has a 2026 timestamp that sorts after our 2024 custom migrations.
- **Wrap all boot-time DB access in try/catch:** `AppServiceProvider::boot()` runs before the test environment swaps the DB connection. Any `Schema::hasTable()` or query call will hit the default `.env` connection (PostgreSQL), not the phpunit.xml SQLite connection. Always wrap the entire block (including `Schema::hasTable`) in a single `try/catch (\Throwable)` that silently returns.
- **Use `strftime` for SQLite, `TO_CHAR` for PostgreSQL in date-grouping queries:** `TO_CHAR(created_at, 'YYYY-MM')` is PostgreSQL-only and fails in SQLite test environments. Check `DB::connection()->getDriverName()` and use `strftime('%Y-%m', created_at)` for SQLite. This applies to any raw SQL date formatting in queries.

### Laravel Internals

- **Use `config('key') ?: 'default'` not `config('key', 'default')` for nullable env vars:** `config('key', 'default')` only uses the default when the key is completely missing from config. If the env var is unset, `env()` returns `null`, the config key exists as `null`, and the default is ignored. Use `?: 'fallback'` to handle both `null` and empty string.
- **Use `present` not `required` for array fields that can be empty:** Laravel's `required` rule rejects empty arrays `[]`. For schema fields like `findings` where zero items is valid (e.g., clean code review), use `'present', 'array'` instead of `'required', 'array'`.
- **`Cache::store()` returns `Repository`, not the underlying store class:** Don't use `assert($store instanceof RedisStore)` — it fails at runtime because the facade returns a `Repository` wrapper. Use `/** @var \Illuminate\Cache\RedisStore $store */ // @phpstan-ignore varTag.type` for accessing store-specific methods like `getRedis()`.
- **Eloquent models with `$incrementing = false` have `null` IDs before persist, despite `@property string $id` PHPDoc:** In `creating` boot callbacks, the ID hasn't been assigned yet and is `null`. Check `$model->id === null || $model->id === ''` with `@phpstan-ignore identical.alwaysFalse` — PHPDoc describes the persisted type, not the pre-persist state.
- **`Toml::parse('')` returns `null`, not an empty array:** The `yosymfony/toml` parser returns `null` for empty or whitespace-only TOML content. Always guard against `null` with `if (! is_array($parsed))` before iterating parsed output. This surfaces in end-to-end tests where `Http::fake()` default fallbacks return empty responses that decode to empty strings passed to the parser.

### Testing

- **Sync queue tests that dispatch jobs making HTTP calls need `Http::fake()`:** When a test uses the sync queue driver (no `Queue::fake()`), dispatched jobs run inline. If those jobs call external APIs (e.g., GitLab), the real HTTP call executes and failures (401, 500) bubble up as the test's HTTP response. Always add `Http::fake()` for external API endpoints in sync-queue integration tests.
- **Unit tests can't use `Http::fake()` — construct HTTP objects manually:** `Http::fake()` requires the Laravel service container (facade root). In `tests/Unit/`, build `RequestException` manually via `new Psr7Response(status, [], body)` → `new Response($psr7)` → `new RequestException($response)`.
- **Use `Log::shouldReceive` (mock) not `Log::spy()` + `shouldHaveReceived` for per-test log assertions:** `Log::spy()` in `beforeEach` accumulates calls across all tests in the file. `shouldHaveReceived('warning')->once()` then sees calls from previous tests. Use `Log::shouldReceive` (strict mock) with expectations *before* the action for tests that assert specific log calls.
- **Don't use `uses(TestCase::class)` in pure unit tests:** Unit tests under `tests/Unit/` that only use Mockery should NOT include `uses(Tests\TestCase::class)`. Booting the Laravel app in a unit test pollutes the container for subsequent pure unit tests (e.g., `Target class [log] does not exist`). Only use `TestCase` when you need the service container, database, or HTTP testing.
- **Wiring new downstream dispatches breaks upstream tests on sync queue:** When adding `SomeJob::dispatch()` inside an existing job (e.g., `ProcessTaskResult`), all upstream tests that trigger that job on the sync queue now run the full chain. Tests that previously expected an intermediate status (e.g., `Running`) may find a terminal status (`Completed` or `Failed`) because downstream jobs execute inline. Fix by providing valid data for the full pipeline or by faking the specific downstream job class with `Queue::fake([NewJob::class])`.
- **`Http::fake()` pattern order matters — specific patterns before broad ones:** Laravel matches faked URL patterns in declaration order using `Str::is()`. A broad pattern like `*/discussions*` will greedily match URLs containing "discussions" deeper in the path (e.g., `*/discussions/disc-1/notes/100/award_emoji`). Always declare more specific patterns first, or use patterns that don't overlap (e.g., `*/notes/100/award_emoji*` before `*/merge_requests/42/discussions*`).
- **Pest helper functions are global — use unique names across test files:** Pest `function` declarations at file scope (outside `it()` blocks) become global PHP functions. If two test files declare the same function name (e.g., `createAdminUser()`), PHP throws `Cannot redeclare function`. Use context-specific prefixes (e.g., `createApiKeyAdminUser()`) or move setup into `beforeEach` closures.

### AI SDK

- **AI SDK Tool schema tests: use `new JsonSchemaTypeFactory` not `app(JsonSchema::class)` in unit tests:** `app()` requires the Laravel container. For pure unit tests (no `uses(TestCase::class)`), instantiate `Illuminate\JsonSchema\JsonSchemaTypeFactory` directly. Keep tool tests as pure unit tests with Mockery — they don't need the framework.
- **AI SDK `Request::string()` returns `Stringable`, not `string`:** The `InteractsWithData` trait's `string()` method returns `Illuminate\Support\Stringable`. Comparing with `!== ''` always evaluates to `true` (object vs string). Cast to `(string)` before comparison or when building arrays that will be matched by Mockery.
- **Never accept AI-provided internal identifiers in tool schemas:** The AI fabricates plausible-looking UUIDs for fields it can't know (e.g., `conversation_id: "conv_123456"`). Resolve internal IDs server-side — use `Context::get()`, `Auth::id()`, or closure injection. Remove unknowable fields from tool input schemas entirely.

### Vue & Frontend

- **Vue component tests that use Pinia stores need `setActivePinia(createPinia())` in `beforeEach`:** When a component calls `useAuthStore()` in `<script setup>`, Pinia must be active before mounting. Create a `pinia` variable in module scope, initialize in `beforeEach`, and pass it as a plugin to `mount()`. Pre-set store state (e.g., `auth.setUser(...)`) before mounting to test authenticated vs. unauthenticated rendering.
- **Page components with `onMounted` API calls cascade into App.test.js:** When a page component (e.g., `DashboardPage`) calls `axios.get()` on mount, `App.test.js` — which renders all routes — will trigger those calls. Add `vi.mock('axios')` and a default `axios.get.mockResolvedValue(...)` in `App.test.js` `beforeEach` to prevent unhandled rejections from cascading page mounts.
- **Vue component tests with `watch` + `onMounted` store calls need all store methods mocked:** When a component has `onMounted` that calls multiple store methods (e.g., `fetchQuality`, `fetchPromptVersions`) and the global axios mock returns `{ data: { data: null } }`, unmocked store methods set reactive state to `null`. If the template uses `.length` on those refs (e.g., `v-if="store.items.length > 0"`), it crashes. Mock all `onMounted` store methods in tests that do interactive mutations (`setValue`, `trigger`), not just the one under test.

### Real-time / Reverb

- **Broadcast events must specify `broadcastQueue('vunnix-server')`:** Events implementing `ShouldBroadcast` without an explicit queue go to `default`, which no Docker worker processes (D134 defines `vunnix-server` + `vunnix-runner-*` only). Events sit unprocessed indefinitely with no error.

### Docker & DevOps

- **Full Docker restart required after code changes:** `docker compose down && docker compose up -d` is required for code changes to take effect. FrankenPHP (Octane) and queue worker processes cache the application in memory — `php artisan octane:reload` or `docker compose restart` are insufficient. Additionally, `docker compose restart` does NOT re-read `.env` variables (they're injected at container creation time). Always use full restart (`down && up`) when changing `.env` or code.

### GitLab API

- **`GITLAB_BOT_ACCOUNT_ID` must be a numeric user ID, not a username:** `ProjectEnablementService::resolveBotUserId()` casts the config value to `(int)`. PHP's `(int) "username"` silently evaluates to `0`, causing `getProjectMember(projectId, 0)` → 404 → "bot is not a member". No error or warning is raised.
- **GitLab Pipeline Triggers API only accepts branch/tag names, not commit SHAs:** `POST /api/v4/projects/:id/trigger/pipeline` returns 400 "Reference not found" when `ref` is a commit SHA. Use the MR's `source_branch` name instead. This is different from the regular pipeline API (`POST /projects/:id/pipeline`) which does accept SHAs.
- **GitLab Pipeline Triggers API must not include PRIVATE-TOKEN header:** `triggerPipeline()` authenticates via the `token` body parameter (trigger token), not the `PRIVATE-TOKEN` header. Sending both causes GitLab to authenticate as the bot user (with stricter variable injection rules) instead of the trigger token, resulting in "empty pipeline" or "insufficient permissions to set pipeline variables".

### Task Pipeline

- **Task `result` JSONB is nullable and polymorphic by type:** The column is `null` before completion, and completed tasks have different keys per type — `title` vs `mr_title`, `findings` vs `analysis` vs `response`, `gitlab_issue_url` vs `mr_iid`. Always null-coalesce (`$result = $task->result ?? []`) before accessing nested fields in events, listeners, and API resources.
- **Re-broadcast `TaskStatusChanged` after downstream jobs update the Task:** When jobs like `PostFeatureDevResult` or `CreateGitLabIssue` save new fields (`mr_iid`, `issue_iid`) after the initial result, the frontend won't see them unless the event re-fires. `DeliverTaskResultToConversation` has an idempotency guard — new downstream jobs should follow the same re-broadcast pattern.

### Static Analysis & Tooling

- **Larastan 3.x doesn't resolve `casts()` method types — add `@return` array shape PHPDoc:** When models use the `casts()` method (Laravel 11+), Larastan can't infer cast types, breaking enum, Carbon, and relationship resolution. Add `@return array{column: 'cast_type'}` PHPDoc with string literal values (e.g., `'App\Enums\TaskStatus'`, `'datetime'`, `'array'`). Without this, all cast columns appear as `string` at level 2+. See [larastan#2387](https://github.com/larastan/larastan/issues/2387).
- **Eloquent relationship methods need generic `@return` PHPDoc for PHPStan level 2+:** Without explicit generics, `$model->relation->property` resolves relationship as `Model` instead of the concrete model. Add `/** @return BelongsTo<Project, $this> */` (or `HasOne`, `HasMany`, `BelongsToMany`) above each relationship method. This enables proper type inference when accessing relationship properties across files.
- **Rector must run before Pint:** Rector's code transformations (type declarations, dead code removal, etc.) may produce formatting that doesn't match Pint's rules. Always run `composer rector:fix` followed by `composer format` to normalize style.
- **Rector `typeDeclarations` misidentifies `ArrayAccess` objects as `array` in closures:** Rector infers `array` from `$obj['key']` usage, but many Laravel classes implement `ArrayAccess` (e.g., `\Illuminate\Http\Client\Request`, `\Illuminate\Foundation\Application`). This breaks `Http::assertSent()` callbacks (expects `Request`, not `array`) and `$this->app->singleton()` callbacks (expects `Application`, not `array`). Always run the full test suite after `composer rector:fix` and review closure parameter type changes.
- **PHPStan correctness ≠ runtime correctness with Laravel:** PHPStan passing doesn't guarantee runtime safety — Laravel uses `ArrayAccess`, magic methods, and facade proxies extensively. Always run the full test suite (`composer test`) after PHPStan-driven refactoring, even when PHPStan reports zero errors.

## Key Files

| File | Purpose |
|---|---|
| `docs/spec/vunnix-v1.md` | Complete specification — source of truth for all requirements |
| `docs/autopilot/` | Autonomous development workflow (runner, progress, handoff, workflow docs) |
| `docs/plans/` | Implementation plans for individual tasks |
| `verify/verify_m{N}.py` | Per-milestone verification scripts |
| `verify/helpers.py` | Shared verification utilities |
| `docker-compose.yml` | Development services |
| `docker-compose.production.yml` | Standalone production compose (GHCR images, resource limits, log rotation) |
| `.env.production.example` | Production environment template |
| `executor/` | Docker image for GitLab Runner |
| `.env` | Environment configuration (secrets, API keys) |
| `docs/spec/decisions-index.md` | Decision lookup table — D1–D155 one-line summaries |
| `docs/assessments/` | Extension assessment artifacts (output of assessing-extensions skill) |
| `docs/extensions/` | Extension planning documents (output of planning-extensions skill) |
| `docs/spikes/` | Spike research results (from spike-required modifier) |

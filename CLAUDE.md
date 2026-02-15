# Vunnix

AI-powered development platform for self-hosted GitLab Free — conversational AI + event-driven code review orchestrator.

## Status

Codebase feature-complete (107/107 code tasks across M1–M5). Remaining tasks (T106–T114) are manual operations: VM deployment, GitLab setup, pilot verification, monitoring, and rollout.

## Specification

Complete specification: **`docs/spec/vunnix-v1.md`** (155 decisions, 116 tasks, 7 milestones, ~3,900 lines).

All task descriptions, dependencies, acceptance criteria, and verification specs are in §21 (Implementation Roadmap). Test strategy is in §22. The Discussion Log contains rationale for all 155 decisions.

## Tech Stack

| Component | Technology |
|---|---|
| **Backend** | Laravel 11 + Octane (FrankenPHP driver) |
| **Frontend** | Vue 3 SPA (Composition API, `<script setup>`) + Vite + Pinia + Vue Router |
| **Database** | PostgreSQL 16 (JSONB, materialized views, full-text search via tsvector + GIN) |
| **Cache / Queue** | Redis (separate DBs for cache, session, queue) |
| **Real-time** | Laravel Reverb (WebSocket) + SSE (chat streaming) |
| **AI (Chat)** | Laravel AI SDK (`laravel/ai`) — Agent classes with tools, middleware, structured output |
| **AI (Executor)** | `claude` CLI in Docker image on GitLab Runner |
| **Testing (PHP)** | Pest (`pestphp/pest`) — unit, feature, integration, browser |
| **Testing (Vue)** | Vitest + Vue Test Utils |
| **Testing (E2E)** | Pest Browser Testing (powered by Playwright) |
| **Markdown** | `markdown-it` + `@shikijs/markdown-it` for syntax highlighting |

## Coding Standards

### PHP (Laravel)

- **Style:** PSR-12, enforce with Laravel Pint
- **Static analysis:** PHPStan
- **Validation:** Always use FormRequest classes for HTTP input validation
- **Services:** Business logic in Service classes, not controllers
- **API responses:** Use Eloquent API Resources
- **Authorization:** Gate/Policy for all authorization checks
- **No raw SQL** — use Eloquent / Query Builder
- **Naming:** PascalCase for classes, camelCase for methods/variables, snake_case for DB columns

### Vue 3

- **Style:** Composition API with `<script setup>` (no Options API)
- **State:** Pinia stores
- **Formatting:** ESLint + Prettier
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

- Always use `--no-gpg-sign` flag
- Never commit broken state — every commit should pass verification

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

Persistent lessons discovered during development. Write each as an actionable rule, not a story. Never remove entries.

- **PostgreSQL-only migrations must guard against SQLite:** Migrations using `tsvector`, GIN indexes, PL/pgSQL triggers, or `ALTER TABLE` on SDK-provided tables must check `DB::connection()->getDriverName() === 'pgsql'` and `Schema::hasTable(...)` before running. The test environment uses SQLite `:memory:` (phpunit.xml), and the Laravel AI SDK's `agent_conversations` migration has a 2026 timestamp that sorts after our 2024 custom migrations.
- **Wrap all boot-time DB access in try/catch:** `AppServiceProvider::boot()` runs before the test environment swaps the DB connection. Any `Schema::hasTable()` or query call will hit the default `.env` connection (PostgreSQL), not the phpunit.xml SQLite connection. Always wrap the entire block (including `Schema::hasTable`) in a single `try/catch (\Throwable)` that silently returns.
- **Use `config('key') ?: 'default'` not `config('key', 'default')` for nullable env vars:** `config('key', 'default')` only uses the default when the key is completely missing from config. If the env var is unset, `env()` returns `null`, the config key exists as `null`, and the default is ignored. Use `?: 'fallback'` to handle both `null` and empty string.
- **Use `present` not `required` for array fields that can be empty:** Laravel's `required` rule rejects empty arrays `[]`. For schema fields like `findings` where zero items is valid (e.g., clean code review), use `'present', 'array'` instead of `'required', 'array'`.
- **Sync queue tests that dispatch jobs making HTTP calls need `Http::fake()`:** When a test uses the sync queue driver (no `Queue::fake()`), dispatched jobs run inline. If those jobs call external APIs (e.g., GitLab), the real HTTP call executes and failures (401, 500) bubble up as the test's HTTP response. Always add `Http::fake()` for external API endpoints in sync-queue integration tests.
- **Unit tests can't use `Http::fake()` — construct HTTP objects manually:** `Http::fake()` requires the Laravel service container (facade root). In `tests/Unit/`, build `RequestException` manually via `new Psr7Response(status, [], body)` → `new Response($psr7)` → `new RequestException($response)`.
- **Use `Log::shouldReceive` (mock) not `Log::spy()` + `shouldHaveReceived` for per-test log assertions:** `Log::spy()` in `beforeEach` accumulates calls across all tests in the file. `shouldHaveReceived('warning')->once()` then sees calls from previous tests. Use `Log::shouldReceive` (strict mock) with expectations *before* the action for tests that assert specific log calls.
- **Don't use `uses(TestCase::class)` in pure unit tests:** Unit tests under `tests/Unit/` that only use Mockery should NOT include `uses(Tests\TestCase::class)`. Booting the Laravel app in a unit test pollutes the container for subsequent pure unit tests (e.g., `Target class [log] does not exist`). Only use `TestCase` when you need the service container, database, or HTTP testing.
- **AI SDK Tool schema tests: use `new JsonSchemaTypeFactory` not `app(JsonSchema::class)` in unit tests:** `app()` requires the Laravel container. For pure unit tests (no `uses(TestCase::class)`), instantiate `Illuminate\JsonSchema\JsonSchemaTypeFactory` directly. Keep tool tests as pure unit tests with Mockery — they don't need the framework.
- **AI SDK `Request::string()` returns `Stringable`, not `string`:** The `InteractsWithData` trait's `string()` method returns `Illuminate\Support\Stringable`. Comparing with `!== ''` always evaluates to `true` (object vs string). Cast to `(string)` before comparison or when building arrays that will be matched by Mockery.
- **Wiring new downstream dispatches breaks upstream tests on sync queue:** When adding `SomeJob::dispatch()` inside an existing job (e.g., `ProcessTaskResult`), all upstream tests that trigger that job on the sync queue now run the full chain. Tests that previously expected an intermediate status (e.g., `Running`) may find a terminal status (`Completed` or `Failed`) because downstream jobs execute inline. Fix by providing valid data for the full pipeline or by faking the specific downstream job class with `Queue::fake([NewJob::class])`.
- **Vue component tests that use Pinia stores need `setActivePinia(createPinia())` in `beforeEach`:** When a component calls `useAuthStore()` in `<script setup>`, Pinia must be active before mounting. Create a `pinia` variable in module scope, initialize in `beforeEach`, and pass it as a plugin to `mount()`. Pre-set store state (e.g., `auth.setUser(...)`) before mounting to test authenticated vs. unauthenticated rendering.
- **Full test suite OOMs in single-process mode — use `--parallel`:** With 900+ tests, `php artisan test` exhausts the 128MB memory limit. Use `php artisan test --parallel` to distribute across worker processes. Individual test files/filters work fine without `--parallel`.
- **JSON encodes whole-number floats as integers — use `int` in `assertJsonPath`:** PHP's `round(8.0, 1)` returns `8.0` (float), but `json_encode` renders it as `8` (integer). `assertJsonPath('key', 8.0)` fails because `json_decode` returns `8` (int) and strict `===` comparison rejects `8 !== 8.0`. Use the integer value in assertions when the float has no fractional part, or use `assertJsonPath` with an `int` cast.
- **Page components with `onMounted` API calls cascade into App.test.js:** When a page component (e.g., `DashboardPage`) calls `axios.get()` on mount, `App.test.js` — which renders all routes — will trigger those calls. Add `vi.mock('axios')` and a default `axios.get.mockResolvedValue(...)` in `App.test.js` `beforeEach` to prevent unhandled rejections from cascading page mounts.
- **Use `strftime` for SQLite, `TO_CHAR` for PostgreSQL in date-grouping queries:** `TO_CHAR(created_at, 'YYYY-MM')` is PostgreSQL-only and fails in SQLite test environments. Check `DB::connection()->getDriverName()` and use `strftime('%Y-%m', created_at)` for SQLite. This applies to any raw SQL date formatting in queries.
- **`Http::fake()` pattern order matters — specific patterns before broad ones:** Laravel matches faked URL patterns in declaration order using `Str::is()`. A broad pattern like `*/discussions*` will greedily match URLs containing "discussions" deeper in the path (e.g., `*/discussions/disc-1/notes/100/award_emoji`). Always declare more specific patterns first, or use patterns that don't overlap (e.g., `*/notes/100/award_emoji*` before `*/merge_requests/42/discussions*`).
- **`Toml::parse('')` returns `null`, not an empty array:** The `yosymfony/toml` parser returns `null` for empty or whitespace-only TOML content. Always guard against `null` with `if (! is_array($parsed))` before iterating parsed output. This surfaces in end-to-end tests where `Http::fake()` default fallbacks return empty responses that decode to empty strings passed to the parser.
- **`evaluateAll` tests must not assert exact event count for system alerts:** `evaluateDiskUsage` calls `disk_free_space()`/`disk_total_space()` on the real filesystem. On machines with high disk usage (>80%), the disk alert fires alongside whatever alert the test intentionally triggers. Use `toContain('expected_type')` instead of `toHaveCount(N)` for `evaluateAll` tests.
- **Pest helper functions are global — use unique names across test files:** Pest `function` declarations at file scope (outside `it()` blocks) become global PHP functions. If two test files declare the same function name (e.g., `createAdminUser()`), PHP throws `Cannot redeclare function`. Use context-specific prefixes (e.g., `createApiKeyAdminUser()`) or move setup into `beforeEach` closures.
- **Vue component tests with `watch` + `onMounted` store calls need all store methods mocked:** When a component has `onMounted` that calls multiple store methods (e.g., `fetchQuality`, `fetchPromptVersions`) and the global axios mock returns `{ data: { data: null } }`, unmocked store methods set reactive state to `null`. If the template uses `.length` on those refs (e.g., `v-if="store.items.length > 0"`), it crashes. Mock all `onMounted` store methods in tests that do interactive mutations (`setValue`, `trigger`), not just the one under test.
- **Carbon `diffInDays()` returns a float, not an int:** Due to microsecond precision in timestamps, `diffInDays()` returns values like `183.0000007` which fail strict `===` comparison against integers. Always cast to `(int)` when storing or asserting day counts.
- **`Process::fake()` doesn't create real output files from shell pipes:** When testing Artisan commands that shell out via `Process::run('pg_dump ... | gzip > file.sql.gz')`, `Process::fake()` intercepts the process but never writes the output file. Don't assert `file_exists()` on the piped target — instead assert on the process command string containing the expected path and program.
- **`docker compose restart` does NOT re-read `.env`:** Docker Compose injects environment variables at container creation time. `restart` only restarts the process inside the existing container. Use `docker compose up -d` to recreate containers with updated `.env` values.
- **`GITLAB_BOT_ACCOUNT_ID` must be a numeric user ID, not a username:** `ProjectEnablementService::resolveBotUserId()` casts the config value to `(int)`. PHP's `(int) "username"` silently evaluates to `0`, causing `getProjectMember(projectId, 0)` → 404 → "bot is not a member". No error or warning is raised.
- **GitLab Pipeline Triggers API only accepts branch/tag names, not commit SHAs:** `POST /api/v4/projects/:id/trigger/pipeline` returns 400 "Reference not found" when `ref` is a commit SHA. Use the MR's `source_branch` name instead. This is different from the regular pipeline API (`POST /projects/:id/pipeline`) which does accept SHAs.
- **GitLab CI `changes:` filter needs split rules for main vs feature branches:** On first push to a new branch, all files appear changed (no baseline). Use `compare_to: refs/heads/main` for feature branches. But on main itself, `compare_to: refs/heads/main` is self-referential (compares the commit to itself → zero changes → no pipeline). Split into two rules: main uses plain `changes:` (previous commit diff), feature branches use `compare_to: refs/heads/main`.
- **Octane + queue workers cache code in memory — restart after changes:** FrankenPHP (Octane) and queue worker processes keep the application cached. Code changes on disk aren't loaded until `php artisan octane:reload` (web) and `docker compose restart queue-server queue-runner` (queues). Both must be restarted during development.
- **GNU coreutils `tr` and `base64` differ from BSD (macOS):** `tr '-_' '+/'` fails on GNU `tr` — the leading `-` is treated as an option flag. Use `tr -- '-_' '+/'`. GNU `base64 -d` requires proper `=` padding while BSD tolerates missing padding. Restore padding before decoding: `case $(( ${#str} % 4 )) in 2) str+="==" ;; 3) str+="=" ;; esac`. Always test shell scripts in the target Docker image, not just the dev machine.
- **GitLab Pipeline Triggers API must not include PRIVATE-TOKEN header:** `triggerPipeline()` authenticates via the `token` body parameter (trigger token), not the `PRIVATE-TOKEN` header. Sending both causes GitLab to authenticate as the bot user (with stricter variable injection rules) instead of the trigger token, resulting in "empty pipeline" or "insufficient permissions to set pipeline variables".
- **Every code change must include corresponding test updates:** When modifying application code (services, controllers, jobs), always add or update tests in the same commit. Never commit behavioral changes without test coverage — tests should be treated as a mandatory part of the change, not a follow-up task.

## Key Files

| File | Purpose |
|---|---|
| `docs/spec/vunnix-v1.md` | Complete specification — source of truth for all requirements |
| `docs/autopilot/` | Autonomous development workflow (runner, progress, handoff, workflow docs) |
| `docs/plans/` | Implementation plans for individual tasks |
| `verify/verify_m{N}.py` | Per-milestone verification scripts |
| `verify/helpers.py` | Shared verification utilities |
| `docker-compose.yml` | Development services |
| `docker-compose.prod.yml` | Production overrides (resource limits, log rotation) |
| `.env.production.example` | Production environment template |
| `executor/` | Docker image for GitLab Runner |
| `.env` | Environment configuration (secrets, API keys) |

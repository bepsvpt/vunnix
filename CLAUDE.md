# Vunnix

AI-powered development platform for self-hosted GitLab Free — conversational AI + event-driven code review orchestrator.

## Resume Instructions

**Read this first on every session start.**

1. Read `progress.md` — find the **bolded** task (that's the current one)
2. Read `handoff.md` — if it has content, it contains sub-step progress, errors encountered, and approach context from the previous session. **Use this to resume mid-task instead of starting fresh.**
3. Run `git log --oneline -5` — see what was last committed
4. Run `git status` — detect uncommitted work from interrupted sessions
5. If uncommitted changes exist:
   - Run `git diff` to review them
   - Cross-reference with `handoff.md` sub-steps to understand what was intentional
   - If the changes look complete → run verification → commit → update progress.md → clear handoff.md
   - If partial → continue implementing from where `handoff.md` left off
6. If no changes and progress.md shows a task in bold → start that task fresh
7. Read the task details in `vunnix.md` §21
8. Implement → verify → update progress.md → clear handoff.md → commit → next task

## Specification

Complete specification: **`vunnix.md`** (155 decisions, 116 tasks, 7 milestones, ~3,900 lines).

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

## Development Workflow

### One Task Per Session (MANDATORY)

**Complete at most ONE task per session.** After verifying and committing a task, **stop**. Do not start the next task. The runner (`run.sh`) will launch a fresh session for the next task.

A single task may require multiple sessions — that's fine. Use `handoff.md` to carry state between sessions.

### Task Lifecycle

1. Find current task in `progress.md` (the **bolded** entry)
2. Check all its dependencies are `[x]` (completed)
3. Read the full task description in `vunnix.md` §21
4. **Write handoff.md** — set the current task, break it into sub-steps
5. Implement the task — **update handoff.md sub-steps as you go** (mark `[x]`, log errors)
6. Write tests per the milestone's Verification subsection in §21
7. Run verification (see protocol below)
8. Update `progress.md`: check the box `[x]`, update milestone count, bold the next task, update summary
9. **Promote learnings** — move any reusable insights from handoff.md "Errors & Blockers" / "Approach & Decisions" into the `## Learnings` section of this file
10. **Clear handoff.md** back to empty template
11. Commit with task reference (commit includes both progress.md and CLAUDE.md if learnings were added)
12. **Stop.** Do not start the next task — the runner will launch a new session.

> **Learnings promotion flow:** handoff.md "Errors & Blockers" → ask yourself *"would a future session hit this same problem?"* → if yes, distill it into a one-line actionable rule and add it to `## Learnings` below.

### Verification Protocol (MANDATORY)

**NEVER mark a task complete without passing verification.**

Run in this order:

```bash
# 1. Laravel tests (when applicable — after T1 scaffold exists)
php artisan test

# 2. Milestone structural checks
python3 verify/verify_m1.py   # (use verify_m{N}.py for current milestone)
```

- **Both must pass** before committing and marking the task done
- If tests fail → fix the issue, do not skip
- If structural checks fail → investigate and fix
- The verification scripts are the gatekeeper — not self-assessment

### Session Handoff Protocol

**`handoff.md` is your mid-task memory.** Maintain it throughout every session to enable seamless resume if the session is interrupted.

#### When to Write

| Event | Action |
|---|---|
| **Starting a task** | Write the task ID, break it into sub-steps with `[ ]` checkboxes |
| **Completing a sub-step** | Mark it `[x]` in handoff.md |
| **Encountering an error** | Log the error message, what caused it, and any attempted fix under "Errors & Blockers" |
| **Making a design decision** | Note it under "Approach & Decisions" so the next session doesn't re-evaluate |
| **Solved a non-obvious problem** | Promote the insight to `## Learnings` in CLAUDE.md — this is how short-term memory becomes long-term |
| **Task fully complete** | Promote any reusable insights from handoff.md to `## Learnings`, then clear handoff.md |

#### Template

```markdown
## Current Task
T{N}: {description}

## Sub-steps
- [x] Completed sub-step
- [ ] Remaining sub-step
- [ ] Another remaining sub-step

## Errors & Blockers
- `composer require foo/bar` failed: requires php ^8.3 — fixed by updating platform config
- Test `test_xyz` fails: expects column that migration hasn't created yet — need to run T3 first

## Approach & Decisions
- Using package X v2.0 instead of v1.x because of Y
- Chose strategy A over B because of Z

## Next Steps
1. Immediate next action
2. Then this
3. Then verify
```

#### Rules

- **Write early, write often** — update after each sub-step, not just at session end
- **Be specific about errors** — include the actual error message, not just "it failed"
- **Don't duplicate progress.md** — handoff.md is for *within-task* state; progress.md is for *cross-task* state
- **Clear on completion** — when a task is verified and committed, reset handoff.md to its empty template

### After Compaction or Session Restart

The file system is the source of truth, not conversation context:

- `CLAUDE.md` (this file) auto-loads — tells you what to do
- `progress.md` — shows exactly which task is current
- `handoff.md` — shows sub-step progress, errors, and context from the previous session
- `git log` — shows what was last committed
- `git status` — shows uncommitted work from interrupted sessions
- `verify/verify_m{N}.py` — confirms what actually works

### Handling Interrupted Tasks

If `git status` shows uncommitted changes from a previous session:

| Scenario | What you see | Action |
|---|---|---|
| **Complete but uncommitted** | All expected files changed, tests pass, handoff.md sub-steps all `[x]` | Run verification → commit → mark done → clear handoff.md |
| **Partial work** | Some files changed, handoff.md shows remaining `[ ]` sub-steps | Continue from the first unchecked sub-step in handoff.md |
| **No changes, handoff has context** | Clean tree, but handoff.md has errors/notes from previous attempt | Read the errors, adjust approach, restart the task |
| **No changes, handoff empty** | Clean working tree, empty handoff.md | Start the bolded task fresh |

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
T{N}: {imperative description}

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
```

### Rules

- Commit after each **verified** task (tests + structural checks pass)
- Always use `--no-gpg-sign` flag
- Sub-task commits for large tasks: `T{N}.{sub}: {description}`
- Never commit broken state — every commit should pass verification

### Milestone Tags

After completing all tasks in a milestone:

```bash
git tag -a m{N}-complete -m "M{N} complete — all tasks verified"
```

### Command Template

```bash
git commit --no-gpg-sign -m "$(cat <<'EOF'
T{N}: {imperative description}

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

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

For all 155 decisions, see the Discussion Log in `vunnix.md`.

## Learnings

Persistent lessons discovered during development. **Add entries here when you solve a non-obvious problem that future sessions might encounter again.** Write each as an actionable rule, not a story. Never remove entries.

- **PostgreSQL-only migrations must guard against SQLite:** Migrations using `tsvector`, GIN indexes, PL/pgSQL triggers, or `ALTER TABLE` on SDK-provided tables must check `DB::connection()->getDriverName() === 'pgsql'` and `Schema::hasTable(...)` before running. The test environment uses SQLite `:memory:` (phpunit.xml), and the Laravel AI SDK's `agent_conversations` migration has a 2026 timestamp that sorts after our 2024 custom migrations.
- **Socialite GitLab driver reads `host` not `instance_uri`:** In `config/services.php`, the GitLab provider key must be `host` (matching what `SocialiteManager::createGitlabDriver()` reads internally).
- **Wrap all boot-time DB access in try/catch:** `AppServiceProvider::boot()` runs before the test environment swaps the DB connection. Any `Schema::hasTable()` or query call will hit the default `.env` connection (PostgreSQL), not the phpunit.xml SQLite connection. Always wrap the entire block (including `Schema::hasTable`) in a single `try/catch (\Throwable)` that silently returns.
- **Use `config('key') ?: 'default'` not `config('key', 'default')` for nullable env vars:** `config('key', 'default')` only uses the default when the key is completely missing from config. If the env var is unset, `env()` returns `null`, the config key exists as `null`, and the default is ignored. Use `?: 'fallback'` to handle both `null` and empty string.
- **Laravel 12 CSRF exclusion uses named parameter syntax:** Use `$middleware->validateCsrfTokens(except: ['webhook'])` — the method `validateCsrfTokenExcept()` does not exist.
- **Use `present` not `required` for array fields that can be empty:** Laravel's `required` rule rejects empty arrays `[]`. For schema fields like `findings` where zero items is valid (e.g., clean code review), use `'present', 'array'` instead of `'required', 'array'`.
- **Sync queue tests that dispatch jobs making HTTP calls need `Http::fake()`:** When a test uses the sync queue driver (no `Queue::fake()`), dispatched jobs run inline. If those jobs call external APIs (e.g., GitLab), the real HTTP call executes and failures (401, 500) bubble up as the test's HTTP response. Always add `Http::fake()` for external API endpoints in sync-queue integration tests.
- **Unit tests can't use `Http::fake()` — construct HTTP objects manually:** `Http::fake()` requires the Laravel service container (facade root). In `tests/Unit/`, build `RequestException` manually via `new Psr7Response(status, [], body)` → `new Response($psr7)` → `new RequestException($response)`.
- **Use `Log::shouldReceive` (mock) not `Log::spy()` + `shouldHaveReceived` for per-test log assertions:** `Log::spy()` in `beforeEach` accumulates calls across all tests in the file. `shouldHaveReceived('warning')->once()` then sees calls from previous tests. Use `Log::shouldReceive` (strict mock) with expectations *before* the action for tests that assert specific log calls.
- **Don't use `uses(TestCase::class)` in pure unit tests:** Unit tests under `tests/Unit/` that only use Mockery should NOT include `uses(Tests\TestCase::class)`. Booting the Laravel app in a unit test pollutes the container for subsequent pure unit tests (e.g., `Target class [log] does not exist`). Only use `TestCase` when you need the service container, database, or HTTP testing.
- **Laravel 11+ base Controller needs `AuthorizesRequests` trait:** The default `Controller` class no longer includes `AuthorizesRequests`. If any controller uses `$this->authorize()`, add `use Illuminate\Foundation\Auth\Access\AuthorizesRequests;` and `use AuthorizesRequests;` to the base `Controller` class.
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

## Key Files

| File | Purpose |
|---|---|
| `vunnix.md` | Complete specification — source of truth for all requirements |
| `progress.md` | Task progress tracker — current state of development |
| `handoff.md` | Session handoff — mid-task state, errors, sub-steps for resume |
| `run.sh` | Autonomous development runner — launches Claude CLI sessions in a loop |
| `verify/verify_m{N}.py` | Per-milestone verification scripts |
| `verify/helpers.py` | Shared verification utilities |
| `verify/watch_progress.sh` | Real-time terminal progress dashboard |
| `docker-compose.yml` | All services (created in T2) |
| `executor/` | Docker image for GitLab Runner (created in T19) |
| `.env` | Environment configuration (secrets, API keys) |

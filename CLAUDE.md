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

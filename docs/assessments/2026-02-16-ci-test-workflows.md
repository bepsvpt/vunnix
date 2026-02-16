# Assessment: CI Test Workflows

**Date:** 2026-02-16
**Requested by:** Kevin
**Trigger:** Build workflows (build-executor, build-app) push Docker images to GHCR without running any tests. The repo has 900+ PHP tests and Vue/Vitest tests, but no CI automation for them.

## What

Add a reusable GitHub Actions test workflow that runs PHP tests (Pest `--parallel` against PostgreSQL) and Vue/JS tests (Vitest), triggered on pull requests and pushes to `main`. Gate the executor build workflow behind passing tests via `workflow_call`. Skip test gates on release builds (build-app) and weekly scheduled executor builds since those operate on already-validated code. No branch protection enforcement — merging is managed manually.

## Classification

**Tier:** 2
**Rationale:** New CI capability (test automation) built on top of existing GitHub Actions infrastructure (ext-001, ext-003). Touches 1 existing file + 1 new file. Requires 2 new decisions (CI test environment strategy, `setup-php` approach). No architectural changes — the test suite, phpunit.xml, and Vitest config already exist and require zero modification.

**Modifiers:**
- [ ] `breaking` — Changes public API, DB schema, or external contracts
- [ ] `multi-repo` — Affects more than one repository
- [ ] `spike-required` — Feasibility uncertain, needs research first
- [ ] `deprecation` — Removes or sunsets existing capability
- [ ] `migration` — Requires data migration or rollout coordination

## Impact Analysis

### Components Affected
| Component | Impact | Files (est.) |
|---|---|---|
| `.github/workflows/tests.yml` | New reusable test workflow (PHP + JS jobs) | 1 new |
| `.github/workflows/build-executor.yml` | Add `workflow_call` test dependency, skip for `schedule` trigger | 1 modified |

### Why phpunit.xml Needs No Changes

PHPUnit's `<env>` directive defaults to `force="false"` (PHPUnit 12 confirmed). This means system environment variables — set by GitHub Actions `env:` blocks — take precedence over XML-defined values. The existing phpunit.xml already uses `<env>` (not `<server>`) for all entries including `DB_CONNECTION=sqlite`. In CI, the workflow sets `DB_CONNECTION=pgsql` as a system env var, which PHPUnit respects without touching the XML.

This is the same pattern used by `laravel/framework` 12.x: their `phpunit.xml.dist` defines a test connection, while CI workflows override via env vars.

### Relevant Decisions
| Decision | Summary | Relationship |
|---|---|---|
| D162 | Vunnix development hosted on GitHub — unlimited free CI | Enables this extension (GitHub Actions is the CI platform) |
| D165 | PHP minimum version `^8.5` | Constrains CI PHP version — `shivammathur/setup-php` v2.36.0 supports 8.5 |
| D166 | PostgreSQL 18 for development and production | CI service container should use PG 18 to match production |
| D167 | Redis 8 for cache/session/queue | CI may need Redis 8 if integration tests require it; phpunit.xml uses array/sync drivers so likely not needed initially |
| D169 | Pest 4 + PHPUnit 12 — requires `--parallel` for 900+ tests | CI must use `php artisan test --parallel` to avoid OOM |
| D125 | Test strategy — Pest + Vitest + AI SDK fakes + Http::fake() | Defines the testing approach that CI automates |

### Dependencies
- **Requires first:** ext-001 (GitHub Actions infrastructure), ext-003 (build-app workflow) — both complete
- **Unblocks:** Reliable quality gates before image publication; future branch protection if desired

## Risk Factors
- **PostgreSQL-specific migrations:** CLAUDE.md documents migrations with `tsvector`, GIN indexes, and PL/pgSQL that guard against non-PostgreSQL drivers. Running against real PostgreSQL in CI validates these guards — and catches regressions that SQLite wouldn't surface.
- **Redis requirement unclear:** phpunit.xml uses array/sync drivers. If all tests pass without Redis in CI, it can be omitted initially and added later if needed.
- **Parallel test workers + PostgreSQL:** ParaTest creates `_test_1`, `_test_2` databases per worker. The CI PostgreSQL service user needs `CREATEDB` permission (default `postgres` superuser has this).
- **CI runtime:** 900+ tests in parallel + npm test. Expected ~3–5 minutes with caching. No cost concern — GitHub Actions is free for public repos (D162).

## Recommendation

Proceed to planning-extensions. This is a well-scoped Tier 2 extension with clear boundaries: 1 new file, 1 modified file, 2 new decisions. All prerequisites are confirmed available: GitHub Actions infrastructure (ext-001), PHP 8.5 in `setup-php`, and PHPUnit env override behavior verified.

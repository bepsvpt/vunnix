## Extension 004: CI Test Workflows

**Status: ✅ Implemented** — `0d53f87`

### Trigger

Build workflows (build-executor, build-app) push Docker images to GHCR without running any tests. The repo has 900+ PHP tests (Pest) and Vue/Vitest tests, but no CI automation. A broken commit on `main` gets built and published as-is.

### Scope

What it does:
- Creates a reusable GitHub Actions test workflow with two parallel jobs: PHP tests (Pest `--parallel` against PostgreSQL 18) and JS tests (Vitest)
- Triggers tests on pull requests and pushes to `main`, and is callable via `workflow_call` from other workflows
- Gates the executor build behind passing tests (skipping the test gate for weekly scheduled builds)
- Uses `shivammathur/setup-php@v2` for PHP 8.5 and workflow-level env vars to override phpunit.xml's SQLite default to PostgreSQL

What it does NOT do:
- Modify `phpunit.xml` — PHPUnit's `<env force="false">` default lets CI env vars take precedence
- Add test gates to `build-app.yml` — release builds operate on already-validated code
- Run tests on the weekly executor schedule — that's for dependency freshness, not code validation
- Configure branch protection rules — merging is managed manually
- Add Redis service containers — phpunit.xml uses array/sync drivers; Redis can be added later if needed

### Architecture Fit

- **Components affected:** `.github/workflows/` (1 new, 1 modified)
- **Extension points used:** GitHub Actions infrastructure from ext-001 (D162); `workflow_call` reusable workflow pattern
- **New tables/endpoints/services:** None

### New Decisions

- **D172:** CI tests run against PostgreSQL 18 service container, not SQLite — workflow env vars (`DB_CONNECTION=pgsql`) override phpunit.xml defaults via PHPUnit's `<env force="false">` behavior. Matches production database engine and catches driver-specific regressions (tsvector, GIN indexes, TO_CHAR) that SQLite would miss. Same pattern used by `laravel/framework` 12.x CI.
- **D173:** Use `shivammathur/setup-php@v2` for PHP 8.5 in CI — confirmed supported since v2.36.0. Simpler than Docker container-based jobs; provides built-in extension installation and Composer caching. Node.js installed via `actions/setup-node@v4` for the JS test job.

### Dependencies

- **Requires:** ext-001 (GitHub Actions infrastructure) — complete
- **Unblocks:** Reliable quality gates before executor image publication; future branch protection if desired

### Tasks

#### T141: Create reusable test workflow
**File(s):** `.github/workflows/tests.yml` (new)
**Action:**
- Add triggers: `pull_request`, `push: branches: [main]`, and `workflow_call`
- Create `php-tests` job:
  - `runs-on: ubuntu-latest`
  - PostgreSQL 18 service container (`postgres:18-bookworm`) with health check, `POSTGRES_DB=vunnix_test`, `POSTGRES_USER=postgres`, `POSTGRES_PASSWORD=postgres`
  - `shivammathur/setup-php@v2` with `php-version: '8.5'`, extensions: `pdo_pgsql, pgsql, zip, intl, pcntl, redis`
  - Composer dependency caching via `actions/cache@v4` on `vendor/` keyed to `composer.lock` hash
  - `composer install --no-interaction --prefer-dist`
  - Set env vars for test step: `DB_CONNECTION=pgsql`, `DB_HOST=localhost`, `DB_PORT=5432`, `DB_DATABASE=vunnix_test`, `DB_USERNAME=postgres`, `DB_PASSWORD=postgres`
  - Run: `php artisan test --parallel`
- Create `js-tests` job (parallel, no dependency on php-tests):
  - `runs-on: ubuntu-latest`
  - `actions/setup-node@v4` with `node-version: 24`, `cache: 'npm'`
  - `npm ci`
  - `npm test`
**Verification:** Workflow YAML passes `actionlint` or manual review; both jobs defined with correct service containers and env vars

#### T142: Gate executor build behind tests
**File(s):** `.github/workflows/build-executor.yml` (modify)
**Action:**
- Add a `tests` job that calls the reusable workflow: `uses: ./.github/workflows/tests.yml`
- Add `if: github.event_name != 'schedule'` to the `tests` job so weekly builds skip tests
- Add `needs: [tests]` to the existing `version-alignment` job (or to `build-executor` directly) so the build only runs after tests pass
- For `schedule` trigger, ensure the build still runs without the test gate by adjusting `needs` with an `if` condition or by making the test job skippable via `if: always()` on downstream jobs that check test results
**Verification:** Push-triggered builds require tests to pass; `schedule`-triggered builds skip tests and proceed directly to build

#### T143: Update decisions index
**File(s):** `docs/reference/spec/decisions-index.md`
**Action:** Append D172–D173 with source `ext-004`
**Verification:** Both decisions present with correct summaries matching this document

### Verification

- [ ] `tests.yml` triggers on PRs, push to main, and is callable via `workflow_call`
- [ ] PHP tests run against PostgreSQL 18 service container with `--parallel` flag
- [ ] JS tests run with Node 24 and `npm test`
- [ ] PHP and JS test jobs run in parallel (no dependency between them)
- [ ] `build-executor.yml` push/manual triggers require tests to pass before building
- [ ] `build-executor.yml` weekly schedule skips tests and builds directly
- [ ] `build-app.yml` is unchanged
- [ ] D172–D173 added to decisions index

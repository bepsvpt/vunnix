## Extension 002: Full Dependency Upgrade

**Status: ✅ Implemented** — `9f8179b`

### Trigger

Dependencies have drifted from latest — major versions behind in testing stack (Pest 3→4), frontend state/routing (Pinia 2→3, Vue Router 4→5), Docker images (PHP 8.4→8.5, PG 16→18, Redis 7→8, Node 22→24), and GitHub Actions (checkout v4→v6). Project is pre-deployment, making this the ideal time.

### Scope

What it does:
- Upgrades PHP minimum from `^8.2` to `^8.5`
- Upgrades Pest 3→4, PHPUnit 11→12 (testing stack)
- Upgrades Pinia 2→3 and Vue Router 4→5 (frontend state/routing)
- Upgrades all Docker base images to latest stable (FrankenPHP php8.5, PG 18, Redis 8, Node 24)
- Bumps `actions/checkout` v4→v6 in GitHub Actions
- Runs `composer update` and `npm update` to pull latest within semver ranges for all other packages

What it does NOT do:
- Upgrade Laravel to a new major version (already on latest 12.x)
- Upgrade `laravel/ai` beyond `^0.1` (SDK still pre-1.0)
- Change application code behavior or features
- Add new dependencies

### Architecture Fit

- **Components affected:** composer.json, package.json, docker/app/Dockerfile, executor/Dockerfile, docker-compose.yml, .github/workflows/build-executor.yml, PHP test suite, Vue stores
- **Extension points used:** None — this is a dependency-level change
- **New tables/endpoints/services:** None

### New Decisions

- **D165:** PHP minimum version `^8.5` — Local dev runs PHP 8.5.2, Docker image targets php8.5-bookworm. No reason to support older PHP versions pre-deployment.
- **D166:** PostgreSQL 18 for development and production — PG 18.2 (Feb 2026) with async I/O. No production data to migrate, fresh volume creation only.
- **D167:** Redis 8 for cache/session/queue — Tri-licensed (RSALv2/SSPLv1/AGPLv3), acceptable for self-hosted deployment. Core now includes previously-separate Stack modules.
- **D168:** Node 24 LTS for executor image — Active LTS (EOL Apr 2028), replaces Node 22 Maintenance LTS.
- **D169:** Pest 4 + PHPUnit 12 for test suite — Pest 4 requires PHP 8.3+, uses PHPUnit 12. Enables test sharding and browser testing features.

### Affected Existing Decisions

| Decision | Current State | Proposed Change | Rationale |
|---|---|---|---|
| D72 | PostgreSQL (not MySQL) | PostgreSQL 18 (not MySQL) | Major version bump, same DBMS choice |

### Component Design

#### Composer (Backend Dependencies)

**Current behavior:** PHP `^8.2`, Pest `^3.0`, PHPUnit `^11.5.50`
**Proposed behavior:** PHP `^8.5`, Pest `^4.0`, PHPUnit `^12.0`
**Interface changes:** PHPUnit 12 removes deprecated APIs from PHPUnit 11. Pest 4 changes snapshot name generation.
**Data model changes:** None

#### npm (Frontend Dependencies)

**Current behavior:** Pinia `^2.3.1`, Vue Router `^4.6.4`
**Proposed behavior:** Pinia `^3.0.0`, Vue Router `^5.0.0`
**Interface changes:** Pinia 3 drops `defineStore({ id })` form (not used in this project — all 4 stores use `defineStore('name', () => {})`). Vue Router 5 has no breaking API changes for standard v4 users.
**Data model changes:** None

#### App Dockerfile (docker/app/Dockerfile)

**Current behavior:** `dunglas/frankenphp:php8.4-bookworm`, `postgresql-client-16`
**Proposed behavior:** `dunglas/frankenphp:php8.5-bookworm`, `postgresql-client-18`
**Interface changes:** None — same Dockerfile structure, different base image and package version
**Data model changes:** None

#### Executor Dockerfile (executor/Dockerfile)

**Current behavior:** `node:22-bookworm-slim`, `VUNNIX_EXECUTOR_VERSION="2.0.7"`
**Proposed behavior:** `node:24-bookworm-slim`, `VUNNIX_EXECUTOR_VERSION="2.1.0"`
**Interface changes:** npm 11 (from Node 24) may have different lockfile format — executor doesn't use lockfiles (global installs only)
**Data model changes:** None

#### Docker Compose (docker-compose.yml)

**Current behavior:** `postgres:16-bookworm`, `redis:7-bookworm`
**Proposed behavior:** `postgres:18-bookworm`, `redis:8-bookworm`
**Interface changes:** PG 18 Docker uses PGDATA at `/var/lib/postgresql/18/docker` (vs `/var/lib/postgresql/data`). Since we use a named volume (`postgres-data`), Docker handles this automatically — but existing volumes must be destroyed and recreated.
**Data model changes:** None — dev database is ephemeral, recreated via migrations

#### GitHub Actions (.github/workflows/build-executor.yml)

**Current behavior:** `actions/checkout@v4`
**Proposed behavior:** `actions/checkout@v6`
**Interface changes:** v6 requires Actions Runner v2.329.0+ (GitHub-hosted runners already satisfy this)
**Data model changes:** None

### Dependencies

- **Requires:** Nothing — standalone upgrade
- **Unblocks:** Production deployment (T106–T114) on latest stable versions

### Risk Mitigation

| Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|
| PHPUnit 12 breaks tests | Test suite fails, must fix deprecated API usage | Medium | Run full suite after upgrade, fix breakages iteratively |
| PHP 8.5 deprecation warnings | Noisy output, potential strict-mode failures | Low | Run with `E_ALL` error reporting, fix deprecations |
| PG 18 volume incompatibility | Database won't start with old volume data | High (if old volume exists) | Destroy `postgres-data` volume before starting PG 18 |
| Redis 8 behavior change | Cache/queue operations fail | Low | Redis 8 is backward-compatible for basic operations (GET/SET/LPUSH/etc.) |
| Composer dependency conflicts | `composer update` fails due to transitive conflicts | Low | Resolve conflicts by adjusting constraints one at a time |
| npm peer dependency conflicts | `npm install` fails after version bumps | Low | Use `--legacy-peer-deps` if needed, or resolve conflicts |

### Rollback Plan

- **Git revert scope:** All changes in a single branch — revert the branch to restore previous `composer.json`, `package.json`, Dockerfiles, and lock files
- **Docker volumes:** If PG 18 volume is destroyed, no rollback needed (dev data is ephemeral, recreated via migrations)
- **Lock files:** `composer.lock` and `package-lock.json` in git — reverting the commit restores exact previous dependency tree
- **No data migration:** No production data exists, so no data recovery needed

### Migration Plan

**What breaks:**
- Pest 3 API → Pest 4 API (snapshot names, archived plugins)
- PHPUnit 11 deprecated methods → removed in PHPUnit 12
- PHP 8.2/8.3/8.4 features deprecated in 8.5
- PostgreSQL 16 data files incompatible with PG 18

**Versioning strategy:**
- API: No API changes — this is infrastructure only
- DB: Destroy and recreate dev volumes (no production data)
- External contracts: No contract changes

**Backward compatibility:** Not applicable — single upgrade commit, no transition period needed (pre-deployment project)

### Data Migration

**Schema changes:** None — application schema unchanged. Only the database engine version changes.

**Migration strategy:**
- [x] Destroy existing `postgres-data` Docker volume
- [x] Start PG 18 container (creates fresh data directory)
- [x] Run `php artisan migrate` to recreate schema
- [x] Run `php artisan db:seed` if needed

**Rollback procedure:**
- Revert Docker image to `postgres:16-bookworm` in `docker-compose.yml`
- Destroy PG 18 volume, start PG 16 container, re-run migrations

### Tasks

#### T123: Update composer.json version constraints
**File(s):** `composer.json`
**Action:**
- Change `"php": "^8.2"` to `"php": "^8.5"`
- Change `"pestphp/pest": "^3.0"` to `"pestphp/pest": "^4.0"`
- Change `"pestphp/pest-plugin-laravel": "^3.0"` to `"pestphp/pest-plugin-laravel": "^4.0"`
- Change `"phpunit/phpunit": "^11.5.50"` to `"phpunit/phpunit": "^12.0"`
**Verification:** `composer validate` passes

#### T124: Run composer update and resolve conflicts
**File(s):** `composer.json`, `composer.lock`
**Action:** Run `composer update` to regenerate lockfile with new constraints. Resolve any transitive dependency conflicts.
**Verification:** `composer update` completes without errors; `composer validate` passes

#### T125: Fix PHP test suite for PHPUnit 12 compatibility
**File(s):** `tests/**/*.php`
**Action:** Run `php artisan test --parallel` and fix any failures caused by PHPUnit 12 API changes (removed deprecated methods, changed assertions, snapshot name changes). Common PHPUnit 12 changes: `withConsecutive()` removed, `getMockBuilder()` changes, `assertFileNotExists` → `assertFileDoesNotExist`, etc.
**Verification:** `php artisan test --parallel` passes with zero failures

#### T126: Update package.json version constraints
**File(s):** `package.json`
**Action:**
- Change `"pinia": "^2.3.1"` to `"pinia": "^3.0.0"`
- Change `"vue-router": "^4.6.4"` to `"vue-router": "^5.0.0"`
**Verification:** `npm install` completes without errors

#### T127: Run npm update and verify frontend
**File(s):** `package.json`, `package-lock.json`
**Action:** Run `npm update` to pull latest within all semver ranges (Tailwind 4.1.x, Vite 7.3.x, Axios 1.13.x, etc.). Then run `npm run build` to verify production build succeeds.
**Verification:** `npm run build` produces output in `public/assets/` without errors

#### T128: Run Vue test suite and fix any failures
**File(s):** `resources/js/**/*.test.js`
**Action:** Run `npm test` and fix any failures caused by Pinia 3 or Vue Router 5 changes.
**Verification:** `npm test` passes with zero failures

#### T129: Update app Dockerfile for PHP 8.5 and PG 18
**File(s):** `docker/app/Dockerfile`
**Action:**
- Change `FROM dunglas/frankenphp:php8.4-bookworm` to `FROM dunglas/frankenphp:php8.5-bookworm`
- Change `postgresql-client-16` to `postgresql-client-18`
- Update PG apt repo if needed for PG 18 packages
**Verification:** `docker build -f docker/app/Dockerfile .` succeeds

#### T130: Update executor Dockerfile for Node 24
**File(s):** `executor/Dockerfile`
**Action:**
- Change `FROM node:22-bookworm-slim` to `FROM node:24-bookworm-slim`
- Bump `VUNNIX_EXECUTOR_VERSION` from `"2.0.7"` to `"2.1.0"`
**Verification:** `docker build -f executor/Dockerfile executor/` succeeds

#### T131: Update docker-compose.yml for PG 18 and Redis 8
**File(s):** `docker-compose.yml`
**Action:**
- Change `image: postgres:16-bookworm` to `image: postgres:18-bookworm`
- Change `image: redis:7-bookworm` to `image: redis:8-bookworm`
**Verification:** `docker compose config` validates without errors

#### T132: Recreate Docker volumes and verify services start
**File(s):** N/A (operational)
**Action:**
- `docker compose down -v` (destroys volumes)
- `docker compose up -d` (starts with new images)
- Wait for all healthchecks to pass
- Run `php artisan migrate` to recreate schema
**Verification:** `docker compose ps` shows all services healthy; `php artisan migrate` completes without errors

#### T133: Update GitHub Actions workflow
**File(s):** `.github/workflows/build-executor.yml`
**Action:** Change both `uses: actions/checkout@v4` to `uses: actions/checkout@v6`
**Verification:** Workflow YAML is valid (GitHub validates on push)

#### T134: Update decisions index
**File(s):** `docs/reference/spec/decisions-index.md`
**Action:** Append D165–D169 with source `ext-002`
**Verification:** All 5 new decisions present with correct summaries

### Verification

- [ ] `composer validate` passes
- [ ] `php artisan test --parallel` passes with zero failures
- [ ] `npm run build` succeeds
- [ ] `npm test` passes with zero failures
- [ ] Docker images build successfully (app + executor)
- [ ] Docker Compose services start and pass healthchecks
- [ ] Migrations run successfully on PG 18
- [ ] D165–D169 added to decisions index
- [ ] All existing tests still pass (no regressions)

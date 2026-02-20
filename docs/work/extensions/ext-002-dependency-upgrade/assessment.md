# Assessment: Full Dependency Upgrade

**Date:** 2026-02-16
**Requested by:** User
**Trigger:** Dependencies have drifted from latest — several major versions behind in testing stack (Pest 3→4), frontend state/routing (Pinia 2→3, Vue Router 4→5), Docker base images, and runtime versions.

## What

Upgrade all dependencies across the stack to their latest stable versions: Composer packages (PHP backend), npm packages (Vue frontend), Docker base images (FrankenPHP, PostgreSQL, Redis, Node), the executor image, and GitHub Actions workflow versions. The project is feature-complete (107/107 code tasks) but not yet deployed to production, making this an ideal time to upgrade before the first deployment.

## Classification

**Tier:** 3
**Rationale:** Touches all components (backend, frontend, Docker infrastructure, CI). Multiple major version bumps with breaking changes. Pest 3→4 raises the PHP minimum to 8.3 and moves to PHPUnit 12, affecting 900+ tests. Pinia and Vue Router are major bumps. Docker images span 4 services. GitHub Actions need a major version bump. The upgrade scope exceeds 10 files across 3+ components.

**Modifiers:**
- [x] `breaking` — Pest 4 requires PHP 8.3+, PHPUnit 12; Pinia 3 drops deprecated APIs; PostgreSQL major version change
- [ ] `multi-repo` — Single repo
- [ ] `spike-required` — Breaking changes are well-documented; no feasibility uncertainty
- [ ] `deprecation` — No features removed
- [x] `migration` — PostgreSQL 16→18 requires dump/restore (dev DB only — no production data yet)

## Impact Analysis

### Components Affected

| Component | Impact | Files (est.) |
|---|---|---|
| **composer.json** | Bump PHP minimum, Pest, PHPUnit version constraints | 1 |
| **package.json** | Bump Pinia, Vue Router | 1 |
| **docker/app/Dockerfile** | FrankenPHP php8.4→php8.5, postgresql-client-16→18 | 1 |
| **executor/Dockerfile** | Node 22→24, bump executor version | 1 |
| **docker-compose.yml** | PostgreSQL 16→18, Redis 7→8 | 1 |
| **.github/workflows/build-executor.yml** | Bump `actions/checkout` v4→v6 | 1 |
| **PHP test suite (900+)** | PHPUnit 12 compatibility — API changes, deprecation removals | 5–20 (if any break) |
| **Vue stores (Pinia)** | Verify no deprecated `defineStore({ id })` usage | 5–10 stores |
| **Vue router config** | Vue Router 5 compatibility check | 1–3 |

### Current vs Latest Versions

#### Composer (Direct Dependencies)

| Package | Current (installed) | Latest | Constraint Change Needed? |
|---|---|---|---|
| `php` (minimum) | `^8.2` | 8.5.2 (local) | **Yes → `^8.5`** (targeting PHP 8.5 in Docker + dev) |
| `laravel/framework` | v12.51.0 | v12.51.0 | No — `^12.0` already covers latest |
| `laravel/ai` | v0.1.5 | v0.1.5 | No — `^0.1` already covers latest |
| `laravel/octane` | v2.13.5 | v2.13.5 | No — `^2.0` already covers latest |
| `laravel/reverb` | v1.7.1 | v1.7.1 | No — `^1.0` already covers latest |
| `laravel/socialite` | ^5.0 | Latest 5.x | No — semver covered |
| `pestphp/pest` | v3.8.5 | **v4.3.2** | **Yes → `^4.0`** |
| `pestphp/pest-plugin-laravel` | v3.2.0 | **v4.0.0** | **Yes → `^4.0`** |
| `phpunit/phpunit` | v11.5.50 | v13.0.2 | **Yes → `^12.0`** (Pest 4 uses PHPUnit 12) |
| `mockery/mockery` | ^1.6 | 1.6.x | No |
| `nunomaduro/collision` | ^8.6 | 8.x | No |

**Key:** Laravel, Octane, Reverb, Socialite, AI SDK are all already at latest within their semver range. `composer update` will pull latest patches. The only _constraint_ changes needed are for PHP minimum, Pest, and PHPUnit.

#### npm (Direct Dependencies)

| Package | Current | Latest | Breaking? |
|---|---|---|---|
| `pinia` | ^2.3.1 | **v3.0.4** | Minor — dropped Vue 2 support, removed `defineStore({ id })` form |
| `vue-router` | ^4.6.4 | **v5.0.2** | Minimal — merged `unplugin-vue-router` into core, no API changes for standard v4 users |
| `vue` | ^3.5.28 | 3.5.x | No — semver covered |
| `@tailwindcss/vite` | ^4.0.0 | v4.1.18 | No — semver covered |
| `tailwindcss` | ^4.0.0 | v4.1.18 | No — semver covered |
| `vite` | ^7.0.7 | v7.3.1 | No — semver covered |
| `vitest` | ^4.0.18 | 4.x | No — semver covered |
| `axios` | ^1.11.0 | v1.13.5 | No — semver covered |
| `@shikijs/markdown-it` | ^3.22.0 | 3.x | No — semver covered |
| `shiki` | ^3.22.0 | 3.x | No — semver covered |
| `jsdom` | ^28.0.0 | v28.1.0 | No — semver covered |
| `concurrently` | ^9.0.1 | v9.2.1 | No — semver covered |
| `laravel-vite-plugin` | ^2.0.0 | v2.1.0 | No — semver covered |
| `laravel-echo` | ^2.3.0 | 2.x | No — semver covered |
| `pusher-js` | ^8.4.0 | 8.x | No — semver covered |
| `markdown-it` | ^14.1.1 | 14.x | No — semver covered |
| `@vitejs/plugin-vue` | ^6.0.4 | 6.x | No — semver covered |
| `@vue/test-utils` | ^2.4.6 | 2.x | No — semver covered |

**Key:** Only Pinia and Vue Router need constraint bumps. Everything else is already covered by existing `^` ranges — `npm update` will pull latest.

#### Docker Images

| Image | Current | Latest Stable | Breaking? |
|---|---|---|---|
| `dunglas/frankenphp` | `php8.4-bookworm` | **`php8.5-bookworm`** | PHP 8.5 adds pipe operator, `clone with`, minor deprecations |
| `postgres` | `16-bookworm` | **`18-bookworm`** (18.2) | Major version — requires dump/restore; PGDATA path changed to `/var/lib/postgresql/18/docker` |
| `redis` | `7-bookworm` | **`8-bookworm`** (8.0.5) | Redis Stack modules merged into core; license → tri-license (RSALv2/SSPLv1/AGPLv3) |
| `node` | `22-bookworm-slim` | **`24-bookworm-slim`** (24.13.0 LTS) | Node 24 Active LTS; OpenSSL 3.5, npm 11, Undici v7 |
| `composer` | `latest` / `2` | 2.9.5 | No breaking changes |

**Note on PostgreSQL 18:** Released Feb 12, 2026 (18.2 is latest point release). Introduces asynchronous I/O (AIO). Docker images use a version-specific PGDATA path (`/var/lib/postgresql/18/docker` instead of `/var/lib/postgresql/data`). Since there is no production data yet, this path change has zero migration risk — just destroy and recreate the volume.

#### GitHub Actions

| Action | Current | Latest | Change Needed? |
|---|---|---|---|
| `actions/checkout` | `@v4` | **`@v6`** | **Yes — 2 major versions behind** |
| `docker/login-action` | `@v3` | `@v3` (v3.7.0) | No — already on latest major |
| `docker/setup-buildx-action` | `@v3` | `@v3` (v3.12.0) | No — already on latest major |
| `docker/build-push-action` | `@v6` | `@v6` | No — already on latest major |

### Relevant Decisions

| Decision | Summary | Relationship |
|---|---|---|
| D72 | PostgreSQL (not MySQL) | Constrains — PG major upgrade requires dump/restore |
| D69 | FrankenPHP serves both app and static files | Constrains — base image tag must be updated |
| D91 | Claude Opus 4.6 for everything | Unaffected |
| D134 | Separate Redis queues | Unaffected — Redis 8 is backward-compatible for basic operations |

### Dependencies

- **Requires first:** Nothing — this is a standalone upgrade
- **Unblocks:** Production deployment (T106–T114) benefits from running on latest stable versions

## Risk Factors

- **Pest 4 / PHPUnit 12 with 900+ tests:** The biggest risk. PHPUnit 12 removes several deprecated APIs from PHPUnit 11. Any tests using removed APIs will fail. Must run full test suite after upgrade and fix breakage.
- **Pinia v3 store compatibility:** Need to audit all Pinia stores for deprecated `defineStore({ id: 'name' })` pattern. The project likely uses the modern `defineStore('name', ...)` form but must verify.
- **PHP 8.5 deprecations:** PHP 8.5 deprecates some functions. Need to check for deprecation warnings in the codebase.
- **PostgreSQL 18 client tools:** The `postgresql-client-16` package in the app Dockerfile must be updated to `postgresql-client-18` to match the server version (for `pg_dump` compatibility in backup jobs — T105). The PG apt repo line may also need updating if PG 18 packages are in a different suite.
- **Redis 8 licensing:** The tri-license model (RSALv2/SSPLv1/AGPLv3) must be acceptable for the project's deployment model. For self-hosted use, AGPLv3 is fine.
- **Lock file churn:** Both `composer.lock` and `package-lock.json` will have large diffs (transitive dependency updates). This is expected and unavoidable.

## Recommendation

**Proceed to planning-extensions.** This is a Tier 3 change touching all layers. The upgrade should be sequenced carefully:

1. Backend first (Composer — Pest 4 is the highest risk, run full test suite)
2. Frontend second (npm — Pinia 3 + Vue Router 5, run Vitest suite)
3. Docker images (rebuild and verify containers start correctly)
4. CI last (GitHub Actions version bumps)

Each layer can be verified independently before moving to the next. The project's pre-deployment status makes this the ideal time to upgrade — no production rollback concerns.

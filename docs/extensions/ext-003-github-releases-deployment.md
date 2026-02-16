## Extension 003: GitHub Releases Deployment

### Trigger

Migration from GitLab to GitHub hosting. Need a production distribution story so self-hosted users can deploy Vunnix on any VPS/EC2 without cloning the source repo — just `docker compose up -d`.

### Scope

What it does:
- Adds multi-stage frontend asset build to the app Dockerfile (Node.js build stage → compiled assets only in final image)
- Creates a GitHub Actions workflow that builds and pushes the app Docker image to GHCR on each GitHub Release
- Creates a self-contained production `docker-compose.yml` for end users (uses `image:` from GHCR, not `build:`)
- Creates a deployment guide documenting first-time setup and upgrades
- Updates `.env.production.example` with version pinning

What it does NOT do:
- Create a README.md (separate task)
- Automate database migrations on deploy (documented as manual post-deploy step)
- Unify app and executor image versioning (they release independently)
- Add TLS/certificate automation (user's responsibility via reverse proxy)

### Architecture Fit

- **Components affected:** `docker/app/Dockerfile`, `.github/workflows/`, `docker-compose.yml` (new production variant), `.env.production.example`, `docs/`
- **Extension points used:** GitHub Actions + GHCR infrastructure established by ext-001 (D162, D163)
- **New tables/endpoints/services:** None

### New Decisions

- **D170:** App image in public GHCR at `ghcr.io/bepsvpt/vunnix/app` — same registry and auth model as executor (D163). Public repo = public images, no credentials needed to pull.
- **D171:** Multi-stage Docker build for frontend assets — Node 24 build stage runs `npm ci` + `npm run build`, only compiled `public/build/` directory copied into final FrankenPHP image. Keeps final image lean (no Node.js, npm, or node_modules).

### Affected Existing Decisions

| Decision | Current State | Proposed Change | Rationale |
|---|---|---|---|
| D69 | FrankenPHP serves app + static files | Unchanged — production compose exposes same ports | Confirmed, no conflict |
| D163 | Executor image in public GHCR | Extended pattern to app image (D170) | Same registry, same approach |

### Dependencies

- **Requires:** Nothing — ext-002 (dependency upgrade) already completed
- **Unblocks:** Self-hosted deployment without source code; version-pinned rollbacks; future README can link to deployment guide

### Tasks

#### T135: Add multi-stage frontend build to app Dockerfile
**File(s):** `docker/app/Dockerfile`
**Action:**
- Add a `frontend` build stage using `node:24-bookworm-slim` that copies `package.json`, `package-lock.json`, `vite.config.js`, `resources/`, and runs `npm ci && npm run build`
- In the main FrankenPHP stage, add `COPY --from=frontend /app/public/build public/build` after the application code is copied
- Position the COPY after `COPY . .` so it overwrites any stale `public/build/` from the source context
**Verification:** `docker build -f docker/app/Dockerfile .` succeeds; the built image contains `public/build/manifest.json`

#### T136: Create GitHub Actions release workflow
**File(s):** `.github/workflows/build-app.yml`
**Action:**
- Trigger on `release: types: [published]` and `workflow_dispatch`
- Extract version from the git tag (e.g., `v1.0.0` → `1.0.0`)
- Log in to GHCR, set up Buildx
- Build `docker/app/Dockerfile` and push to `ghcr.io/bepsvpt/vunnix/app` with tags: `<sha>`, `<version>`, `latest`
- Add OCI labels (same pattern as `build-executor.yml`)
- Attach `docker-compose.production.yml` and `.env.production.example` as release assets using `gh release upload`
**Verification:** Workflow YAML is valid; manual `workflow_dispatch` trigger succeeds (once pushed)

#### T137: Create production docker-compose.yml for end users
**File(s):** `docker-compose.production.yml` (new, project root)
**Action:**
- Merge base `docker-compose.yml` and `docker-compose.prod.yml` into a single self-contained file
- Replace all `build:` blocks with `image: ghcr.io/bepsvpt/vunnix/app:${VUNNIX_VERSION:-latest}`
- Remove source code volume mounts (`- .:/app` and `- vendor-data:/app/vendor`) — code is baked into the image
- Keep infrastructure images as-is (`postgres:18-bookworm`, `redis:8-bookworm`)
- Keep all healthchecks, networks, depends_on, env_file references
- Include production resource limits and log rotation inline (from `docker-compose.prod.yml`)
- Use PG 18 volume mount at `/var/lib/postgresql` (D166 learning)
- Add header comment explaining usage: download file, create `.env`, run `docker compose -f docker-compose.production.yml up -d`
**Verification:** `docker compose -f docker-compose.production.yml config` validates without errors

#### T138: Update .env.production.example
**File(s):** `.env.production.example`
**Action:**
- Add `VUNNIX_VERSION=latest` at the top (after APP_NAME) with comment explaining version pinning (e.g., `1.0.0` or `latest`)
- Remove `VITE_*` variables — frontend is pre-built in the Docker image, Vite env vars are baked at build time
**Verification:** All variables referenced in `docker-compose.production.yml` are documented in `.env.production.example`

#### T139: Create deployment guide
**File(s):** `docs/deployment.md`
**Action:**
Create a self-contained deployment guide covering:
- Prerequisites (Docker Engine, Docker Compose v2, VPS/EC2 with 2+ CPU / 4+ GB RAM)
- Quick start: download `docker-compose.production.yml` + `.env.production.example` from GitHub Release, configure `.env`, run `docker compose up -d`
- First-time setup: `docker compose exec app php artisan migrate`, `docker compose exec app php artisan db:seed`
- Generate app key: `docker compose exec app php artisan key:generate`
- Configuration reference: GitLab OAuth, bot PAT, Anthropic API key, Reverb, mail
- Upgrading: change `VUNNIX_VERSION` in `.env`, run `docker compose pull && docker compose up -d`, run migrations
- Backup and restore (reference existing pg_dump scheduler)
- Troubleshooting: healthcheck failures, log access (`docker compose logs -f app`)
**Verification:** Guide covers all `.env.production.example` variables; all commands are copy-pasteable

#### T140: Update decisions index
**File(s):** `docs/spec/decisions-index.md`
**Action:** Append D170–D171 with source `ext-003`
**Verification:** Both decisions present with correct summaries

### Verification

- [ ] `docker build -f docker/app/Dockerfile .` succeeds and final image contains `public/build/manifest.json`
- [ ] `.github/workflows/build-app.yml` is valid YAML with correct triggers and GHCR push
- [ ] `docker compose -f docker-compose.production.yml config` validates without errors
- [ ] `.env.production.example` includes `VUNNIX_VERSION` and excludes `VITE_*` variables
- [ ] `docs/deployment.md` covers quick start, first-time setup, upgrades, and troubleshooting
- [ ] D170–D171 added to decisions index

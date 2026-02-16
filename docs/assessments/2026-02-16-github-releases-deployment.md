# Assessment: GitHub Releases Deployment

**Date:** 2026-02-16
**Requested by:** Kevin
**Trigger:** Migration from GitLab to GitHub hosting — need a production distribution story for self-hosted users.

## What

Create a GitHub Releases-based deployment workflow so end users can deploy Vunnix on any VPS/EC2 by downloading a `docker-compose.yml` and `.env.example`, then running `docker compose up -d`. Pre-built Docker images are pushed to GHCR on each release; users pull images automatically via Compose — no source code or build tools required on the target server.

## Classification

**Tier:** 2 (Feature-scoped)
**Rationale:** New distribution capability within existing GitHub Actions + GHCR infrastructure. Touches 5-7 files (3 new, 2-3 modified). Extends patterns already proven by the executor image workflow (D163). No architectural changes — adds a new workflow and production-ready compose file.

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
| GitHub Actions | New workflow to build + push app image to GHCR on release | 1 new |
| Docker (app Dockerfile) | Add Node.js, `npm ci` + `npm run build` for frontend assets | 1 modified |
| Docker Compose | New production compose file using `image:` instead of `build:` | 1 new |
| Documentation | Deployment guide for end users | 1 new |
| `.env.production.example` | Minor update (add `VUNNIX_VERSION` or similar) | 1 modified |

### Current Stack Versions (post ext-002)

| Component | Version | Decision |
|---|---|---|
| PHP / FrankenPHP | 8.5 (`dunglas/frankenphp:php8.5-bookworm`) | D165 |
| PostgreSQL | 18 (`postgres:18-bookworm`) | D166 |
| Redis | 8 (`redis:8-bookworm`) | D167 |
| Node (executor) | 24 (`node:24-bookworm-slim`) | D168 |
| PG volume mount | `/var/lib/postgresql` (not `/data`) | D166 / CLAUDE.md learning |

### Relevant Decisions

| Decision | Summary | Relationship |
|---|---|---|
| D22 | Executor as Docker image | Enables — same image distribution pattern for app |
| D69 | FrankenPHP serves app + static files | Constrains — production compose must expose ports 80/443, no separate web server needed |
| D162 | Vunnix development on GitHub (public) | Foundation — GitHub Actions + GHCR available |
| D163 | Executor image in public GHCR | Enables — same registry, same auth model (public, no credentials needed) |
| D164 | CI template via remote GitHub raw URL | Independent — CI template distribution unaffected |
| D94 | Rollback via per-project disable + post-mortem | Enabled by — version-tagged releases enable precise rollbacks |
| D165 | PHP minimum ^8.5 | Constrains — app image built on php8.5-bookworm |
| D166 | PostgreSQL 18 | Constrains — production compose uses postgres:18-bookworm, volume at `/var/lib/postgresql` |
| D167 | Redis 8 | Constrains — production compose uses redis:8-bookworm |

### Dependencies

- **Requires first:** Dockerfile must be updated with frontend asset build (`npm ci` + `npm run build`) — currently missing, production images would serve broken SPA
- **Unblocks:** Self-hosted deployment without source code; version-pinned rollbacks; automated release process

## Risk Factors

- **Frontend build in Docker:** Adding Node.js to the PHP image increases image size (~200-400MB). Could use multi-stage build to keep final image lean.
- **First-deploy manual steps:** Database migration (`php artisan migrate`) and seeding (`php artisan db:seed`) are not automated — must be clearly documented in deployment guide.
- **Version coordination:** App image version and executor image version are independent. Must document compatibility matrix or unify versioning.
- **opcache is statically compiled in php8.5** — do not attempt `docker-php-ext-install opcache` (CLAUDE.md learning).

## Out of Scope

- **README.md:** Will be handled as a separate task. The deployment docs created here should be self-contained so the README can link to them later.

## Recommendation

Proceed to **planning-extensions**. Well-scoped Tier 2 extension with no blocking constraints. Existing executor workflow (`.github/workflows/build-executor.yml`) provides a proven template. Critical prerequisite (Dockerfile frontend build) is straightforward to address. The ext-002 dependency upgrade does not break or change the plan — it only updates version numbers that the production compose file must reference.

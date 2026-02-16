# Assessment: Migrate Repo Hosting from GitLab to GitHub

**Date:** 2026-02-16
**Requested by:** Kevin
**Trigger:** Public GitHub repo provides virtually unlimited free CI credits via GitHub Actions

## What

Migrate Vunnix's own source code hosting from GitLab to GitHub at https://github.com/bepsvpt/vunnix (public). The product's purpose is unchanged — it still serves self-hosted GitLab Free users. Only the *development infrastructure* moves: CI/CD pipelines, container registry, and code hosting. The application code (PHP, Vue), database schema, and all customer-facing GitLab integration remain identical.

Public repo status means: unlimited GitHub Actions minutes, generous GHCR limits, and no auth complexity for the executor image.

## Classification

**Tier:** 2 (Feature-scoped)
**Rationale:** Changes 5–8 files across CI config, documentation, and one service file. No database schema changes. No application logic changes. Two existing decisions affected. Existing architecture fully preserved — the executor, task dispatcher, webhook controller, and all AI features are untouched.

**Modifiers:**
- [ ] `breaking` — No public API, DB schema, or external contract changes
- [ ] `multi-repo` — Only this repository changes
- [ ] `spike-required` — GitHub Actions and GHCR are well-understood
- [ ] `deprecation` — No product capability removed
- [x] `migration` — Target projects must update CI template include path and executor image URL

## Impact Analysis

### Components Affected

| Component | Impact | Files (est.) |
|---|---|---|
| `.gitlab-ci.yml` | Replaced entirely by `.github/workflows/` | 1 deleted, 1–2 created |
| `ci-template/vunnix.gitlab-ci.yml` | Image path changes from GitLab CR to GHCR | 1 |
| `docs/project-setup.md` | Setup instructions rewritten for GHCR + remote include | 1 |
| `app/Services/ProjectEnablementService.php` | Visibility warning (line 74) no longer applies | 1 |
| `docs/spec/decisions-index.md` | D65 superseded, D150 modified, new decisions added | 1 |
| `executor/Dockerfile` | No change (image is platform-agnostic) | 0 |
| `app/Services/TaskDispatcher.php` | No change (still triggers customer GitLab pipelines) | 0 |
| `app/Services/GitLabClient.php` | No change (still talks to customer GitLab API) | 0 |
| Database schema | No change | 0 |
| Frontend (Vue SPA) | No change | 0 |

### What Does NOT Change

These components serve **customer GitLab instances** and are unaffected by where Vunnix's source code is hosted:

- **TaskDispatcher** — still triggers customer GitLab CI via Pipeline Triggers API
- **GitLabClient** — still authenticates with customer GitLab bot PAT
- **WebhookController** — still receives customer GitLab webhooks
- **ProjectEnablementService** — still creates trigger tokens on customer GitLab (D156)
- **EventDeduplicator** — still cancels customer pipelines on supersede
- **Executor entrypoint** — still runs inside customer GitLab Runner jobs
- **All AI/conversation/review features** — no dependency on hosting platform
- **Database** — `pipeline_id`, `ci_trigger_token` are about customer pipelines

### Relevant Decisions

| Decision | Summary | Relationship |
|---|---|---|
| D21 | Execution on GitLab Runner — CI pipelines execute claude CLI | **Unchanged** — describes customer GitLab, not Vunnix hosting |
| D22 | Executor as Docker image — Skills/MCP/scripts packaged in image | **Unchanged** — Docker image concept is platform-agnostic |
| D27 | CI pipeline location — Runs in project's own CI/CD | **Unchanged** — describes customer projects |
| D65 | Executor image registry — GitLab Container Registry | **Superseded** — moves to `ghcr.io/bepsvpt/vunnix/executor` |
| D150 | Executor image registry access — Vunnix project internal/public | **Superseded** — public GHCR from public repo, no auth needed |
| D156 | Project enablement auto-creates CI trigger token | **Unchanged** — about customer GitLab |

### Dependencies

- **Requires first:** GitHub repository exists (confirmed: https://github.com/bepsvpt/vunnix)
- **Unblocks:** Virtually unlimited free CI for Vunnix development

## Key Design Decisions

### 1. Executor image — public GHCR, zero auth

Image at `ghcr.io/bepsvpt/vunnix/executor`. Public repo = public GHCR. Target GitLab projects pull without authentication — no `CI_JOB_TOKEN`, no deploy tokens, no visibility constraints. Simpler than the current model.

### 2. CI template — remote include from GitHub

Target projects switch from `include: project:` to `include: remote:`:
```yaml
include:
  - remote: 'https://raw.githubusercontent.com/bepsvpt/vunnix/main/ci-template/vunnix.gitlab-ci.yml'
```

Net improvement — removes same-instance coupling.

### 3. CI template image path simplification

**Current (requires per-project `VUNNIX_PROJECT_PATH` variable):**
```yaml
VUNNIX_REGISTRY: "${CI_SERVER_HOST}:${CI_SERVER_PORT:-443}/${VUNNIX_PROJECT_PATH}"
image: "${VUNNIX_REGISTRY}/vunnix/executor:${VUNNIX_EXECUTOR_VERSION}"
```

**After (zero per-project config):**
```yaml
image: "ghcr.io/bepsvpt/vunnix/executor:${VUNNIX_EXECUTOR_VERSION}"
```

Eliminates `VUNNIX_PROJECT_PATH` variable entirely.

### 4. Self-dogfooding

Vunnix can't review its own GitHub PRs (product serves GitLab, not GitHub). Dogfooding continues via pilot projects on the target GitLab instance.

## Risk Factors

- **Target project migration:** Existing target projects update CI includes — one-time config change per project
- **Self-dogfooding gap:** Vunnix can't review its own code. Mitigated by pilot project testing.

## Recommendation

**Proceed to planning-extensions.** Clean Tier 2 change with `migration` modifier. Application code untouched — purely CI/CD infrastructure and documentation.

## Extension 001: Migrate Repo Hosting to GitHub

**Status: ✅ Implemented** — `c4e6a3b`

### Trigger
CI ecosystem preference and virtually unlimited free CI credits for public repos on GitHub Actions. Repository: https://github.com/bepsvpt/vunnix

### Scope
What it does:
- Replaces `.gitlab-ci.yml` with GitHub Actions workflows for building the executor image and running version alignment checks
- Moves executor image from GitLab Container Registry to public GHCR (`ghcr.io/bepsvpt/vunnix/executor`)
- Simplifies CI template: static GHCR image path replaces dynamic `CI_SERVER_HOST`/`VUNNIX_PROJECT_PATH` construction
- Updates project setup documentation for the new distribution model

What it does NOT do:
- Change any application code (PHP services, Vue frontend, database schema)
- Affect how the executor runs on customer GitLab Runners (still GitLab CI)
- Change the TaskDispatcher, GitLabClient, or WebhookController (they serve customer GitLab)
- Alter the executor Docker image contents or entrypoint
- Add GitHub-based code review (product still serves GitLab only)

### Architecture Fit
- **Components affected:** CI config, CI template, project setup docs, 1 service warning message, decisions index
- **Extension points used:** None — this is infrastructure, not product logic
- **New tables/endpoints/services:** None

### New Decisions

- **D162:** Vunnix development hosted on GitHub (public) — unlimited free CI via GitHub Actions, code at `github.com/bepsvpt/vunnix`
- **D163:** Executor image registry — public GHCR at `ghcr.io/bepsvpt/vunnix/executor`, no authentication needed for target projects (supersedes D65, D150)
- **D164:** CI template distributed via `include: remote:` URL from GitHub raw content (replaces same-instance project include)

### Dependencies
- **Requires:** GitHub repository created (confirmed: https://github.com/bepsvpt/vunnix)
- **Unblocks:** Free CI for Vunnix development, simplified target project setup (no `VUNNIX_PROJECT_PATH` variable)

### Target Project Migration

**No database or schema changes.** The migration affects target project CI configuration only.

**What target projects must update:**

| Change | Before | After |
|---|---|---|
| CI template include | `project: 'group/vunnix'` | `remote: 'https://raw.githubusercontent.com/bepsvpt/vunnix/main/ci-template/vunnix.gitlab-ci.yml'` |
| `VUNNIX_PROJECT_PATH` variable | Required | Remove (no longer used) |
| Deploy tokens (private fallback) | Sometimes needed | Remove (public GHCR) |

**Rollout:** Update target projects one at a time. Each update is a single-line change in `.gitlab-ci.yml` plus removing one CI variable. Can be done incrementally — old and new image paths can coexist during transition.

### Tasks

#### T117: Create GitHub Actions workflow for executor image build
**File(s):** `.github/workflows/build-executor.yml`
**Action:** Create workflow that:
- Triggers on push to `main` when `executor/**` changes, and on workflow_dispatch
- Runs version alignment check (`scripts/check-version-alignment.sh`)
- Builds executor Docker image from `executor/Dockerfile`
- Logs in to GHCR via `GITHUB_TOKEN`
- Tags: `${{ github.sha }}` (always), version from Dockerfile ENV + `latest` (main only)
- Pushes to `ghcr.io/bepsvpt/vunnix/executor`
- Uses OCI labels matching current build (created, revision, version, source, title)
**Verification:** Push a change to `executor/` on main → workflow runs → image appears at `ghcr.io/bepsvpt/vunnix/executor:latest`

#### T118: Update CI template for GHCR image path
**File(s):** `ci-template/vunnix.gitlab-ci.yml`
**Action:**
- Replace `image: "${VUNNIX_REGISTRY}/vunnix/executor:${VUNNIX_EXECUTOR_VERSION}"` with `image: "ghcr.io/bepsvpt/vunnix/executor:${VUNNIX_EXECUTOR_VERSION}"`
- Remove `VUNNIX_REGISTRY` variable (line 32)
- Remove reference to `VUNNIX_PROJECT_PATH` from comments
- Update header comments: remote URL is now the primary include method, project reference removed
- Remove D150 visibility prerequisite from comments (line 16)
- Update `VUNNIX_EXECUTOR_VERSION` default to match current version from `executor/Dockerfile`
**Verification:** `grep -c 'ghcr.io/bepsvpt/vunnix' ci-template/vunnix.gitlab-ci.yml` returns 1; no references to `CI_SERVER_HOST`, `VUNNIX_PROJECT_PATH`, or `VUNNIX_REGISTRY` remain

#### T119: Update project setup documentation
**File(s):** `docs/guides/project-setup.md`
**Action:**
- Remove Step 1 (Verify Registry Access) — public GHCR needs no auth
- Remove Step 5 (Configure the Registry Path) — `VUNNIX_PROJECT_PATH` eliminated
- Update Step 4: remote URL include is now the primary method, using `https://raw.githubusercontent.com/bepsvpt/vunnix/main/ci-template/vunnix.gitlab-ci.yml`
- Remove "Private Registry Fallback" section entirely
- Update Variable Reference: remove `VUNNIX_PROJECT_PATH`, `VUNNIX_DEPLOY_USER`, `VUNNIX_DEPLOY_TOKEN`
- Update Prerequisites table: remove Vunnix project visibility requirement
- Update Troubleshooting: simplify Image Pull Failure (no visibility/path issues)
- Renumber steps after removals
**Verification:** No references to `VUNNIX_PROJECT_PATH`, `CI_JOB_TOKEN`, `internal or public`, or deploy tokens in the file

#### T120: Remove ProjectEnablementService visibility warning
**File(s):** `app/Services/ProjectEnablementService.php`
**Action:** Remove the Vunnix project visibility check block (lines 70–78) that warns about private visibility and D150. The executor image is now on public GHCR — GitLab project visibility is irrelevant to image access.
**Verification:** `php artisan test --filter=ProjectEnablementService` passes; no reference to D150 or "executor image" visibility in the service

#### T121: Delete `.gitlab-ci.yml`
**File(s):** `.gitlab-ci.yml`
**Action:** Delete the file. Its two purposes are handled differently now:
- BUILD stage → replaced by T117 (GitHub Actions)
- EXECUTE stage (self-dogfooding) → removed; dogfooding via pilot projects on target GitLab
**Verification:** File does not exist; `git status` shows it as deleted

#### T122: Update decisions index
**File(s):** `docs/reference/spec/decisions-index.md`
**Action:**
- Change D65 status from `Active` to `Superseded` and update summary: `~~Executor image registry — GitLab Container Registry~~ — Superseded by D163`
- Change D150 status from `Active` to `Superseded` and update summary: `~~Executor image registry access — Vunnix project internal/public~~ — Superseded by D163`
- Append D162, D163, D164 with source `ext-001`
**Verification:** D65 and D150 show `Superseded`; D162–D164 present with correct summaries

### Verification
- [ ] GitHub Actions workflow builds and pushes executor image to `ghcr.io/bepsvpt/vunnix/executor`
- [ ] CI template references `ghcr.io/bepsvpt/vunnix/executor` with no GitLab registry references
- [ ] `docs/guides/project-setup.md` uses remote URL include and has no `VUNNIX_PROJECT_PATH` references
- [ ] ProjectEnablementService has no D150 visibility check
- [ ] `.gitlab-ci.yml` deleted
- [ ] Decisions index updated: D65/D150 superseded, D162–D164 added
- [ ] `php artisan test --parallel` passes (no regressions)
- [ ] Target project can pull executor image from GHCR without authentication

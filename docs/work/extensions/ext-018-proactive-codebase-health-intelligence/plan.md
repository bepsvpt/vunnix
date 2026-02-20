## Extension 018: Proactive Codebase Health Intelligence

### Trigger

After ext-012 (Project Memory) closed the feedback loop for reviews, the next mode shift is making Vunnix *proactive*. Currently every capability is reactive — code review waits for MR open, chat waits for user input, feature dev waits for label assignment. Health Intelligence makes Vunnix continuously monitor enabled projects and surface problems before they reach code review.

### Scope

What it does:
- Runs scheduled health analysis on the default branch of enabled projects, checking three dimensions: test coverage trends (from GitLab pipeline API), dependency vulnerabilities (from lock file parsing), and file complexity heuristics (from source file analysis via GitLab API)
- Stores time-series health snapshots in PostgreSQL for trend analysis and dashboard visualization
- Evaluates configurable thresholds and creates GitLab issues automatically when thresholds are crossed, following the existing AlertEvent pattern
- Bridges health signals into Project Memory (new `health_signal` type) so code reviews receive health context via the existing MemoryInjectionService pipeline
- Adds a Health tab to the Vue dashboard with trend charts and active alert summaries
- Feature-flagged per health dimension for gradual rollout

What it does NOT do:
- No AI token consumption — all analysis is local computation (API calls + data crunching), not Claude invocations (D87 cost constraint respected)
- No external service requirements — works with GitLab's built-in pipeline coverage API and file reads, not SonarQube/Codecov
- No architecture drift detection or code duplication analysis (future extension if v1 proves value)
- No cross-project benchmarking (future extension)
- No vector embeddings or semantic search (D136/D145 remain deferred)

### Architecture Fit

- **Components affected:** Models (2 new), Migrations (1 new), Services (5 new + 1 modified), Jobs (1 new), Commands (1 new), Enums (1 new), Events (1 new), Controllers (1 new), Resources (1 new), Frontend (4 new components + 1 modified page + 1 new store), Config, Routes, Tests
- **Extension points used:** AlertEvent + AlertEventService alert pattern, MemoryInjectionService memory injection pipeline, MemoryEntry model (new type), ProjectConfig for per-project overrides, Reverb broadcasting, `vunnix-server` queue, existing dashboard tab system
- **New tables:** `health_snapshots`
- **New endpoints:** `GET /api/v1/projects/{project}/health/trends`, `GET /api/v1/projects/{project}/health/summary`, `GET /api/v1/projects/{project}/health/alerts`
- **New services:** HealthAnalysisService, CoverageAnalyzer, DependencyAnalyzer, ComplexityAnalyzer, HealthAlertService
- **New jobs:** AnalyzeProjectHealth
- **New commands:** `health:analyze`
- **New events:** HealthSnapshotRecorded

### New Decisions

- **D211:** Health data sourced from GitLab APIs, not external services — Coverage percentage read from GitLab pipeline API (`coverage` field, populated when `.gitlab-ci.yml` uses the `coverage` keyword). Dependency vulnerabilities detected by parsing `composer.lock`/`package-lock.json` via GitLab file read API and checking against known CVE databases (Packagist security advisories API, npm audit registry). Complexity measured via file-level heuristics (LOC, function/method count) from source files read via GitLab API. Zero external service dependencies preserves simplicity for self-hosted GitLab Free users.
- **D212:** Three health dimensions at launch: coverage, dependencies, complexity — Start concrete and small. Coverage tracking has the highest signal-to-effort ratio (GitLab provides it natively). Dependency scanning is high value for security posture. Complexity heuristics give actionable hotspot data. Architecture drift and duplication detection deferred until these three prove value.
- **D213:** Health analysis runs server-side on `vunnix-server` queue, not GitLab Runner — Unlike code review (which runs Claude on Runner), health analysis is deterministic computation: API calls + data parsing. No AI tokens consumed. No executor Docker image needed. Runs as a standard Laravel job on the server queue (D134 topology respected).
- **D214:** Health snapshots stored as time-series with 180-day retention — Each analysis run creates `HealthSnapshot` records (one per dimension per project). JSONB `details` column holds dimension-specific payload (coverage: per-file breakdown; dependencies: vulnerability list; complexity: top-N complex files). 180-day retention for trend analysis (configurable). Snapshots auto-cleaned by scheduled command.
- **D215:** Health alerts extend existing AlertEvent model with auto-created GitLab issues — No new alert model. Health threshold violations create AlertEvents with new `alert_type` values (`health_coverage_decline`, `health_vulnerability_found`, `health_complexity_spike`). When critical or warning severity, a GitLab issue is auto-created in the project using the existing `CreateGitLabIssue` infrastructure (T56). Consistent with existing infrastructure monitoring alerts (T104).
- **D216:** Health signals bridge to Project Memory as `health_signal` type — After each health analysis, significant findings (coverage drops, new vulnerabilities, growing complexity) are recorded as MemoryEntry records with `type: 'health_signal'`. These flow through the existing MemoryInjectionService into code review prompts (Layer 2.5, D197). Example injection: "Test coverage in app/Services/ dropped to 72% last week — prioritize test coverage in changed files."
- **D217:** Feature-flagged per health dimension — Three independent flags: `health.coverage_tracking`, `health.dependency_scanning`, `health.complexity_tracking`. Global enable: `health.enabled`. Each defaults to `true`. Per-project disable via ProjectConfig. Allows disabling a noisy dimension without affecting others.

### Affected Existing Decisions

| Decision | Current State | Proposed Change | Rationale |
|---|---|---|---|
| D23 | Four-layer intelligence: Vunnix → Skills → Memory (Layer 2.5) → Project config | Health signals join Layer 2.5 alongside review patterns and conversation facts | Health context is project-scoped learned intelligence, same injection mechanism |
| D87 | Cost budget — soft cap alerts at 2x rolling average | Health analysis explicitly excluded from cost tracking (no AI tokens) | Server-side computation; no API cost to budget |
| D134 | Queue topology: vunnix-server (I/O) + vunnix-runner (CI) | Health jobs run on vunnix-server queue | Consistent with I/O-bound classification (API reads + DB writes) |
| D195 | Memory stored in PostgreSQL | MemoryEntry gains new type `health_signal` | Additive — existing types unchanged |
| D199 | Three feature flags for memory sub-capabilities | Health adds three more independent flags under `health.*` namespace | Same pattern, separate namespace |

### Component Design

#### HealthSnapshot Model
**Current behavior:** Does not exist.
**Proposed behavior:** Time-series model recording point-in-time health measurements. Each row represents one dimension's score for one project at one point in time. The `score` field is a normalized 0–100 value (higher = healthier). The `details` JSONB holds dimension-specific data (per-file coverage, vulnerability list, complexity rankings).
**Interface changes:** New model with relationships to Project. Scopes for dimension filtering and date ranges.
**Data model changes:**
```
health_snapshots:
  id              bigint PK
  project_id      bigint FK → projects.id (indexed)
  dimension       varchar (coverage | dependency | complexity)
  score           decimal(5,2) (0.00–100.00, higher = healthier)
  details         jsonb (dimension-specific payload)
  source_ref      varchar nullable (pipeline ID, commit SHA, or similar)
  created_at      timestamp

Indices:
  (project_id, dimension, created_at DESC) — primary trend query
  (created_at) — retention cleanup
```

#### HealthDimension Enum
**Current behavior:** Does not exist.
**Proposed behavior:** Backed enum with cases: `Coverage`, `Dependency`, `Complexity`. Each case provides: `label()` (human-readable), `configKey()` (maps to `health.*_tracking` config flag), `defaultThresholds()` (returns sensible defaults). Extensible for future dimensions.
**Interface changes:** New enum used by HealthSnapshot, analyzers, and alert evaluation.
**Data model changes:** None (enum, not stored as model).

#### CoverageAnalyzer
**Current behavior:** Does not exist.
**Proposed behavior:** Service that fetches test coverage data from GitLab's pipeline API. Calls `GET /projects/:id/pipelines?ref=<default_branch>&status=success&per_page=1` to get the latest successful pipeline. Reads the `coverage` field (float, e.g., `87.5`). If coverage data is available, returns a normalized score (0–100) and details payload containing: `{coverage_percent, pipeline_id, compared_to_previous, trend_direction}`. If the pipeline has no coverage configured, returns `null` (dimension skipped gracefully). For per-file granularity (future): download Cobertura XML from job artifacts if available.
**Interface changes:** New service implementing `HealthAnalyzerContract` interface.
**Data model changes:** None.

#### DependencyAnalyzer
**Current behavior:** Does not exist.
**Proposed behavior:** Service that checks dependency health by reading lock files via GitLab's file read API. For PHP: reads `composer.lock`, extracts package versions, queries Packagist Security Advisories API (`https://packagist.org/api/security-advisories/?packages[]=vendor/package`) for known CVEs. For JS: reads `package-lock.json`, queries npm audit registry. Score: 100 = no vulnerabilities, decremented by severity (critical: -25, high: -15, medium: -5, low: -2). Details JSONB: `{php_vulnerabilities: [{package, advisory, severity, cve}], js_vulnerabilities: [...], total_count, packages_scanned}`.
**Interface changes:** New service implementing `HealthAnalyzerContract` interface.
**Data model changes:** None.

#### ComplexityAnalyzer
**Current behavior:** Does not exist.
**Proposed behavior:** Service that estimates file complexity using heuristics from source files read via GitLab API. Strategy: fetch the repository tree for configured directories (default: `app/`, `resources/js/`), read the top-N largest files (by size from tree API), count lines, function/method declarations (regex: `function\s+\w+` for PHP, `function\s+\w+|const\s+\w+\s*=.*=>` for JS/TS), and nesting depth indicators (indentation levels). Score: 100 = low complexity, decremented based on files exceeding LOC threshold (default 300 lines) or function count threshold (default 20). Details JSONB: `{hotspot_files: [{path, loc, function_count, score}], avg_complexity_score, files_analyzed}`. Rate-limited: max 20 file reads per analysis (cache tree, prioritize by file size).
**Interface changes:** New service implementing `HealthAnalyzerContract` interface.
**Data model changes:** None.

#### HealthAnalyzerContract Interface
**Current behavior:** Does not exist.
**Proposed behavior:** Interface defining the contract for all health analyzers. Method: `analyze(Project $project): ?HealthAnalysisResult`. Returns null when dimension not available (e.g., no coverage configured). `HealthAnalysisResult` is a simple DTO: `{dimension: HealthDimension, score: float, details: array, sourceRef: ?string}`.
**Interface changes:** New interface + DTO.
**Data model changes:** None.

#### HealthAnalysisService (Orchestrator)
**Current behavior:** Does not exist.
**Proposed behavior:** Central orchestrator that runs all enabled analyzers for a project. Iterates registered analyzers, skips disabled dimensions (per config flag), collects results, creates HealthSnapshot records, dispatches threshold evaluation via HealthAlertService, creates MemoryEntry health signals via bridge, and broadcasts HealthSnapshotRecorded event. Methods: `analyzeProject(Project): Collection<HealthSnapshot>`, `registerAnalyzer(HealthAnalyzerContract)`.
**Interface changes:** New service, injected via constructor DI with tagged analyzers.
**Data model changes:** None (writes to health_snapshots table).

#### HealthAlertService
**Current behavior:** Does not exist (AlertEventService handles infrastructure alerts).
**Proposed behavior:** Evaluates health snapshots against configured thresholds. Default thresholds: coverage < 70% (warning), < 50% (critical); vulnerability count > 0 critical/high (warning), > 3 (critical); complexity score < 50 (warning), < 30 (critical). Compares current snapshot to previous snapshot for the same dimension to detect trends (coverage dropping > 5% in a week = warning). Creates AlertEvent records (reuses existing model with new alert_type values). For warning/critical alerts, dispatches CreateGitLabIssue job with a health-specific template. Follows same stateful pattern as AlertEventService (idempotent, resolves when threshold recovered).
**Interface changes:** New service. Uses AlertEvent model (no changes to model).
**Data model changes:** Adds new values to AlertEvent's alert_type: `health_coverage_decline`, `health_vulnerability_found`, `health_complexity_spike`.

#### MemoryInjectionService (modified)
**Current behavior:** Builds review guidance from review_pattern, conversation_fact, cross_mr_pattern memories.
**Proposed behavior:** Add `buildHealthGuidance(Project): string` method that queries active `health_signal` MemoryEntry records and formats them as actionable review context. Example output: "Recent codebase health signals: Test coverage in app/Services/ is 72% (below 80% target). 2 high-severity dependency vulnerabilities pending. Prioritize test coverage and security in this review." Method called from existing `buildReviewGuidance()` — health guidance appended to review memory section, sharing the D200 token budget.
**Interface changes:** New public method `buildHealthGuidance(Project): string`. Existing `buildReviewGuidance()` updated to include health signals.
**Data model changes:** None.

#### AnalyzeProjectHealth Job
**Current behavior:** Does not exist.
**Proposed behavior:** Queue job on `vunnix-server`. Accepts project_id. Loads project, calls `HealthAnalysisService::analyzeProject()`. Creates health_signal MemoryEntry records for significant findings (score below threshold or trending down). Wrapped in try/catch — logs warning on failure (non-blocking). Retries 2x with 60-second backoff.
**Interface changes:** New job.
**Data model changes:** None.

#### AnalyzeCodebaseHealth Command
**Current behavior:** Does not exist.
**Proposed behavior:** Artisan command `health:analyze` that iterates all enabled projects (where health.enabled and project is active) and dispatches `AnalyzeProjectHealth` jobs. Scheduled daily at 05:00 UTC (`->dailyAt('05:00')->withoutOverlapping()`). Can be run manually for a specific project: `health:analyze --project=5`. Logs project count and dispatch summary.
**Interface changes:** New command. Registered in `routes/console.php`.
**Data model changes:** None.

#### HealthSnapshotRecorded Event
**Current behavior:** Does not exist.
**Proposed behavior:** Broadcast event implementing `ShouldBroadcast`. Broadcasts to `project.{projectId}.health` channel. Payload: project_id, dimension, score, trend_direction (up/down/stable), created_at. `broadcastQueue('vunnix-server')` per D134. Frontend subscribes via Laravel Echo for real-time dashboard updates.
**Interface changes:** New event.
**Data model changes:** None.

#### DashboardHealthController
**Current behavior:** Does not exist.
**Proposed behavior:** API controller with three endpoints:
1. `trends(Project, Request)` — Returns health snapshots for a project over a date range. Accepts: `dimension` (optional filter), `from`/`to` dates (default last 30 days). Returns array of snapshots ordered by created_at.
2. `summary(Project)` — Returns latest snapshot per dimension + trend direction (vs 7 days ago). Single object with per-dimension scores and status.
3. `alerts(Project)` — Returns active health AlertEvents for the project. Cursor-paginated.
Authorization: requires project membership (any role with dashboard access).
**Interface changes:** New controller + routes.
**Data model changes:** None.

#### Frontend: DashboardHealthPanel
**Current behavior:** Does not exist.
**Proposed behavior:** Dashboard tab component showing project health overview. Three metric cards (one per dimension) showing current score, trend arrow, and last-checked timestamp. Uses BaseCard (ext-010). Below cards: HealthTrendChart showing selected dimension over time. Below chart: active health alerts list. Real-time updates via Laravel Echo subscription to `project.{projectId}.health` channel. Graceful empty state when no health data available ("Health analysis has not run yet for this project").
**Interface changes:** New component.
**Data model changes:** None.

#### Frontend: HealthTrendChart
**Current behavior:** Does not exist.
**Proposed behavior:** Line chart component that renders health score trends over time. Accepts dimension and date-range props. Renders using native SVG (no chart library dependency). Shows score on Y-axis (0–100), dates on X-axis. Threshold line drawn at configured warning level. Color: green above threshold, yellow near threshold, red below. Tooltip on hover showing exact score and date.
**Interface changes:** New component.
**Data model changes:** None.

#### Frontend: HealthAlertCard
**Current behavior:** Does not exist.
**Proposed behavior:** Card component rendering a single health alert. Shows: dimension icon, alert message, severity badge, when triggered, link to GitLab issue (if created). Uses BaseBadge for severity coloring.
**Interface changes:** New component.
**Data model changes:** None.

#### Frontend: health.ts Pinia Store
**Current behavior:** Does not exist.
**Proposed behavior:** Pinia store managing health state. State: `summary` (latest per-dimension scores), `trends` (time-series data for selected dimension), `alerts` (active health alerts), `loading` flags. Actions: `fetchSummary(projectId)`, `fetchTrends(projectId, dimension, dateRange)`, `fetchAlerts(projectId)`. Subscribes to Reverb channel for real-time score updates.
**Interface changes:** New store.
**Data model changes:** None.

### Dependencies

- **Requires:** All prerequisites exist and are implemented:
  - ext-012 Project Memory (MemoryEntry model, MemoryInjectionService, extraction patterns)
  - Infrastructure monitoring (AlertEvent model, AlertEventService pattern, T104)
  - Metrics dashboard infrastructure (dashboard tabs, Reverb broadcasting, T73-T87)
  - CreateGitLabIssue job (T56)
  - GitLabClient service (file reads, pipeline queries)
- **Unblocks:**
  - Cross-project health benchmarking (future ext) — compare health scores across all projects
  - Risk-adjusted code review (future ext) — weight review findings by file health scores
  - Developer coaching (future ext) — personalized review focus based on which files a developer changes relative to health hotspots
  - Compliance dashboards (future ext) — exportable health reports for audit trails
  - Predictive defect detection (future ext) — correlate health trends with bug introduction rates

### Spike Plan

**Question to answer:** Can Vunnix obtain meaningful health metrics from GitLab's built-in APIs without requiring users to install external services?

**Spike scope:**
- Timeboxed to: T276 (single task, first in sequence)
- Deliverable: Working proof-of-concept PHP script that (1) queries a GitLab project's pipeline API for coverage data, (2) reads `composer.lock` via file API and queries Packagist security advisories, (3) reads a source file and computes LOC/function count. Results documented in `docs/spikes/ext-018-health-data-sources.md`.
- Success criteria:
  1. GitLab `GET /projects/:id/pipelines` returns a `coverage` field for projects with CI coverage configured (tested against real GitLab instance or documented from GitLab API docs)
  2. Packagist Security Advisories API (`https://packagist.org/api/security-advisories/`) returns vulnerability data for given packages
  3. File content from GitLab API can be parsed for basic complexity heuristics (LOC, function count) in < 500ms per file

**If spike succeeds:** Proceed with all three dimensions (coverage, dependency, complexity).
**If spike partially succeeds:** Ship only the dimensions that work. Coverage and dependency scanning are most likely to succeed; complexity can be deferred.
**If spike fails:** Descope to dependency-only health monitoring (can always parse lock files locally) and reassess data source strategy.

### Risk Mitigation

| Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|
| GitLab pipeline API doesn't return coverage for all project configs | Coverage dimension unavailable for some projects | Medium | Graceful degradation: CoverageAnalyzer returns null, dimension skipped. Dashboard shows "Configure CI coverage to enable this metric." |
| Alert fatigue from too many health notifications | Developers ignore alerts, Vunnix credibility drops | Medium | Conservative default thresholds (only critical issues alert). Per-project threshold customization. AlertEvent dedup prevents repeat alerts for same ongoing issue. |
| GitLab API rate limits hit during file reads for complexity analysis | Complexity analyzer fails or slows down | Low | Cap file reads at 20 per analysis run. Cache repository tree. Run analysis during low-traffic hours (05:00 UTC). |
| Packagist/npm advisory APIs unavailable or slow | Dependency scanning fails | Low | Retry with backoff. Cache advisory data for 24 hours. Non-blocking — dependency score returns null, dashboard shows "Unavailable". |
| Health signal memory entries pollute review prompts | Review quality degrades from too much health context | Low | Health signals share D200 token budget with other memories. Confidence-scored: health signals compete fairly with review patterns. Admin can archive noisy entries. |
| Scope creep — "just add one more dimension" | Extension never ships | Medium | Explicit scope boundary: three dimensions only. Architecture drift and duplication detection are explicitly out-of-scope and documented as future extensions. |

### Rollback Plan

- **Feature flags:** `health.enabled` master switch + per-dimension flags in `config/health.php`. Set `health.enabled = false` to instantly disable all health analysis without code deployment.
- **Database:** Single `health_snapshots` table. Rollback migration drops it. No foreign keys point TO this table. Health-type AlertEvent records become orphaned but harmless (filter by type in dashboard already handles unknown types).
- **Memory entries:** `health_signal` MemoryEntry records can be bulk-archived via admin UI or `DELETE FROM memory_entries WHERE type = 'health_signal'`. MemoryInjectionService gracefully handles zero health signals.
- **Git revert scope:** All tasks are additive. Revert the merge commit to remove all new files and modifications.
- **Data recovery:** Health snapshots are derived data (re-analyzable at any time). No unique user data is lost on rollback.

### Tasks

#### T276: Spike — validate GitLab API health data sources
**File(s):** `docs/spikes/ext-018-health-data-sources.md`
**Action:** Research and document GitLab API responses for health data. Verify: (1) `GET /projects/:id/pipelines?status=success&per_page=1` includes `coverage` field on GitLab Free, (2) Packagist Security Advisories API works for checking composer.lock packages, (3) GitLab file read API can retrieve source files efficiently for complexity heuristics. Document API response shapes, rate limits, and edge cases (no coverage configured, private packages, large repos). Write spike results document.
**Verification:** Spike document exists with clear go/no-go per dimension. Decision gate: which dimensions proceed.

#### T277: Create health configuration
**File(s):** `config/health.php`
**Action:** New config file with keys: `'enabled' => (bool) env('VUNNIX_HEALTH_ENABLED', true)`, `'coverage_tracking' => (bool) env('VUNNIX_HEALTH_COVERAGE', true)`, `'dependency_scanning' => (bool) env('VUNNIX_HEALTH_DEPENDENCIES', true)`, `'complexity_tracking' => (bool) env('VUNNIX_HEALTH_COMPLEXITY', true)`, `'analysis_directories' => ['app/', 'resources/js/']` (for complexity analyzer), `'max_file_reads' => 20`, `'snapshot_retention_days' => 180`, `'thresholds' => ['coverage' => ['warning' => 70, 'critical' => 50], 'dependency' => ['warning' => 1, 'critical' => 3], 'complexity' => ['warning' => 50, 'critical' => 30]]`.
**Verification:** `config('health.enabled')` returns `true`. All keys accessible.

#### T278: Create health_snapshots migration
**File(s):** `database/migrations/2026_02_20_100000_create_health_snapshots_table.php`
**Action:** Create migration for `health_snapshots` table. Guard with `DB::connection()->getDriverName() === 'pgsql'` for JSONB columns (SQLite compat per CLAUDE.md learnings). Columns: id (bigint PK), project_id (bigint FK → projects.id), dimension (varchar), score (decimal 5,2), details (jsonb), source_ref (varchar nullable), created_at (timestamp). Indices: composite (project_id, dimension, created_at DESC) for trend queries, (created_at) for retention cleanup.
**Verification:** `php artisan migrate` succeeds. `php artisan migrate:rollback --step=1` succeeds. Table exists with correct columns and indices.

#### T279: Create HealthSnapshot model
**File(s):** `app/Models/HealthSnapshot.php`
**Action:** Eloquent model with: `$fillable` for all columns, `$casts` for details (array), score (decimal), created_at (datetime). `$timestamps = false` (only created_at, no updated_at). Relationships: `project(): BelongsTo<Project>`. Scopes: `scopeForProject($query, int $projectId)`, `scopeOfDimension($query, string $dimension)`, `scopeRecent($query, int $days = 30)`. Add `@return` PHPDoc for relationships (Larastan). Add `@return array{...}` PHPDoc for `casts()` method (Larastan 3.x workaround per CLAUDE.md).
**Verification:** `composer analyse` passes. Model can be instantiated in tinker.

#### T280: Add Project→healthSnapshots relationship
**File(s):** `app/Models/Project.php`
**Action:** Add `healthSnapshots(): HasMany<HealthSnapshot>` relationship with `@return` PHPDoc generic.
**Verification:** `composer analyse` passes. `$project->healthSnapshots` returns collection.

#### T281: Create HealthDimension enum
**File(s):** `app/Enums/HealthDimension.php`
**Action:** Backed string enum with cases: `Coverage = 'coverage'`, `Dependency = 'dependency'`, `Complexity = 'complexity'`. Methods: `label(): string` (human-readable display name), `configKey(): string` (maps to `health.*` config key), `defaultWarningThreshold(): float`, `defaultCriticalThreshold(): float`, `alertType(): string` (maps to AlertEvent alert_type value).
**Verification:** `composer analyse` passes. Enum values accessible.

#### T282: Create HealthAnalyzerContract interface and HealthAnalysisResult DTO
**File(s):** `app/Contracts/HealthAnalyzerContract.php`, `app/DTOs/HealthAnalysisResult.php`
**Action:** Interface: `analyze(Project $project): ?HealthAnalysisResult`. DTO: readonly class with properties `dimension` (HealthDimension), `score` (float), `details` (array), `sourceRef` (?string). Null return from analyzer means dimension not available for this project.
**Verification:** `composer analyse` passes.

#### T283: Create CoverageAnalyzer service
**File(s):** `app/Services/Health/CoverageAnalyzer.php`
**Action:** Implements `HealthAnalyzerContract`. Calls `GitLabClient` to fetch latest successful pipeline for the project's default branch. Reads `coverage` field from pipeline response. If null (no coverage configured), returns null. Otherwise, returns HealthAnalysisResult with score = coverage percentage, details = `{coverage_percent, pipeline_id, pipeline_url, compared_to_previous}`. To compute `compared_to_previous`: load the most recent HealthSnapshot for this project + dimension and calculate delta.
**Verification:** Unit test with mocked GitLabClient: pipeline with coverage → returns result; pipeline without coverage → returns null.

#### T284: Create DependencyAnalyzer service
**File(s):** `app/Services/Health/DependencyAnalyzer.php`
**Action:** Implements `HealthAnalyzerContract`. Reads `composer.lock` via `GitLabClient::getFile()`. Parses JSON to extract package names and versions. Queries Packagist Security Advisories API (`GET https://packagist.org/api/security-advisories/?packages[]=vendor/name`) via `Http::get()` with retry. Optionally reads `package-lock.json` and queries npm registry audit endpoint. Calculates score: 100 minus severity penalties (critical: -25, high: -15, medium: -5, low: -2, capped at 0). Returns HealthAnalysisResult with details = `{php_vulnerabilities: [...], js_vulnerabilities: [...], total_count, packages_scanned}`. Returns null if neither lock file exists.
**Verification:** Unit test with mocked HTTP: lock file with known vulnerable package → returns result with vulnerability details; no lock files → returns null.

#### T285: Create ComplexityAnalyzer service
**File(s):** `app/Services/Health/ComplexityAnalyzer.php`
**Action:** Implements `HealthAnalyzerContract`. Reads repository tree via `GitLabClient::getTree()` for configured analysis directories. Filters for PHP/JS/TS/Vue files. Sorts by file size (descending), takes top `max_file_reads` files. For each file: reads content via `GitLabClient::getFile()`, counts LOC and function/method declarations (regex patterns). Calculates per-file complexity score. Overall score: 100 minus penalties for files exceeding thresholds (>300 LOC: -3 per file, >500 LOC: -5 per file, >20 functions: -3 per file). Details JSONB: `{hotspot_files: [{path, loc, function_count, score}], files_analyzed, avg_loc}`.
**Verification:** Unit test with mocked file reads: mix of small/large files → returns result with correct hotspots identified.

#### T286: Create HealthAnalysisService orchestrator
**File(s):** `app/Services/Health/HealthAnalysisService.php`
**Action:** Central service that runs all enabled analyzers for a project. Constructor receives tagged analyzers via Laravel service container. Method `analyzeProject(Project): Collection<HealthSnapshot>`: (1) iterate analyzers, skip if dimension's config flag is disabled, (2) call each analyzer's `analyze()`, skip null results, (3) create HealthSnapshot record for each result, (4) dispatch threshold evaluation via HealthAlertService, (5) create MemoryEntry health signals for significant findings (score < warning threshold or >5% decline from previous), (6) broadcast HealthSnapshotRecorded event. Register analyzer bindings in `AppServiceProvider` via tagged container bindings.
**Verification:** Unit test with mocked analyzers: all return results → 3 snapshots created; one returns null → 2 snapshots created; dimension disabled → analyzer not called.

#### T287: Create HealthAlertService
**File(s):** `app/Services/Health/HealthAlertService.php`
**Action:** Evaluates health snapshots against thresholds. Method `evaluateThresholds(Project, Collection<HealthSnapshot>): void`. For each snapshot: compare score against configured thresholds (per-project override from ProjectConfig, falling back to config/health.php defaults). If threshold crossed: check for existing active AlertEvent of same type for this project (dedup). If no existing alert: create AlertEvent with appropriate severity and dispatch `CreateGitLabIssue` with health-specific issue template (title: "⚠️ Health Alert: {dimension} threshold crossed", body: score, trend, affected files, suggested action). If threshold recovered: resolve existing AlertEvent. Follow same pattern as `AlertEventService::evaluateAlertCondition()`.
**Verification:** Unit test: score below threshold → AlertEvent created; score recovered → AlertEvent resolved; existing active alert → no duplicate created.

#### T288: Create AnalyzeProjectHealth job
**File(s):** `app/Jobs/AnalyzeProjectHealth.php`
**Action:** Queue job on `vunnix-server`. Accepts project_id. Loads project, calls `HealthAnalysisService::analyzeProject()`. Wrapped in try/catch — logs warning on failure, never throws. Retries 2x with 60-second backoff. `$tries = 3`, `$backoff = 60`.
**Verification:** Feature test: dispatch job → verify health_snapshots created. Job failure → logged, retried.

#### T289: Create AnalyzeCodebaseHealth scheduled command
**File(s):** `app/Console/Commands/AnalyzeCodebaseHealth.php`, `routes/console.php`
**Action:** Artisan command `health:analyze`. Default: iterates all enabled projects (where health is enabled and project is active). Option: `--project={id}` for single project analysis. Dispatches `AnalyzeProjectHealth` job for each project. Logs summary (N projects queued for analysis). Schedule in `routes/console.php`: `->dailyAt('05:00')->withoutOverlapping()->when(fn(): bool => config('health.enabled'))`.
**Verification:** `php artisan health:analyze` runs without error. `php artisan health:analyze --project=1` queues single job. Schedule registered.

#### T290: Create CleanHealthSnapshots scheduled command
**File(s):** `app/Console/Commands/CleanHealthSnapshots.php`, `routes/console.php`
**Action:** Artisan command `health:clean-snapshots`. Deletes health_snapshots older than `snapshot_retention_days` (default 180). Logs count of deleted records. Schedule in `routes/console.php`: `->weekly()->sundays()->at('04:00')`.
**Verification:** `php artisan health:clean-snapshots` runs. Old records deleted. Recent records preserved.

#### T291: Bridge health signals to Project Memory
**File(s):** `app/Services/Health/HealthAnalysisService.php` (part of T286 implementation)
**Action:** Within `analyzeProject()`, after creating snapshots, create MemoryEntry records for significant health findings. Type: `health_signal`. Category: dimension name (coverage, dependency, complexity). Content JSONB: `{signal, score, trend, details_summary}`. Confidence: based on data quality (coverage from pipeline = 80, dependency from advisory API = 70, complexity heuristic = 50). Source_meta: `{snapshot_id, analysis_timestamp}`. Dedup: update existing health_signal for same dimension instead of creating new one (one active signal per dimension per project).
**Verification:** Covered by T286 tests + integration test in T304.

#### T292: Extend MemoryInjectionService with health guidance
**File(s):** `app/Services/MemoryInjectionService.php`
**Action:** Add `buildHealthGuidance(Project $project): string` method. Queries active MemoryEntry records of type `health_signal`. Formats as actionable sentences: "Test coverage is at {X}% (below {threshold}% target) — prioritize test coverage." / "N dependency vulnerabilities found (M critical) — check security impact." / "Complexity hotspots detected in: {files}." Call this from existing `buildReviewGuidance()` method — append health guidance to review memory section, sharing the D200 token budget.
**Verification:** Unit test: project with health signals → guidance includes health context; no health signals → empty string returned.

#### T293: Create HealthSnapshotRecorded broadcast event
**File(s):** `app/Events/HealthSnapshotRecorded.php`
**Action:** Event implementing `ShouldBroadcast`. Broadcasts to `project.{projectId}.health` channel. Payload: project_id, dimension (string), score (float), trend_direction (string: up/down/stable), created_at. `broadcastQueue('vunnix-server')` per D134.
**Verification:** Unit test: event constructs with correct data. `composer analyse` passes.

#### T294: Create DashboardHealthController API endpoints
**File(s):** `app/Http/Controllers/Api/DashboardHealthController.php`, `app/Http/Resources/HealthSnapshotResource.php`
**Action:** Controller with endpoints: `trends(Request, Project)` — returns health snapshots filtered by optional `dimension` and `from`/`to` dates (default 30 days), ordered by created_at. `summary(Project)` — returns latest snapshot per dimension + trend_direction (vs 7 days ago). `alerts(Project)` — returns active health-type AlertEvents. Resource: HealthSnapshotResource (id, dimension, score, details, source_ref, created_at). Authorization: project membership required (any role with dashboard access).
**Verification:** Feature tests for each endpoint with auth checks. `composer analyse` passes.

#### T295: Add API routes for health endpoints
**File(s):** `routes/api.php`
**Action:** Add routes under existing project scope: `Route::prefix('projects/{project}/health')->group(function () { Route::get('trends', [DashboardHealthController::class, 'trends']); Route::get('summary', [DashboardHealthController::class, 'summary']); Route::get('alerts', [DashboardHealthController::class, 'alerts']); });`
**Verification:** `php artisan route:list --path=health` shows 3 routes.

#### T296: Add Zod schemas and health composable (frontend)
**File(s):** `resources/js/types/api.ts`, `resources/js/composables/useProjectHealth.ts`
**Action:** Zod schemas: `HealthSnapshotSchema` (id, dimension, score, details, source_ref, created_at), `HealthSummarySchema` (per-dimension scores with trend_direction), `HealthAlertSchema` (reuse existing AlertEvent schema with health-type filter). Composable `useProjectHealth(projectId)`: methods `fetchSummary()`, `fetchTrends(dimension, dateRange)`, `fetchAlerts()`.
**Verification:** `npm run typecheck` passes. `npm run lint` passes.

#### T297: Create health Pinia store
**File(s):** `resources/js/stores/health.ts`
**Action:** Pinia store with state: `summary` (latest per-dimension scores or null), `trends` (time-series array for selected dimension), `alerts` (active health alerts array), `loading` (boolean), `selectedDimension` ('coverage' | 'dependency' | 'complexity'). Actions: `fetchSummary(projectId)`, `fetchTrends(projectId, dimension, dateRange)`, `fetchAlerts(projectId)`. Uses `useProjectHealth` composable.
**Verification:** `npm run typecheck` passes.

#### T298: Create HealthTrendChart component
**File(s):** `resources/js/components/HealthTrendChart.vue`
**Action:** SVG-based line chart component. Props: `data` (array of {score, created_at}), `warningThreshold` (number), `criticalThreshold` (number). Renders: line chart with score on Y-axis (0–100), dates on X-axis. Horizontal threshold lines (dashed yellow for warning, dashed red for critical). Line color: green above warning, yellow between warning/critical, red below critical. Hover tooltip showing exact score and date. Responsive width via `viewBox` and CSS.
**Verification:** `npm test -- HealthTrendChart` passes. Component renders with mock data.

#### T299: Create HealthAlertCard component
**File(s):** `resources/js/components/HealthAlertCard.vue`
**Action:** Card component for a single health alert. Props: alert object (dimension, message, severity, triggered_at, gitlab_issue_url). Renders: dimension icon, alert message, BaseBadge for severity (warning = yellow, critical = red), timestamp, link to GitLab issue if available. Uses BaseCard.
**Verification:** `npm test -- HealthAlertCard` passes.

#### T300: Create DashboardHealthPanel component
**File(s):** `resources/js/components/DashboardHealthPanel.vue`
**Action:** Main dashboard health tab component. Uses health Pinia store. On mount: fetches summary and alerts. Renders: (1) Three metric cards (BaseCard) showing per-dimension score, trend arrow (↑/↓/→), last-checked time. Click on card selects dimension. (2) HealthTrendChart for selected dimension (fetches trends on dimension change). (3) Active health alerts list using HealthAlertCard. Empty state: BaseEmptyState with "Health analysis hasn't run yet" message. Subscribes to Laravel Echo channel `project.{projectId}.health` for real-time updates.
**Verification:** `npm test -- DashboardHealthPanel` passes. Component renders all states (loading, empty, data).

#### T301: Add Health tab to DashboardPage
**File(s):** `resources/js/pages/DashboardPage.vue`
**Action:** Add "Health" tab to the dashboard tab group (alongside Activity, Quality, Cost, etc.). When selected, renders `DashboardHealthPanel` for the currently selected project. Tab visible when `config.health.enabled` (passed from backend via global config endpoint, or feature-flagged in Vue).
**Verification:** `npm test -- DashboardPage` passes (updated for new tab).

#### T302: Register health analyzer bindings in service provider
**File(s):** `app/Providers/AppServiceProvider.php`
**Action:** In `register()`: bind `CoverageAnalyzer`, `DependencyAnalyzer`, `ComplexityAnalyzer` to container, tagged as `health.analyzers`. Bind `HealthAnalysisService` with tagged injection. Wrap in try/catch per CLAUDE.md learnings (boot-time DB access guard).
**Verification:** `composer analyse` passes. Container resolves `HealthAnalysisService` with all analyzers.

#### T303: Unit tests for models, enum, analyzers, and services
**File(s):** `tests/Unit/Models/HealthSnapshotTest.php`, `tests/Unit/Enums/HealthDimensionTest.php`, `tests/Unit/Services/Health/CoverageAnalyzerTest.php`, `tests/Unit/Services/Health/DependencyAnalyzerTest.php`, `tests/Unit/Services/Health/ComplexityAnalyzerTest.php`, `tests/Unit/Services/Health/HealthAnalysisServiceTest.php`, `tests/Unit/Services/Health/HealthAlertServiceTest.php`
**Action:** Pure unit tests (Mockery, no Laravel container per CLAUDE.md learnings). Test: HealthSnapshot scopes and casts, HealthDimension enum methods, CoverageAnalyzer with mocked GitLabClient (coverage present/absent/null), DependencyAnalyzer with mocked HTTP (vulnerabilities found/clean/missing lock files), ComplexityAnalyzer with mocked file reads (large/small files), HealthAnalysisService orchestration (analyzers called, snapshots created, disabled dimensions skipped), HealthAlertService threshold evaluation (below/above/recovery).
**Verification:** `php artisan test --filter=HealthSnapshot && php artisan test --filter=HealthDimension && php artisan test --filter=CoverageAnalyzer && php artisan test --filter=DependencyAnalyzer && php artisan test --filter=ComplexityAnalyzer && php artisan test --filter=HealthAnalysisService && php artisan test --filter=HealthAlertService` all pass.

#### T304: Feature tests for jobs, commands, and integration
**File(s):** `tests/Feature/Jobs/AnalyzeProjectHealthTest.php`, `tests/Feature/Commands/AnalyzeCodebaseHealthTest.php`, `tests/Feature/HealthIntegrationTest.php`
**Action:** Feature tests with real DB: AnalyzeProjectHealth job creates snapshots (mock GitLabClient with `Http::fake()`). AnalyzeCodebaseHealth command dispatches jobs for enabled projects. Integration test: full pipeline — run analysis → snapshots created → threshold crossed → AlertEvent created → MemoryEntry health_signal created → MemoryInjectionService returns health guidance in review prompt.
**Verification:** `php artisan test --filter=AnalyzeProjectHealth && php artisan test --filter=AnalyzeCodebaseHealth && php artisan test --filter=HealthIntegration` all pass.

#### T305: Feature tests for API endpoints
**File(s):** `tests/Feature/Api/DashboardHealthControllerTest.php`
**Action:** Feature tests for: trends endpoint (with/without dimension filter, date range), summary endpoint (returns latest per dimension + trend), alerts endpoint (returns active health AlertEvents). Auth checks: unauthenticated rejected, non-member rejected, member allowed. Seed health_snapshots and AlertEvent records via factories.
**Verification:** `php artisan test --filter=DashboardHealthController` passes.

#### T306: Frontend component tests
**File(s):** `tests/js/components/HealthTrendChart.test.ts`, `tests/js/components/HealthAlertCard.test.ts`, `tests/js/components/DashboardHealthPanel.test.ts`
**Action:** Vitest + Vue Test Utils tests for: HealthTrendChart renders SVG with data points and threshold lines, HealthAlertCard renders severity badge and issue link, DashboardHealthPanel renders metric cards and fetches data on mount (mock axios). Test empty state, loading state, and data state. Mock Pinia store with `setActivePinia(createPinia())` per CLAUDE.md learnings.
**Verification:** `npm test -- HealthTrendChart && npm test -- HealthAlertCard && npm test -- DashboardHealthPanel` pass.

#### T307: End-to-end verification test
**File(s):** `tests/Feature/HealthEndToEndTest.php`
**Action:** Single integration test exercising the full pipeline: (1) Create project with health enabled, (2) Mock GitLabClient to return pipeline with 65% coverage (below 70% warning threshold), lock file with 1 critical vulnerability, and source files with varying complexity, (3) Run `HealthAnalysisService::analyzeProject()`, (4) Verify 3 health_snapshots created with correct scores, (5) Verify AlertEvent created for coverage decline and vulnerability, (6) Verify MemoryEntry health_signal created, (7) Call `MemoryInjectionService::buildReviewGuidance()` and verify output mentions coverage and vulnerability, (8) Run `CleanHealthSnapshots` with 0 retention → verify old snapshots cleaned. This validates the complete health intelligence loop.
**Verification:** `php artisan test --filter=HealthEndToEnd` passes.

#### T308: Update decisions index
**File(s):** `docs/reference/spec/decisions-index.md`
**Action:** Add entries for D211 through D217 with one-line summaries referencing ext-018.
**Verification:** Decisions index contains all new entries. No numbering gaps.

### Verification

- [ ] Spike completed: data source strategy validated for all three dimensions (T276)
- [ ] `health_snapshots` table created with correct schema and indices
- [ ] Coverage analyzer returns score from GitLab pipeline API; returns null when no coverage configured
- [ ] Dependency analyzer detects vulnerabilities from Packagist advisories; handles missing lock files
- [ ] Complexity analyzer identifies hotspot files via heuristic analysis
- [ ] Health analysis scheduled daily (05:00 UTC) and can be triggered manually
- [ ] Threshold violations create AlertEvents with appropriate severity
- [ ] GitLab issues auto-created for warning/critical health alerts
- [ ] Health signals stored as MemoryEntry (type: health_signal) and injected into code review prompts
- [ ] Dashboard Health tab shows per-dimension scores, trend charts, and active alerts
- [ ] Real-time dashboard updates via Reverb broadcasting
- [ ] Feature flags independently disable each health dimension
- [ ] No health data available → graceful empty state (no errors, helpful message)
- [ ] Old snapshots cleaned by weekly retention command (180-day default)
- [ ] No AI tokens consumed by health analysis (all server-side computation)
- [ ] All existing tests still pass (no regressions)
- [ ] New tests cover all services, jobs, commands, controllers, and frontend components
- [ ] `composer analyse` passes (PHPStan level 8)
- [ ] `npm run typecheck` and `npm run lint` pass

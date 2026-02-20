# Assessment: Proactive Codebase Health Intelligence

**Date:** 2026-02-19
**Requested by:** Project owner (brainstorming session)
**Trigger:** Natural evolution after ext-012 (Project Memory) — shifting Vunnix from reactive to proactive

## What

Add a scheduled health engine that continuously monitors the default branch of enabled projects for test coverage drift, complexity hotspots, dependency health, and code duplication. When configurable thresholds are crossed, automatically create GitLab issues with actionable context. Feed health signals into code review prompts via the existing memory injection pipeline. Add a health trends dashboard view to the Vue SPA.

This is the single biggest mode shift available to Vunnix: from a system that *waits* for events (MR opened, @ai mentioned) to one that *actively watches* and surfaces problems before they reach code review.

## Classification

**Tier:** 3 (Architectural)
**Rationale:** Introduces a new scheduled analysis engine spanning 8+ architectural layers (database, models, services, jobs, scheduled commands, API controllers, frontend components, memory injection). Estimated 40-60 new files across backend and frontend. Requires 5+ new decisions (data source strategy, threshold model, scan cadence, external tool integration, health-to-memory bridging). However, it heavily *composes* existing infrastructure rather than replacing it — ext-012 established nearly every architectural pattern needed.

**Modifiers:**
- [ ] `breaking` — No existing APIs or schemas change; purely additive
- [ ] `multi-repo` — Single repository
- [x] `spike-required` — How Vunnix obtains health metrics (coverage %, complexity scores) without requiring users to set up external services (SonarQube, Codecov) needs research. Can we parse GitLab CI job artifacts? Run analysis via the executor? Use built-in tools (PHPStan, phpunit coverage)?
- [ ] `deprecation` — Nothing removed
- [ ] `migration` — New tables only, no data migration

## Impact Analysis

### Components Affected

| Component | Impact | Files (est.) |
|---|---|---|
| **Database / Migrations** | 2-3 new tables (health_snapshots, health_alerts, health_thresholds) | 3 |
| **Eloquent Models** | New models + extend Project relationship | 4 |
| **Services** | HealthEngine, CoverageAnalyzer, ComplexityAnalyzer, DependencyScanner, HealthAlertService, extend MemoryInjectionService | 6-8 |
| **Queue Jobs** | RunHealthCheck, CreateHealthAlertIssue, AggregateHealthTrends | 3 |
| **Console Commands** | `health:analyze` scheduled command | 1 |
| **Events / Broadcasting** | HealthCheckCompleted, HealthThresholdCrossed | 2 |
| **API Controllers** | DashboardHealthController (trends, summary, alerts endpoints) | 1-2 |
| **API Resources** | HealthSnapshotResource, HealthAlertResource | 2 |
| **FormRequests** | HealthThresholdRequest (admin config) | 1 |
| **Agent Tools** | ReadHealthStatus (conversation engine context) | 1 |
| **Vue Components** | HealthTrendsPanel, HealthAlertsList, HealthMetricCard, coverage/complexity charts | 4-5 |
| **Pinia Stores** | health.ts store | 1 |
| **TypeScript Types** | Zod schemas for health API responses | 1 |
| **Routes** | api.php additions | 1 |
| **Config** | config/health.php (thresholds, cadence, feature flags) | 1 |
| **Tests (PHP)** | Unit + Feature tests for services, jobs, commands, controllers | 15-20 |
| **Tests (Vue)** | Component tests for health dashboard | 4-5 |
| **Total** | | **~50-60** |

### Relevant Decisions

| Decision | Summary | Relationship |
|---|---|---|
| D23 | Three-layer intelligence (global → skills → project config) | **Enables** — health signals slot into Layer 2.5 alongside project memory |
| D29 | Dashboard visibility — project-scoped metrics with admin-only cost | **Enables** — dashboard infrastructure ready for health views |
| D48 | Metrics scope — all roles (engineering + PM + designer) | **Enables** — health metrics are engineering-focused, fits multi-role model |
| D70 | Dashboard RBAC — permission-controlled views | **Enables** — health view inherits existing permission model |
| D87 | Cost budget — soft cap alerts at 2x rolling average | **Constrains** — health scans that call Claude API add unbudgeted costs; prefer local analysis |
| D112 | Prompt improvement is metric-triggered | **Enables** — health metrics become new triggers for prompt tuning |
| D126 | CLI/SDK alignment — shared severity definitions | **Constrains** — health severity classifications must align with existing review severity model |
| D134 | Separate Redis queues (vunnix-server + vunnix-runner) | **Constrains** — health jobs run on vunnix-server queue (local analysis, not CI pipeline) |
| D136 | Embedding pipeline + RAG deferred post-v1 | **Constrains** — health analysis must use structured queries, not vector search |
| D144 | Automated PAT rotation reminder (weekly schedule) | **Enables** — establishes scheduler pattern for health checks |
| D145 | pgvector deferred post-v1 | **Constrains** — same as D136; structured extraction only |
| D195-200 | Project Memory decisions (storage, extraction, injection, confidence, feature flags, token cap) | **Enables** — health signals flow through identical infrastructure |

### Dependencies

**Requires first:**
- ext-012 Project Memory (completed) — provides MemoryEntry model, injection service, extraction patterns
- Infrastructure monitoring (T104, completed) — provides AlertEvent model, AlertEventService patterns
- Metrics infrastructure (T83-T84, completed) — provides materialized views, aggregation patterns
- Dashboard infrastructure (T73-T87, completed) — provides controller patterns, Reverb broadcasting, Vue dashboard tabs
- CreateGitLabIssue job (T56, completed) — provides automated issue creation pattern

**Spike required before implementation:**
- Data source strategy: how to obtain coverage %, cyclomatic complexity, and dependency health metrics without requiring users to install external services (SonarQube, Codecov). Research options: (a) parse GitLab CI job artifacts (coverage reports), (b) run analysis tools in executor Docker image, (c) use built-in tools via GitLab API (PHPStan output, composer audit), (d) simple file-level heuristics (LOC, function count, import depth).

**Unblocks:**
- Cross-project health benchmarking (future) — compare health across all enabled projects
- Risk-adjusted code review (future) — reviews weighted by file health signals
- Developer coaching (future) — personalized review focus based on health trends in their changed files
- Compliance dashboards (future) — health reports for audit trails
- Predictive defect detection (future) — correlate health trends with bug introduction rates

## Risk Factors

- **Data source uncertainty (spike-required):** The biggest risk. If reliable health metrics require users to configure external services (SonarQube, Codecov), adoption will be low for self-hosted GitLab Free users who chose Vunnix *because* they want simplicity. The spike must identify a zero-config or near-zero-config data source strategy.
- **Signal-to-noise ratio:** If health alerts are too sensitive (false positives) or too dull (missed real problems), developers will ignore them — the same "alert fatigue" problem that plagues monitoring systems. Configurable thresholds with sensible defaults and confidence scoring (from ext-012 patterns) are critical.
- **Cost of analysis:** If health scans call the Claude API (via executor), costs scale with number of projects × scan frequency. Prefer local analysis tools (PHPStan, coverage parsers) that don't consume AI tokens. Reserve AI for *interpreting* results, not *generating* them.
- **Scope creep:** "Codebase health" is infinitely expandable. Must ship with 2-3 concrete health dimensions (coverage + complexity + dependencies) and resist adding architecture drift, duplication, etc. until the core loop proves value.
- **GitLab API rate limits:** Frequent scans reading repository files could hit GitLab API limits, especially for large projects. Batch reads and cache aggressively.

## Recommendation

**Proceed to planning-extensions** after completing the spike on data source strategy.

The spike should answer: "Can Vunnix obtain meaningful health metrics (test coverage %, per-file complexity) by parsing GitLab CI job artifacts or running local analysis tools, without requiring users to install external services?" If yes → plan full feature. If no → descope to dependency health only (which `composer audit` / `npm audit` already provide locally) and defer coverage/complexity to post-v1.

The spike can be embedded as Task 1 of the planning document rather than blocking the entire plan — the plan should have a decision gate after the spike that determines which health dimensions are feasible.

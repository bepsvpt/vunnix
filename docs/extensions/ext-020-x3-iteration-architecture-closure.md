## Extension 020: X3 Iteration Architecture Closure

### Trigger
Ext-019 established the migration foundation, but the audit outcomes are not fully closed. Remaining gaps include frontend decomposition depth, alert/agent decomposition, broader platform port coverage, contract-aware Fast Lane behavior, and measurable architecture success metrics.

### Scope
What it does:
- Completes remaining X3 architecture outcomes defined in `docs/audits/architecture/2026-02-19.md`
- Finishes frontend feature-slice cutover by moving real store logic under `resources/js/features/*`
- Decomposes `AlertEventService` and `VunnixAgent` into registry/provider-based components
- Expands explicit external ports beyond GitLab (`AiProviderPort`, `PipelineExecutorPort`, `RealtimePort`, `NotificationPort`)
- Upgrades Fast Lane to module-targeted/contract-aware execution
- Adds architecture metrics collection so X3 targets are measured continuously

What it does NOT do:
- Does not move Vunnix to microservices or multi-repo topology
- Does not change product-facing behavior or remove existing workflows
- Does not change GitLab as source of truth
- Does not require immediate deletion of all compatibility adapters in one release

### Architecture Fit
- **Components affected:** Chat module, Observability module, TaskOrchestration module, frontend feature slices, CI workflows, architecture verification scripts, architecture docs
- **Extension points used:** ext-019 module boundaries, registry kernel, outbox/event model, existing queue topology (`vunnix-server`, `vunnix-runner-*`), existing Fast/Full CI split
- **New tables/endpoints/services:** New table `architecture_iteration_metrics`; new rule/provider registries for alerts and agent composition; additional cross-module port contracts and compatibility adapters

### New Decisions
- **D224:** Ext-019 closure requires implementation-level cutover of frontend feature slices (not scaffold-only re-exports) while retaining temporary compatibility exports behind enforcement checks — completes D221 intent without disruptive rewrite.
- **D225:** Observability alert evaluation is decomposed into `AlertRule` plug-ins behind an `AlertRuleRegistry`, with `AlertEventService` reduced to orchestration facade — lowers change collision and isolates rule testing.
- **D226:** Chat agent assembly is decomposed into composable providers (instructions/toolset/context/model options), with `VunnixAgent` acting as coordinator — preserves AI SDK integration while reducing class-level coupling.
- **D227:** External integration boundaries are standardized via explicit ports (`AiProviderPort`, `PipelineExecutorPort`, `RealtimePort`, `NotificationPort`) and compatibility adapters — aligns implementation with audit-defined contract-first architecture.
- **D228:** Fast Lane becomes module-targeted and changed-contract-aware (including contract tests) while Full Lane remains protected-branch merge gate — closes D222 execution gap.
- **D229:** X3 success metrics are tracked via weekly persisted architecture snapshots and CI timing inputs — converts audit targets into observable operating metrics.

### Affected Existing Decisions

| Decision | Current State | Proposed Change | Rationale |
|---|---|---|---|
| D221 | Feature slices allowed with compatibility exports during migration | Require real feature-owned store/composable logic for chat/admin/dashboard/activity; compatibility exports become transitional shims only | Completes migration objective and reduces frontend coupling |
| D222 | Fast Lane + Full Lane model exists | Fast Lane must run changed-module test scopes plus changed-contract validation, not only broad unit/test buckets | Achieves intended feedback speed and contract safety |
| D218 | Modular monolith with explicit contracts | Expand explicit contract boundaries to additional platform ports and enforce usage in modules | Reduces hidden coupling at external boundaries |
| D219 | Registry-driven kernel for orchestration | Apply same plugin decomposition pattern to alerting and agent assembly hotspots | Aligns architecture style across high-churn paths |
| D220 | Outbox/event boundary active | Integrate outbox/contract checks into changed-contract Fast Lane path | Prevents contract drift and regression risk |

### Component Design

#### Frontend Feature Slice Cutover
**Current behavior:** `resources/js/features/*` store files are mostly re-export stubs to legacy global stores.  
**Proposed behavior:** Store logic, API bindings, and slice-specific helpers live in feature-owned paths; legacy store files become temporary compatibility exports or facades.  
**Interface changes:** Existing `useConversationsStore`/`useAdminStore` public signatures preserved during cutover.  
**Data model changes:** None.

#### Observability Alert Rule Registry
**Current behavior:** `AlertEventService` contains broad rule logic and side-effect handling in one class.  
**Proposed behavior:** Rule evaluation moves to `AlertRule` implementations registered in `AlertRuleRegistry`; orchestration service executes registry and notification pipeline.  
**Interface changes:** New `AlertRule` contract (`supports/evaluate`) and registry interfaces in module contracts.  
**Data model changes:** None.

#### Agent Provider Composition
**Current behavior:** `VunnixAgent` contains large prompt/tool/context assembly surface in one class.  
**Proposed behavior:** Provider classes generate prompt sections, tool packs, and model/context options; `VunnixAgent` composes providers.  
**Interface changes:** New provider contracts for prompt/tool/context/model option contributors.  
**Data model changes:** None.

#### Platform Port Expansion
**Current behavior:** `GitLabPort` exists, but other audit-defined boundaries are implicit/concrete-coupled.  
**Proposed behavior:** Add `AiProviderPort`, `PipelineExecutorPort`, `RealtimePort`, `NotificationPort` contracts with compatibility adapters.  
**Interface changes:** Service constructors depend on ports instead of concrete implementations where applicable.  
**Data model changes:** None.

#### Fast Lane Targeting + Contract Validation
**Current behavior:** Fast Lane uses changed-path detection, but test execution remains broad and does not explicitly run changed-contract validation.  
**Proposed behavior:** Changed-path matrix emits module/contract scopes; workflow executes targeted PHP/Vitest subsets plus `tests/Feature/Contracts/*` when relevant.  
**Interface changes:** CI matrix outputs include module and contract scope keys.  
**Data model changes:** None.

#### Architecture Metrics Snapshotting
**Current behavior:** X3 targets are documented but not persisted as operational metrics.  
**Proposed behavior:** Weekly metric snapshots capture PR/module touch breadth, Fast Lane duration, regression reopen rate proxies, and lead-time indicators.  
**Interface changes:** New command/service for metrics collection and reporting.  
**Data model changes:** New `architecture_iteration_metrics` table.

### Dependencies
- **Requires:** Ext-019 baseline artifacts (module skeleton, kernel registries, outbox model, architecture boundary checks, Fast/Full CI split) remain in place.
- **Unblocks:** Full closure of the 2026-02-19 architecture audit, measurable X3 progress tracking, and lower coupling in remaining hotspot classes.

### Data Migration

**Schema changes:**
| Table | Change | Reversible? |
|---|---|---|
| `architecture_iteration_metrics` | New weekly snapshot table for architecture/iteration KPIs | yes |

**Migration strategy:**
- [x] Additive migration first (new table and indexes)
- [x] Backfill existing data from git/CI history where available
- [x] Update application/ops scripts to write snapshot rows
- [x] Keep data additive (no destructive schema removal in this extension)

**Zero-downtime approach:**
- Metrics writes are asynchronous and non-blocking for request paths.
- Read paths default to empty snapshots when no data exists.

**Rollback procedure:**
- [x] Disable metrics collection via config/command scheduling toggle
- [x] Revert consumers to no-metrics mode (feature fallback)
- [x] Keep snapshot table for audit/history even if disabled
- [x] Estimated rollback time: < 1 hour

**Verification:**
- [ ] Migration up/down succeeds in local and CI test environments
- [ ] Backfill command/job writes valid snapshot rows
- [ ] No request-path performance regression from metrics collection

### Risk Mitigation

| Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|
| Frontend cutover introduces regressions | Chat/admin/dashboard failures in production UI | Med | Maintain compatibility facades during phased cutover; add parity tests before removing legacy internals |
| Alert/agent decomposition causes behavior drift | Missed alerts or altered assistant behavior | Med | Characterization tests first; keep orchestration facade and compare outputs under dual-path tests |
| New ports add abstraction overhead without adoption | Complexity increases with little benefit | Med | Require targeted usage in high-churn services first; defer broad adoption until validated |
| Fast Lane mis-scopes changed paths | False negatives in pre-merge checks | Med | Keep Full Lane gate mandatory; add deliberate CI scope fixtures and periodic map tuning |
| Metrics data quality is inconsistent | Misleading X3 progress reporting | Low | Version metric schema, validate collectors, and annotate missing-data intervals |

### Rollback Plan
- Keep decomposition changes behind compatibility facades (`AlertEventService`, `VunnixAgent`, legacy frontend store exports) until parity is verified.
- Revert Fast Lane targeting logic independently from Full Lane (Full Lane remains unchanged merge gate).
- Disable metrics collection and reporting paths without removing schema.
- Revert by phase-scoped commits (ports, backend decomposition, frontend cutover, CI targeting, metrics) to limit blast radius.
- No user data transformation rollback is required.

### Tasks

#### T325: Create architecture closure design artifact
**File(s):** `docs/architecture/2026-02-19-x3-iteration-architecture.md`, `docs/extensions/ext-020-x3-iteration-architecture-closure.md`  
**Action:** Create missing architecture design doc referenced by ext-019 and document closure scope/phase boundaries aligned with this extension.  
**Verification:** Referenced architecture doc exists and links from ext-019/ext-020 remain valid.

#### T326: Add platform port contracts and compatibility adapters
**File(s):** `app/Modules/Shared/Application/Contracts/AiProviderPort.php`, `app/Modules/Shared/Application/Contracts/PipelineExecutorPort.php`, `app/Modules/Shared/Application/Contracts/RealtimePort.php`, `app/Modules/Shared/Application/Contracts/NotificationPort.php`, `app/Providers/AppServiceProvider.php`  
**Action:** Introduce audit-defined ports and bind compatibility adapters that preserve existing behavior.  
**Verification:** Unit tests validate container bindings and adapter passthrough behavior.

#### T327: Decompose AlertEventService into AlertRule registry
**File(s):** `app/Modules/Observability/Application/Contracts/AlertRule.php`, `app/Modules/Observability/Application/Registries/AlertRuleRegistry.php`, `app/Modules/Observability/Application/Rules/*.php`, `app/Services/AlertEventService.php`  
**Action:** Move alert rule logic into rule plug-ins and keep `AlertEventService` as orchestration facade.  
**Verification:** Existing alert service tests pass; new registry selection tests cover deterministic rule ordering.

#### T328: Decompose VunnixAgent into provider-based composition
**File(s):** `app/Modules/Chat/Application/Contracts/*.php`, `app/Modules/Chat/Application/Providers/*.php`, `app/Agents/VunnixAgent.php`, `tests/Unit/Agents/VunnixAgentTest.php`  
**Action:** Split prompt/tool/context/model-option assembly into provider contracts and implementations composed by `VunnixAgent`.  
**Verification:** Existing VunnixAgent tests pass with new provider architecture and no response-shape regressions.

#### T329: Move chat store implementation into feature-owned code
**File(s):** `resources/js/features/chat/stores/conversations.ts`, `resources/js/features/chat/composables/*.ts`, `resources/js/stores/conversations.ts`, `resources/js/pages/ChatPage.vue`  
**Action:** Relocate chat store internals to feature slice; leave legacy store path as compatibility facade only.  
**Verification:** Chat page tests and store tests pass; `resources/js/features/chat/stores/conversations.ts` contains real implementation.

#### T330: Move admin/dashboard/activity store implementations into feature-owned code
**File(s):** `resources/js/features/admin/stores/admin.ts`, `resources/js/features/dashboard/stores/dashboard.ts`, `resources/js/features/activity/stores/activity.ts`, `resources/js/stores/admin.ts`, `resources/js/stores/dashboard.ts`, related page/tests files  
**Action:** Relocate remaining store internals to feature slices and keep legacy exports as transitional compatibility shims.  
**Verification:** Frontend test suite, typecheck, and lint pass; feature store files contain non-trivial implementation logic.

#### T331: Enforce frontend boundary rules for feature-slice ownership
**File(s):** `scripts/architecture/check-boundaries.sh`, `scripts/architecture/module-boundaries.yml`, `eslint.config.js`  
**Action:** Add checks preventing new direct page/component imports from legacy global stores when feature-owned alternatives exist.  
**Verification:** Deliberate import violation fails architecture checks with actionable output.

#### T332: Modularize API routes by module ownership
**File(s):** `routes/api.php`, `routes/api/chat.php`, `routes/api/admin.php`, `routes/api/dashboard.php`, `routes/api/activity.php`, `routes/api/external.php`  
**Action:** Extract domain route groups into module-owned route files and keep `routes/api.php` as composition entrypoint.  
**Verification:** `php artisan route:list` retains equivalent endpoint coverage and middleware behavior.

#### T333: Upgrade changed-path matrix to module/contract scopes
**File(s):** `scripts/ci/changed-path-matrix.sh`, `scripts/ci/changed-path-map.yml`, `.github/workflows/tests-fast.yml`  
**Action:** Emit changed modules/features/contracts from path mapping and expose outputs for Fast Lane targeting logic.  
**Verification:** Script outputs expected scopes for representative changed-file sets.

#### T334: Add contract-aware targeted Fast Lane execution
**File(s):** `.github/workflows/tests-fast.yml`, `composer.json`, `package.json`  
**Action:** Run module-scoped backend/frontend tests and `tests/Feature/Contracts/*` when contract surfaces change; keep Full Lane unchanged as merge gate.  
**Verification:** Fast Lane runs targeted scopes on scoped changes and still passes for docs-only changes.

#### T335: Add architecture iteration metrics persistence and collector
**File(s):** `database/migrations/2026_02_21_090000_create_architecture_iteration_metrics_table.php`, `app/Models/ArchitectureIterationMetric.php`, `app/Services/Architecture/IterationMetricsCollector.php`, `app/Console/Commands/CollectArchitectureIterationMetrics.php`  
**Action:** Implement snapshot schema and collector command for X3 KPI tracking (module touch breadth, median files changed, Fast Lane duration, regression proxy, lead-time proxy).  
**Verification:** Collector writes snapshot rows and command output validates captured metrics.

#### T336: Add parity and contract tests for closure components
**File(s):** `tests/Feature/Architecture/AlertRuleParityTest.php`, `tests/Feature/Architecture/AgentProviderParityTest.php`, `tests/Feature/Contracts/AiProviderPortContractTest.php`, `tests/Feature/Contracts/NotificationPortContractTest.php`, `tests/Feature/Contracts/RealtimePortContractTest.php`, `tests/Feature/Contracts/PipelineExecutorPortContractTest.php`  
**Action:** Add characterization and contract tests for alert decomposition, agent composition, and new ports.  
**Verification:** New parity/contract tests pass with existing suites.

#### T337: Update decisions index and architecture/operational docs
**File(s):** `docs/spec/decisions-index.md`, `CLAUDE.md`, `docs/local-dev-setup.md`, `docs/project-setup.md`  
**Action:** Add D224-D229 and document closure workflows, Fast Lane targeting rules, and metrics collection operations.  
**Verification:** Docs are internally consistent and reflect implemented behavior.

### Verification
- [ ] Frontend feature slices hold real store/composable logic for chat/admin/dashboard/activity (not scaffold-only re-exports)
- [ ] Alert and agent decomposition reduce monolith hotspot responsibilities without behavior regressions
- [ ] Audit-defined external ports are implemented with compatibility adapters and contract tests
- [ ] Fast Lane performs module-targeted + changed-contract validation while Full Lane remains protected-branch gate
- [ ] API route ownership is segmented by module with endpoint parity preserved
- [ ] X3 success metrics are collected and reportable weekly
- [ ] All existing tests still pass (no regressions)
- [ ] New tests cover closure decomposition and contract boundaries

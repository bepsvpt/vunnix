## Extension 019: X3 Iteration Development Architecture

### Trigger
The platform reached high feature throughput quickly, but core delivery paths now have high coupling and large regression blast radius. A staff-level redesign is needed to sustain ~3x iteration velocity without reducing quality.

### Scope
What it does:
- Reorganizes backend code into capability-oriented modules (`app/Modules/*`) with explicit contracts and boundary rules
- Introduces a workflow kernel (intent classifier registry, task handler registry, result publisher registry) to remove hardcoded orchestration branching
- Splits large integration/services (especially GitLab integration and alert evaluation) into adapter/rule sets behind interfaces
- Adds internal event envelope + outbox delivery for durable side-effect fan-out
- Migrates frontend to feature slices (`resources/js/features/*`) and decomposes large cross-domain stores
- Adds delivery architecture upgrades: Fast Lane CI, architecture fitness checks, and `dev-fast` runtime profile

What it does NOT do:
- Does not move to microservices or multi-repo architecture
- Does not change product-facing workflows or remove existing capabilities
- Does not alter GitLab as source of truth
- Does not require immediate rewrite of all modules before shipping incremental improvements

### Architecture Fit
- **Components affected:** Webhook intake, routing/dispatch pipeline, task result fan-out, GitLab adapter layer, frontend store/composable structure, CI workflows, internal event delivery
- **Extension points used:** Existing queue topology (D134), existing task lifecycle model, existing AI SDK and GitLab HTTP patterns, existing dashboard/chat surfaces
- **New tables/endpoints/services:** New table `internal_outbox_events`; new registries (`IntentClassifierRegistry`, `TaskHandlerRegistry`, `ResultPublisherRegistry`); new outbox delivery services/jobs

### New Decisions
- **D218:** Vunnix remains a single deployable modular monolith with capability modules and explicit contracts — maximizes iteration speed without microservice operational overhead.
- **D219:** Core orchestration moves to registry-driven workflow kernel (classifier/handler/publisher plug-ins) — new intents ship as isolated additions instead of core flow edits.
- **D220:** Cross-module async side effects use versioned internal event envelopes delivered via outbox — improves reliability, idempotency, and migration safety.
- **D221:** Frontend architecture shifts from global cross-domain stores to feature-sliced domains with local API/store/composable boundaries — reduces coupling and test churn.
- **D222:** CI uses two mandatory lanes (Fast Lane + Full Lane) with changed-path selection and architecture fitness checks — improves feedback speed while preserving full-regression safety.
- **D223:** Local development has explicit runtime profiles (`dev-fast`, `dev-parity`) — optimizes inner-loop iteration without removing parity verification.

### Affected Existing Decisions

| Decision | Current State | Proposed Change | Rationale |
|---|---|---|---|
| D23 | Three-layer intelligence architecture | Keep intelligence layers, but enforce module contracts for cross-layer integration | Preserve behavior while reducing structural coupling |
| D47 | Separate pages: Chat, Dashboard, Admin | Keep page model, but implement feature-slice ownership per domain | Reduce cross-page/shared-store entanglement |
| D63 | Single broad GitLab client via Laravel HTTP | Keep Laravel HTTP approach, split into capability adapters behind `GitLabPort` | Improve cohesion and testability |
| D66 | Pinia state management | Keep Pinia, move to domain-local stores + orchestration composables | Lower frontend blast radius |
| D123 | Runner/server execution split | Keep execution semantics, route through registry-based workflow kernel | Reduce central branching complexity |
| D134 | Queue isolation by workload | Keep queue topology, add outbox delivery on `vunnix-server` | Reliable async side-effects with current infra |

### Component Design

#### Workflow Kernel
**Current behavior:** Core orchestration is distributed across controller/service/job branches and conditionals.  
**Proposed behavior:** Kernel coordinates three registries: intent classification, task handling, and result publishing.  
**Interface changes:** New contracts in `app/Modules/TaskOrchestration/Application/Contracts/*`.  
**Data model changes:** None.

#### Intent Classifier Registry
**Current behavior:** Intent classification logic is largely centralized in router matches.  
**Proposed behavior:** Intent classifiers are independent handlers registered by module/capability.  
**Interface changes:** `IntentClassifier::supports(WebhookEventEnvelope): bool` and `classify(...): RoutingDecision`.  
**Data model changes:** None.

#### Task Handler Registry
**Current behavior:** Task type routing uses hardcoded maps and centralized dispatch branching.  
**Proposed behavior:** Handlers register per task type/intent with clear ownership and priority.  
**Interface changes:** `TaskHandler::supports(TaskEnvelope): bool` and `handle(...): TaskExecutionResult`.  
**Data model changes:** None.

#### Result Publisher Registry
**Current behavior:** Result fan-out is encoded via conditional dispatch in `ProcessTaskResult`.  
**Proposed behavior:** Publisher plug-ins handle task-type-specific posting/side effects.  
**Interface changes:** `ResultPublisher::supports(Task): bool` and `publish(Task, ProcessedResult): void`.  
**Data model changes:** None.

#### GitLab Integration Adapters
**Current behavior:** `GitLabClient` centralizes repo/issues/mr/pipeline/note behavior.  
**Proposed behavior:** Split into adapter set (`RepoAdapter`, `IssueAdapter`, `MergeRequestAdapter`, `PipelineAdapter`, `NoteAdapter`) behind `GitLabPort`.  
**Interface changes:** Port interfaces in module contracts; old client retained as compatibility adapter during migration.  
**Data model changes:** None.

#### Internal Event Envelope + Outbox
**Current behavior:** Side effects are dispatched directly after state changes.  
**Proposed behavior:** State changes emit versioned internal events persisted to outbox; delivery worker publishes to downstream jobs/reverb/webhooks idempotently.  
**Interface changes:** New `InternalEvent` DTO and outbox dispatcher service.  
**Data model changes:** New `internal_outbox_events` table.

#### Frontend Feature Slices
**Current behavior:** Global stores (`conversations`, `admin`) own many unrelated concerns.  
**Proposed behavior:** `resources/js/features/{chat,dashboard,admin,activity,shared}` each own API clients, stores/composables, types, tests.  
**Interface changes:** Existing components consume feature exports rather than shared global store surface.  
**Data model changes:** None.

#### Delivery Architecture
**Current behavior:** Primarily full-suite CI checks, slower feedback for localized changes.  
**Proposed behavior:** Fast Lane (changed-path scoped) + Full Lane (full regression) with boundary fitness checks.  
**Interface changes:** New CI workflows and boundary check scripts.  
**Data model changes:** None.

### Dependencies
- **Requires:** Existing behavior-characterization tests for routing/dispatch/result paths before migration starts
- **Unblocks:** Parallel domain delivery, smaller change sets, faster CI feedback, safer long-term iteration velocity

### Data Migration

**Schema changes:**
| Table | Change | Reversible? |
|---|---|---|
| `internal_outbox_events` | New table for durable internal event delivery | yes |

**Migration strategy:**
- [x] Additive migration first (new outbox table and indexes)
- [x] Backfill existing data (not required; outbox starts forward-only)
- [x] Update application code to dual-path during transition
- [x] Remove old direct side-effect paths after parity verification

**Zero-downtime approach:**
- Dual-write period: existing direct side effects continue while outbox delivery runs in shadow mode
- Cutover after parity validation metrics pass

**Rollback procedure:**
- [x] Disable outbox dispatch via feature flag/config
- [x] Re-enable direct side-effect dispatch path
- [x] Keep outbox table intact for audit/replay
- [x] Estimated rollback time: < 1 hour

**Verification:**
- [ ] Outbox migration applies and rolls back cleanly
- [ ] Dual-path mode emits matching side effects in staging
- [ ] Cutover mode shows no delivery regressions

### Risk Mitigation

| Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|
| Migration stalls due to broad scope | Long-lived partial architecture | Med | Phase gates with compatibility adapters and strict exit criteria per phase |
| Outbox introduces duplicate side effects | User-visible duplicated comments/updates | Med | Idempotency keys + publisher dedup checks + shadow-mode validation |
| Fast Lane misses regressions | False confidence in localized checks | Med | Require Full Lane before protected-branch merge; tune changed-path map with incident feedback |
| Boundary rules are bypassed | Coupling returns quickly | High | Automated architecture fitness checks in CI as merge blocker |

### Rollback Plan
- Keep old orchestration paths behind compatibility flags until parity is proven.
- Roll back by toggling kernel/outbox paths off and using existing dispatch/result flows.
- Revert module-slice changes by phase-specific commits (one phase per merge set).
- Outbox table is additive and safe to keep even if disabled.
- No user data transformation is required for rollback.

### Tasks

#### T309: Create module boundary map and skeleton
**File(s):** `app/Modules/README.md`, `app/Modules/*/.gitkeep`, `docs/architecture/2026-02-19-x3-iteration-architecture.md`  
**Action:** Define canonical module boundaries and create initial module directories for Chat, WebhookIntake, TaskOrchestration, GitLabIntegration, ReviewExecution, FeatureExecution, Observability, AdminGovernance, Shared.  
**Verification:** Boundary map exists; directories present; team can place new code only under module ownership.

#### T310: Introduce orchestration kernel contracts and registries
**File(s):** `app/Modules/TaskOrchestration/Application/Contracts/*.php`, `app/Modules/TaskOrchestration/Application/Registries/*.php`, `app/Providers/AppServiceProvider.php`  
**Action:** Add `IntentClassifier`, `TaskHandler`, `ResultPublisher` contracts and registries with container registration.  
**Verification:** Unit tests prove handler registration/selection works deterministically.

#### T311: Migrate webhook intent classification to registry handlers
**File(s):** `app/Services/EventRouter.php`, `app/Modules/WebhookIntake/Application/Classifiers/*.php`, `tests/Feature/Services/EventDeduplicatorTest.php`, `tests/Feature/WebhookControllerTest.php`  
**Action:** Move intent-specific matching into classifier handlers while preserving existing behavior and dedup semantics.  
**Verification:** Existing webhook/e2e tests pass unchanged; characterization tests match prior routing outcomes.

#### T312: Migrate task dispatch mapping to task handlers
**File(s):** `app/Services/TaskDispatchService.php`, `app/Modules/TaskOrchestration/Application/Handlers/*.php`, `tests/Feature/Services/TaskDispatcherTest.php`  
**Action:** Replace static intent-to-type branching with handler selection and command envelope processing.  
**Verification:** Dispatch behavior parity for all current intents (`auto_review`, `on_demand_review`, `incremental_review`, `improve`, `ask_command`, `issue_discussion`, `feature_dev`).

#### T313: Migrate result fan-out to result publisher plug-ins
**File(s):** `app/Jobs/ProcessTaskResult.php`, `app/Modules/TaskOrchestration/Application/Publishers/*.php`, `tests/Feature/Jobs/ProcessTaskResultDispatchTest.php`  
**Action:** Move post-processing fan-out rules (summary/threads/labels/issue/MR/comment paths) into publishers registered by task type/intent.  
**Verification:** Existing downstream dispatch tests and end-to-end task result behavior remain equivalent.

#### T314: Split GitLab integration into capability adapters behind port
**File(s):** `app/Services/GitLabClient.php`, `app/Modules/GitLabIntegration/Application/Contracts/GitLabPort.php`, `app/Modules/GitLabIntegration/Infrastructure/Adapters/*.php`, `tests/Feature/Services/GitLabClientTest.php`  
**Action:** Introduce `GitLabPort`; extract capability adapters; keep `GitLabClient` as compatibility facade during migration.  
**Verification:** API behavior parity tests pass for repos/issues/mrs/pipelines/notes endpoints.

#### T315: Introduce internal event envelope and outbox schema
**File(s):** `database/migrations/2026_02_20_120000_create_internal_outbox_events_table.php`, `app/Modules/Shared/Domain/InternalEvent.php`, `app/Models/InternalOutboxEvent.php`  
**Action:** Add versioned internal event envelope and outbox table with idempotency key/indexes and status fields.  
**Verification:** Migration up/down succeeds; model tests validate envelope serialization and state transitions.

#### T316: Implement outbox dispatcher and delivery worker
**File(s):** `app/Modules/TaskOrchestration/Infrastructure/Outbox/OutboxWriter.php`, `app/Jobs/DeliverOutboxEvents.php`, `app/Console/Commands/OutboxReplayCommand.php`  
**Action:** Write events transactionally, deliver asynchronously on `vunnix-server`, support replay for failed deliveries.  
**Verification:** Integration tests show exactly-once effect at publisher layer via idempotency keys.

#### T317: Add compatibility dual-path and cutover flags
**File(s):** `config/vunnix.php`, `app/Providers/AppServiceProvider.php`, `app/Services/*` migration touchpoints  
**Action:** Add feature flags for kernel/outbox modes (`orchestration.kernel_enabled`, `events.outbox_enabled`, `events.outbox_shadow_mode`) and dual-path behavior during migration.  
**Verification:** Shadow mode emits both paths with parity logs; cutover mode disables old path cleanly.

#### T318: Feature-slice frontend scaffold and chat migration
**File(s):** `resources/js/features/chat/*`, `resources/js/stores/conversations.ts`, `resources/js/pages/ChatPage.vue`, `resources/js/router/index.ts`  
**Action:** Move chat API/store/composables/types/tests into `features/chat` and keep backward-compatible exports during transition.  
**Verification:** Chat test suite passes; no route/runtime regressions.

#### T319: Feature-slice frontend migration for admin/dashboard/activity
**File(s):** `resources/js/features/admin/*`, `resources/js/features/dashboard/*`, `resources/js/features/activity/*`, `resources/js/stores/admin.ts`, related components/pages/tests  
**Action:** Migrate remaining large stores and consumers into feature slices with local ownership.  
**Verification:** Frontend tests, typecheck, and lint pass with reduced cross-feature imports.

#### T320: Add Fast Lane CI workflow and changed-path mapping
**File(s):** `.github/workflows/tests-fast.yml`, `.github/workflows/tests.yml`, `scripts/ci/changed-path-matrix.sh`  
**Action:** Add path-scoped fast checks (targeted PHP tests, targeted Vitest, static checks, contract tests), keep full lane unchanged for protected merges.  
**Verification:** Fast lane executes under target budget for localized changes; full lane still passes.

#### T321: Add architecture fitness checks
**File(s):** `phpstan.neon.dist`, `eslint.config.js`, `scripts/architecture/check-boundaries.sh`, `scripts/architecture/module-boundaries.yml`  
**Action:** Enforce forbidden cross-module imports and invalid controller-to-infrastructure coupling in CI.  
**Verification:** Deliberate boundary violation fails CI with actionable error output.

#### T322: Add runtime profiles (`dev-fast`, `dev-parity`)
**File(s):** `composer.json`, `docs/local-dev-setup.md`, `docs/project-setup.md`  
**Action:** Add documented commands/profiles for fast iteration and parity verification workflows.  
**Verification:** Both profiles run successfully; docs explain when each profile is required.

#### T323: Add characterization and contract test suites for migration safety
**File(s):** `tests/Feature/Architecture/OrchestrationParityTest.php`, `tests/Feature/Architecture/OutboxParityTest.php`, `tests/Feature/Contracts/*.php`  
**Action:** Lock current behavior as characterization tests, add contract tests for internal event/versioned DTO boundaries.  
**Verification:** Baseline parity tests pass before and after each phase.

#### T324: Update decision index and architecture docs
**File(s):** `docs/spec/decisions-index.md`, `docs/architecture/2026-02-19-x3-iteration-architecture.md`, `CLAUDE.md`  
**Action:** Add D218-D223 entries and document module boundary rules, migration rules, and runtime profile usage.  
**Verification:** Documentation is current and consistent with implemented architecture.

### Verification
- [ ] Registry-driven orchestration replaces hardcoded branching without behavioral regression
- [ ] GitLab integration split reduces service coupling while preserving API behavior
- [ ] Outbox delivery is idempotent and reliable under retry/failure conditions
- [ ] Frontend feature slices reduce cross-domain coupling (`conversations`/`admin` store decomposition complete)
- [ ] Fast Lane provides materially faster feedback; Full Lane remains merge gate
- [ ] Architecture fitness checks prevent boundary drift
- [ ] All existing tests still pass (no regressions)
- [ ] New tests cover migrated kernel/outbox/module boundary behavior

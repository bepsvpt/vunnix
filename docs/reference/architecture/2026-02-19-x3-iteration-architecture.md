# X3 Iteration Architecture (Implementation Baseline)

Date: 2026-02-19  
Status: Active migration baseline (ext-019 + ext-020)

## Purpose

This document is the implementation-facing architecture baseline for the X3 iteration initiative. It bridges:

- `docs/operations/audits/architecture/2026-02-19.md` (staff audit proposal)
- `docs/work/extensions/ext-019-x3-iteration-development-architecture/plan.md` (initial migration plan)
- `docs/work/extensions/ext-020-x3-iteration-architecture-closure/plan.md` (closure plan for remaining gaps)

## Architectural Direction

Vunnix remains a single deployable modular monolith with:

- capability-oriented backend modules under `app/Modules/*`
- registry-driven orchestration for intent classification, task handling, and result publishing
- outbox-backed internal event delivery for resilient side effects
- frontend feature slices under `resources/js/features/*`
- Fast/Full CI lanes with architecture fitness checks

## Phase Boundaries

### Ext-019 Completed Foundation

- Module skeleton and ownership map introduced (`app/Modules/README.md`)
- Workflow kernel contracts/registries introduced
- Webhook classification and task/result routing migrated to registry model
- GitLab adapter split under `GitLabPort`
- Outbox event envelope + delivery worker + replay command added
- Fast/Full lane structure and architecture boundary checks added
- Runtime profiles (`dev:fast`, `dev:parity`) added

### Ext-020 Closure Scope

- Replace scaffold-only frontend feature store exports with feature-owned implementations
- Decompose `AlertEventService` into rule registry and plug-ins
- Decompose `VunnixAgent` via provider composition model
- Add explicit ports for AI provider, pipeline execution, realtime, and notifications
- Make Fast Lane module-targeted and changed-contract-aware
- Add persistent X3 architecture iteration metrics

## Compatibility and Migration Rules

- Preserve existing product behavior during decomposition via compatibility facades/adapters.
- Prefer additive migration before destructive cleanup.
- Keep Full Lane as the protected-branch merge gate while tuning Fast Lane scope.
- Enforce boundaries automatically in CI to prevent architecture drift.

## Success Signals

Target outcomes for closure:

- Feature slices own real frontend domain logic (not pass-through re-exports).
- Core hotspots (`AlertEventService`, `VunnixAgent`) are decomposed with parity tests.
- External boundaries are interface-first with contract tests.
- Fast Lane is materially faster while retaining contract safety.
- Weekly architecture metrics are available for trend tracking.

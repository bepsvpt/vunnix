# Assessment: X3 Iteration Development Architecture

**Date:** 2026-02-19
**Requested by:** Project owner (architecture review request)
**Trigger:** Rapid feature growth created coupling bottlenecks; request to design architecture that sustains 3x iteration speed

## What

Restructure Vunnix from a horizontally layered monolith into a capability-oriented modular monolith with explicit module contracts, a workflow kernel (intent/task/result registries), and faster delivery feedback loops (fast/full CI lanes, boundary fitness checks, dev-fast runtime profile). The goal is not scale-out infrastructure; it is reducing coordination cost and regression blast radius so teams can ship significantly faster without lowering quality.

## Classification

**Tier:** 3 (Architectural)  
**Rationale:** This changes system shape across backend boundaries, frontend composition, orchestration flow, and CI architecture. It affects core paths (`WebhookController`, router/dispatch pipeline, task result fan-out), introduces new internal contracts and migration seams, and requires phased rollout across many components.

**Modifiers:**
- [ ] `breaking` — Changes public API, DB schema, or external contracts
- [ ] `multi-repo` — Affects more than one repository
- [ ] `spike-required` — Feasibility uncertain, needs research first
- [ ] `deprecation` — Removes or sunsets existing capability
- [x] `migration` — Requires data migration or rollout coordination

## Impact Analysis

### Components Affected

| Component | Impact | Files (est.) |
|---|---|---|
| Webhook + routing orchestration | Move from hardcoded switch paths to registries and handlers | 12-18 |
| Task dispatch + result fan-out | Introduce workflow kernel contracts and adapter-based migration | 10-16 |
| GitLab integration | Split monolithic client into capability adapters behind port interfaces | 12-20 |
| Alerting/automation services | Split large service patterns into rule registries | 6-10 |
| Frontend state/domain organization | Move from global stores to feature-sliced domains/composables | 20-35 |
| API route and controller organization | Group by module API surfaces with explicit boundaries | 10-18 |
| CI/workflow architecture | Add fast/full lanes and architecture fitness checks | 4-8 |
| Database/events | Add outbox table and internal event envelope model | 3-6 |
| Total | Architectural migration, phased to preserve behavior | ~77-131 |

### Relevant Decisions

| Decision | Summary | Relationship |
|---|---|---|
| D23 | Three-layer intelligence architecture | Constrains modular boundaries and integration style |
| D63 | Raw Laravel HTTP Client for GitLab integration | Enables adapter refactor without third-party wrapper lock-in |
| D69 | FrankenPHP application server | Constrains runtime profile choices and reload strategy |
| D73 | Laravel AI SDK for CE | Constrains agent modularization to SDK-compatible seams |
| D123 | Runner/server split execution modes | Must be preserved by workflow kernel abstraction |
| D134 | Queue isolation (`vunnix-server`, `vunnix-runner-*`) | Constrains async module boundaries and outbox delivery |
| D135 | Desktop-first SPA | Constrains frontend slice migration to existing responsive patterns |
| D140 | Latest-wins superseding for review flow | Must remain invariant during orchestration refactor |
| D148 | PostgreSQL FTS for conversation search | Constrains module split around existing search behavior |

### Dependencies

- **Requires first:** Existing task lifecycle, queue topology, and event-driven review behavior remain as compatibility baseline during migration
- **Unblocks:** Faster feature delivery, lower coupling, parallel team execution by domain, and reduced regression risk under higher iteration throughput

## Risk Factors

- Architectural migration can stall if not seam-based and phase-gated.
- Temporary dual-path operation can increase short-term complexity.
- Boundary enforcement can be bypassed unless automated in CI.
- Fast lane can miss regressions if changed-path mapping is too coarse.

## Recommendation

Proceed to planning-extensions.

# Vunnix Development Progress

## Summary

- **Current Milestone:** M5 — Admin & Configuration
- **Tasks Complete:** 99 / 116 (85.3%)
- **Current Task:** T99 — Team chat notifications — event routing
- **Last Verified:** T98

---

## M1 — Core Infrastructure (11/11) ✅

- [x] T1: Scaffold Laravel project (Octane, FrankenPHP, AI SDK, Socialite, Reverb) [Depends: —]
- [x] T2: Docker Compose (FrankenPHP + PostgreSQL + Redis + Reverb) [Depends: T1]
- [x] T3: Migrations — auth & RBAC tables [Depends: T2]
- [x] T4: Migrations — task & conversation tables (full-text search D148) [Depends: T2]
- [x] T5: Migrations — operational tables [Depends: T2]
- [x] T6: Health check endpoint [Depends: T2]
- [x] T7: GitLab OAuth (Socialite, read_user + read_api scopes D151) [Depends: T3]
- [x] T8: User model + membership sync (periodic re-validation D147) [Depends: T7]
- [x] T9: RBAC system (roles, permissions, Gate/Policy) [Depends: T3, T8]
- [x] T10: Global configuration model [Depends: T5]
- [x] T11: GitLab HTTP client service [Depends: T1]

## M2 — Path A Functional (35/35) ✅

### Webhook & Event Routing

- [x] T12: Webhook controller + middleware (CSRF exclusion, X-Gitlab-Token) [Depends: T1, T11]
- [x] T13: Event types + event router (bot filtering D154, command fallback D155) [Depends: T12]
- [x] T14: Deduplication + latest-wins superseding (D140) [Depends: T13]

### Task Queue & Dispatch

- [x] T15: Task model + lifecycle (state machine) [Depends: T4]
- [x] T16: Task queue — Redis with priority + queue isolation (D134) [Depends: T15]
- [x] T17: Task Dispatcher — strategy selection + execution mode routing [Depends: T16, T11]
- [x] T18: Pipeline trigger integration (task-scoped token D127) [Depends: T17]

### Executor Image

- [x] T19: Executor Dockerfile + entrypoint (Playwright, screenshots D131) [Depends: —]
- [x] T20: Executor CLAUDE.md (output format, severity, safety) [Depends: —]
- [x] T21: Frontend-review skill [Depends: T20]
- [x] T22: Backend-review skill [Depends: T20]
- [x] T23: Mixed-review skill [Depends: T21, T22]
- [x] T24: Security-audit skill [Depends: T20]
- [x] T25: UI-adjustment skill (screenshots D131) [Depends: T20, T19]
- [x] T26: Issue-discussion skill [Depends: T20]
- [x] T27: Feature-dev skill [Depends: T20]
- [x] T28: Result posting scripts [Depends: T19]

### Runner ↔ Vunnix Interface

- [x] T29: Runner result API endpoint [Depends: T15, T18]
- [x] T30: Structured output schema — code review [Depends: —]
- [x] T31: Structured output schema — feature dev + UI adjustment [Depends: —]

### Result Processor

- [x] T32: Result Processor service [Depends: T11, T29, T30, T31]
- [x] T33: Summary comment — Layer 1 [Depends: T32]
- [x] T34: Inline discussion threads — Layer 2 [Depends: T32]
- [x] T35: Labels + commit status — Layer 3 [Depends: T32]
- [x] T36: Placeholder-then-update pattern [Depends: T33]

### Retry & Failure

- [x] T37: Retry + backoff (30s → 2m → 8m, max 3) [Depends: T16]
- [x] T38: Failure handling (DLQ, failure comment) [Depends: T37, T32]

### End-to-End & Variations

- [x] T39: Code review — end-to-end [Depends: T14, T18, T28, T34, T35, T36, T38]
- [x] T40: Incremental review [Depends: T39]
- [x] T41: On-demand review — @ai review [Depends: T39]
- [x] T42: @ai improve + @ai ask commands [Depends: T39]
- [x] T43: Issue discussion — @ai on Issue [Depends: T39, T26]
- [x] T44: Feature development — ai::develop label [Depends: T39, T27]
- [x] T45: CI pipeline template [Depends: T19]
- [x] T46: Executor image CI/CD + version alignment [Depends: T19]

## M3 — Path B Functional (27/27) ✅

### Chat Backend

- [x] T47: Chat API endpoints (search D148, archiving D141, title D142) [Depends: T4, T9]
- [x] T48: SSE streaming endpoint [Depends: T47]
- [x] T49: Conversation Engine — AI SDK Agent class [Depends: T47, T48, T10]
- [x] T50: GitLab tools — repo browsing (BrowseRepoTree, ReadFile, SearchCode) [Depends: T49, T11]
- [x] T51: GitLab tools — Issues (ListIssues, ReadIssue) [Depends: T49, T11]
- [x] T52: GitLab tools — Merge Requests (ListMRs, ReadMR, ReadMRDiff) [Depends: T49, T11]
- [x] T53: Cross-project tool-use access check [Depends: T50, T51, T52, T9]
- [x] T54: Quality gate behavior [Depends: T49]
- [x] T55: Action dispatch from conversation (deep analysis D132) [Depends: T49, T15, T16, T9]
- [x] T56: Server-side execution mode (create Issue bypass) [Depends: T55, T32, T11]
- [x] T57: Structured output schema — action dispatch [Depends: T49]
- [x] T58: Conversation pruning middleware (>20 turns) [Depends: T49]
- [x] T59: Language configuration injection [Depends: T49, T10]
- [x] T60: Prompt injection hardening [Depends: T49]

### Chat Frontend

- [x] T61: Vue SPA scaffold (responsive D135, history mode) [Depends: T1]
- [x] T62: Auth state management (Pinia) [Depends: T61, T7]
- [x] T63: Chat page — conversation list [Depends: T62, T47]
- [x] T64: Chat page — new conversation flow (cross-project warning D128) [Depends: T63]
- [x] T65: Chat page — message thread + markdown rendering (Shiki D138) [Depends: T63]
- [x] T66: Chat page — SSE streaming (connection resilience) [Depends: T65, T48]
- [x] T67: Chat page — tool-use activity indicators [Depends: T66]
- [x] T68: Chat page — preview cards (action-type-specific) [Depends: T65, T57]
- [x] T69: Chat page — pinned task bar (runner load awareness D133) [Depends: T68, T73]
- [x] T70: Chat page — result cards (screenshots D131) [Depends: T69, T73]
- [x] T71: PRD output template [Depends: T49, T54]
- [x] T72: Designer iteration flow [Depends: T55, T44]

### Conversation Management

- [x] T115: Conversation archiving (D141) [Depends: T47]

## M4 — Dashboard & Metrics (15/15) ✅

- [x] T73: Reverb channel configuration (channel authorization) [Depends: T2, T15]
- [x] T74: Laravel Echo client [Depends: T61, T73]
- [x] T75: Dashboard — activity feed (filter tabs, cursor pagination) [Depends: T62, T74, T15]
- [x] T76: Dashboard — Overview [Depends: T75]
- [x] T77: Dashboard — Quality [Depends: T75]
- [x] T78: Dashboard — PM Activity [Depends: T75]
- [x] T79: Dashboard — Designer Activity [Depends: T75]
- [x] T80: Dashboard — Efficiency [Depends: T75]
- [x] T81: Dashboard — Cost (admin-only) [Depends: T75, T9]
- [x] T82: Dashboard — Adoption [Depends: T75]
- [x] T83: Metrics collection — model observers [Depends: T15]
- [x] T84: Metrics aggregation (every 15 min, materialized views) [Depends: T83, T5]
- [x] T85: Cost tracking (token × price formula) [Depends: T83, T10]
- [x] T86: Acceptance tracking (webhook-driven D149) [Depends: T34, T11, T13]
- [x] T87: Engineer feedback — emoji reactions on MR merge [Depends: T34, T11, T86]

## M5 — Admin & Configuration (11/18)

- [x] T88: Admin page — project management (registry D150, label pre-creation) [Depends: T62, T11, T9]
- [x] T89: Admin page — role management [Depends: T62, T9]
- [x] T90: Admin page — global settings (API key status D153) [Depends: T62, T10, T9]
- [x] T91: Per-project configuration (DB overrides) [Depends: T5, T88]
- [x] T92: Optional .vunnix.toml support [Depends: T91, T11]
- [x] T93: PRD template management [Depends: T91, T9]
- [x] T94: Cost monitoring — 4 alert rules [Depends: T85, T90]
- [x] T95: Over-reliance detection (>95% acceptance 2+ weeks) [Depends: T86]
- [x] T96: Dead letter queue — backend [Depends: T37, T5]
- [x] T97: Dead letter queue — admin UI [Depends: T96, T62]
- [x] T98: Team chat notifications — webhook integration [Depends: T90]
- [ ] **T99:** Team chat notifications — event routing [Depends: T98, T94, T15]
- [ ] T100: API versioning + external access (SHA-256 hash D152) [Depends: T9, T5]
- [ ] T101: Documented external endpoints [Depends: T100]
- [ ] T102: Prompt versioning [Depends: T15, T20, T49]
- [ ] T103: Audit logging (full-content, admin API) [Depends: T5, T9]
- [ ] T104: Infrastructure monitoring alerts [Depends: T6, T98]
- [ ] T116: Bot PAT rotation reminder (D144) [Depends: T90, T98]

## M6 — Pilot Launch (0/7)

- [ ] T105: Production Docker Compose (backup, log rotation) [Depends: T2]
- [ ] T106: Deploy to cloud VM [Depends: T105]
- [ ] T107: GitLab bot account setup [Depends: T106]
- [ ] T108: Enable pilot project [Depends: T106, T107, T88, T45]
- [ ] T109: Verify end-to-end Path A [Depends: T108, T39]
- [ ] T110: Verify end-to-end Path B [Depends: T108, T72, T70]
- [ ] T111: Pilot monitoring period (2–4 weeks) [Depends: T109, T110]

## M7 — Team-wide Rollout (0/3)

- [ ] T112: Onboarding materials (per-role docs) [Depends: T111]
- [ ] T113: Batch rollout (3–5 projects per wave) [Depends: T112]
- [ ] T114: Steady state operations [Depends: T113]

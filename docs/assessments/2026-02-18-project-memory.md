# Assessment: Project Memory

**Date:** 2026-02-18
**Requested by:** Project owner
**Trigger:** Each conversation and code review starts from scratch — feedback data (FindingAcceptance, emoji sentiment, code change correlation) is collected but never fed back into AI behavior. Conversations are amnesiac across sessions. The spec's flywheel (§16.6) requires manual CLAUDE.md updates.

## What

Project Memory is a persistent, per-project knowledge layer that connects chat conversations, code review feedback, and issue discussions into a shared, evolving understanding. It closes the feedback loop described in §16.6 by automatically extracting patterns from FindingAcceptance data, conversation summaries, and cross-MR findings — then injecting them as learned context into VunnixAgent (chat) and Task Executor (code review) prompts. Three sub-capabilities: (1) Review Learning, (2) Conversation Continuity, (3) Cross-MR Pattern Detection.

## Classification

**Tier:** 3 (Architectural)
**Rationale:** Introduces a new cross-cutting knowledge layer spanning 8 architectural components (~55-60 files). Extends the three-layer intelligence model (D23) with a dynamic "Layer 2.5" of learned rules. Automates the manual prompt improvement loop (D112, §16.4). Requires 2 new models, 3 new services, 3 new jobs, 1 new controller, 3 new Vue components, 2 new migrations, and 55+ tests.

**Modifiers:**
- [ ] `breaking` — No existing APIs change; additive only
- [ ] `multi-repo` — Single repository
- [ ] `spike-required` — Infrastructure already proves feasibility (FindingAcceptance schema, VunnixAgent section pattern, executor env vars)
- [ ] `deprecation` — No removals
- [x] `migration` — New `project_memories` and `memory_entries` tables

## Impact Analysis

### Components Affected

| Component | Impact | Files (est.) |
|---|---|---|
| **Models** | 2 new (ProjectMemory, MemoryEntry) + 4 modified (Project, Task, FindingAcceptance, Conversation) | 6 |
| **Migrations** | 2 new tables with JSONB columns + indices | 2 |
| **Services** | 3 new (ProjectMemoryService, MemoryExtractionService, MemoryInjectionService) + 3 modified (ConversationService, AcceptanceTrackingService, ProjectConfigService) | 6 |
| **Jobs** | 3 new (ExtractMemoryFromFinding, ExtractMemoryFromConversation, IndexMemoryPattern) + 1 modified (ProcessTaskResult) | 4 |
| **Agents** | VunnixAgent: new `memorySection()` in `buildSystemPrompt()` | 1 |
| **Middleware** | PruneConversationHistory: persist summaries + dispatch extraction | 1 |
| **Controllers** | 1 new (ProjectMemoryController) + 1 modified (ConversationController) | 2 |
| **HTTP Resources** | 2 new (MemoryEntryResource, ProjectMemoryStatsResource) + 1 modified (ConversationDetailResource) | 3 |
| **Config** | `config/vunnix.php` memory section | 1 |
| **Executor** | `executor/entrypoint.sh` — read `VUNNIX_LEARNING_CONTEXT` in `build_prompt()` | 1 |
| **TaskDispatcher** | Build learning context JSON, pass as pipeline env var | 1 |
| **Frontend (Vue)** | 3 new (ProjectMemoryPanel, MemoryStatsWidget, MemoryDebugger) + 3 modified (ChatPage, AdminPage, DashboardPage) | 6 |
| **Frontend (Types)** | 3 new Zod schemas in `api.ts` | 1 |
| **Tests (Unit)** | ~30 new test files | 30 |
| **Tests (Feature)** | ~20 new test files | 20 |
| **TOTAL** | | ~55-60 production + 50 test |

### Relevant Decisions

| Decision | Summary | Relationship |
|---|---|---|
| **D9** | Conversation persistence — threads survive sessions | Enables: conversation table infrastructure ready |
| **D23** | Three-layer intelligence model (Vunnix → Executor → Project config) | Enhanced by: adds Layer 2.5 (learned rules) between executor skills and project CLAUDE.md |
| **D73** | Laravel AI SDK for all AI calls | Enables: middleware support for injecting learned rules |
| **D90** | Cost estimates with pricing date | Constrains: memory extraction jobs add marginal cost |
| **D103** | Prompt versioning — track which version produced each result | Enables: retrospective "what rules worked?" analysis |
| **D104** | Prompt injection hardening | Constrains: learned rules cannot override severity definitions, output schema, or safety boundaries |
| **D106** | Token budgets — soft guidance only | Constrains: memory injection adds ~300 tokens per prompt; monitor budget |
| **D112** | Prompt improvement — metric-triggered only | Superseded by: automates the manual pattern → improvement loop |
| **D113** | Over-reliance detection — alert at >95% acceptance | Constrains: learned rules must not lower review standards; guards feedback quality |
| **D126** | CLI/SDK alignment — shared rules, drift check | Constrains: learned rules must apply consistently to both CE and TE |
| **D134** | Queue topology — vunnix-server + vunnix-runner | Constrains: memory jobs queue on `vunnix-server` (I/O-bound) |
| **D136** | Embedding pipeline + RAG — deferred to post-v1 | Superseded by: Project Memory is a pragmatic structured alternative; pgvector can enhance later |
| **D137** | Config file naming — `.vunnix.toml` | Constrains: learned rules stored in DB (not `.vunnix.toml`) to avoid repo commits per rule |
| **D145** | RAG/pgvector — deferred, keyword search for now | Superseded by: structured extraction over unstructured embeddings |
| **D149** | Webhook-driven acceptance tracking | Enables: FindingAcceptance data pipeline is exactly what memory needs |

### Spec Alignment

§16.6 (Per-Project Learning) describes the exact flywheel Project Memory automates:

> "This creates a flywheel: usage generates data → data reveals patterns → patterns inform improvements → improved prompts generate better output → acceptance improves."

Currently this flywheel requires manual maintainer intervention (D112). Project Memory closes it automatically.

### Dependencies
- **Requires first:** Nothing — all prerequisite infrastructure exists (FindingAcceptance, VunnixAgent sections, executor env vars, PruneConversationHistory)
- **Unblocks:** Adaptive review quality per project, conversation continuity across sessions, self-improving executor, team-level pattern recognition, proactive quality gates

### Existing Patterns to Reuse

| Pattern Source | What to Reuse |
|---|---|
| **T95 (OverrelianceDetection)** | Service-based rule evaluation, weekly schedule + dedup, alert model with JSON context |
| **T86 (AcceptanceTracking)** | Multi-source webhook pipeline (merge → final state, update → near-real-time, push → correlation) |
| **T87 (EngineerFeedback)** | Sentiment aggregation from emoji reactions, per-category tracking |
| **T94 (CostAlerts)** | Alert/insight model with acknowledgment lifecycle, Pinia store + Vue component pattern |
| **VunnixAgent::buildSystemPrompt()** | Section-based prompt assembly — add `memorySection()` alongside existing sections |
| **Materialized views** | `mv_metrics_by_project` pattern for pre-computed aggregations |

## Risk Factors

- **Prompt token overhead:** Memory injection adds ~300 tokens per prompt. Must monitor token budget impact and cap injected memories (top-5 by confidence).
- **Extraction quality:** AI-extracted "facts" from conversation summaries may be noisy. Mitigate with confidence scoring and human acknowledgment of high-impact memories.
- **Feedback loop amplification:** If the system learns to suppress a finding category and that category later becomes critical, it could miss real issues. Mitigate with D113 over-reliance guards and minimum severity floors.
- **Cold start:** New projects have no memory. Graceful degradation (empty memory = no injection) is straightforward but must be tested.
- **CE/TE alignment (D126):** Learned rules must apply consistently to both Conversation Engine and Task Executor. Requires shared storage and format.

## Recommendation

**Proceed to planning-extensions.** Tier 3 architectural feature with clear spec alignment (§16.6), existing infrastructure prerequisites, and reusable patterns from T86/T87/T94/T95. Suggest implementing in three phases: Review Learning → Conversation Continuity → Cross-MR Pattern Detection (each builds on prior infrastructure, each independently valuable).

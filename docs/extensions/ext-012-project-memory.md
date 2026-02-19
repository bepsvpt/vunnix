## Extension 012: Project Memory

### Trigger

Each conversation and code review starts from scratch. FindingAcceptance data (emoji sentiment, code change correlation, resolution patterns) is collected but never fed back into AI behavior. Conversations are amnesiac across sessions. The spec's §16.6 flywheel — "usage generates data → data reveals patterns → patterns inform improvements" — currently requires manual CLAUDE.md updates (D112). Project Memory automates this loop.

### Scope

What it does:
- Extracts patterns from FindingAcceptance data (category acceptance rates, severity calibration, false positive indicators) and injects as review guidance into both Task Executor and VunnixAgent prompts
- Persists conversation summaries from PruneConversationHistory as retrievable project knowledge, so new conversations inherit context from past sessions
- Detects cross-MR patterns (repeat findings, file hotspots, emerging team conventions) via periodic analysis of FindingAcceptance history
- Provides admin API and UI for viewing, managing, and archiving learned memory entries per project
- Feature-flagged per sub-capability for gradual rollout

What it does NOT do:
- No vector embeddings or pgvector (D136/D145 remain deferred; this uses structured extraction)
- No automatic prompt modification — memory is injected as guidance context, not as overriding instructions
- No cross-project memory sharing (each project's memory is isolated)
- No real-time extraction during active streams — extraction runs async via jobs
- No modification to FindingAcceptance collection pipeline — consumes existing data only

### Architecture Fit

- **Components affected:** Models, Migrations, Services (3 new + 3 modified), Jobs (3 new + 1 modified), Agents (VunnixAgent), Middleware (PruneConversationHistory), Controllers (1 new), HTTP Resources (2 new), Config, Executor, Frontend (3 new components + 2 modified pages), Tests
- **Extension points used:** VunnixAgent section-based prompt assembly (`buildSystemPrompt()`), ProcessTaskResult dispatch chain, executor env vars (`VUNNIX_*`), `config/vunnix.php`
- **New tables:** `memory_entries`
- **New endpoints:** `GET/DELETE /api/v1/projects/{project}/memory`, `GET /api/v1/projects/{project}/memory/stats`
- **New services:** ProjectMemoryService, MemoryExtractionService, MemoryInjectionService
- **New jobs:** ExtractReviewPatterns, ExtractConversationFacts, AnalyzeCrossMRPatterns

### New Decisions

- **D195:** Memory stored in PostgreSQL, not `.vunnix.toml` — Learned rules change frequently as feedback accumulates. Storing in DB avoids requiring repo commits per rule update. `.vunnix.toml` remains for static, human-authored project config (D137 unchanged).
- **D196:** Structured extraction over vector embeddings — Extract typed patterns (category distributions, severity trends, conversation facts) as JSONB rather than unstructured vector embeddings. Pragmatic approach that works without pgvector. D136/D145 (RAG/embeddings deferred) remain valid; pgvector can enhance memory search later.
- **D197:** Memory injected as Layer 2.5 in instruction hierarchy — Learned rules sit between executor skills (Layer 2) and project CLAUDE.md (Layer 3). Cannot override severity definitions, output schema, or safety boundaries (D104). Phrased as guidance ("In this project, findings about X are typically dismissed because Y"), not directives.
- **D198:** Confidence-scored memories with 90-day TTL — Each memory entry has a confidence score (0–100) based on sample size and consistency. Entries older than 90 days auto-archive. Prevents stale patterns from persisting indefinitely. Configurable via `config/vunnix.php`.
- **D199:** Feature-flagged per sub-capability — Three independent flags: `memory.review_learning`, `memory.conversation_continuity`, `memory.cross_mr_patterns`. Each defaults to `true`. Allows disabling problematic capabilities without affecting others.
- **D200:** Memory context capped at 2000 tokens — Injected memory limited to top-N entries by confidence score, serialized to ≤2000 tokens. Prevents prompt bloat (D106 token budget constraint). Cap configurable via `memory.max_context_tokens`.

### Affected Existing Decisions

| Decision | Current State | Proposed Change | Rationale |
|---|---|---|---|
| D23 | Three-layer intelligence: Vunnix → Executor → Project config | Add Layer 2.5 (learned memory) between Executor skills and Project CLAUDE.md | Dynamic per-project guidance discovered from feedback data |
| D112 | Prompt improvement is metric-triggered, manual review only | Add automatic per-project learning alongside manual review | Manual review remains for global prompt changes; per-project patterns automated |
| D136 | Embedding pipeline deferred to post-v1 | Remains deferred; Project Memory is a structured alternative | Structured extraction covers 80% of use cases without pgvector |
| D145 | RAG/pgvector deferred, keyword search for now | Remains deferred; memory entries use JSONB queries | Can coexist with future pgvector for semantic search over memories |

### Component Design

#### MemoryEntry Model
**Current behavior:** Does not exist.
**Proposed behavior:** Single model representing a learned memory entry. Types: `review_pattern` (from FindingAcceptance), `conversation_fact` (from PruneConversationHistory), `cross_mr_pattern` (from periodic analysis). Each entry has a confidence score, source reference, and JSONB content.
**Interface changes:** New model with relationships to Project, Task (nullable), and scopes for active/archived entries.
**Data model changes:**
```
memory_entries:
  id              bigint PK
  project_id      bigint FK → projects.id (indexed)
  type            varchar (review_pattern | conversation_fact | cross_mr_pattern)
  category        varchar nullable (severity_calibration | false_positive | convention | fact | hotspot)
  content         jsonb (pattern-specific payload)
  confidence      smallint (0–100)
  source_task_id  bigint FK → tasks.id nullable
  source_meta     jsonb nullable (conversation_id, mr_iid, etc.)
  applied_count   integer default 0
  archived_at     timestamp nullable
  created_at      timestamp
  updated_at      timestamp

Indices:
  (project_id, type, archived_at, confidence DESC) — primary query path
  (project_id, created_at) — TTL cleanup
  (source_task_id) — trace back to source
```

#### ProjectMemoryService
**Current behavior:** Does not exist.
**Proposed behavior:** Central service for memory CRUD, querying, and archival. Methods: `getActiveMemories(Project, ?type)`, `archiveExpired(Project)`, `recordApplied(MemoryEntry)` (increment applied_count). Caches active memories in Redis with 5-minute TTL to avoid repeated queries on hot path.
**Interface changes:** New service, injected via constructor DI.
**Data model changes:** None (operates on memory_entries table).

#### MemoryExtractionService
**Current behavior:** Does not exist.
**Proposed behavior:** Extracts patterns from existing data sources. Three extraction methods:
1. `extractFromFindings(Project, Collection<FindingAcceptance>)` — Analyzes acceptance rates by category/severity, detects false positive patterns (>60% dismissal rate for a category over 20+ findings), identifies severity calibration drift. Returns `Collection<MemoryEntry>`.
2. `extractFromConversationSummary(Project, string $summary, array $meta)` — Extracts key facts (architectural decisions, team preferences, tool choices) from conversation summaries. Returns `Collection<MemoryEntry>`.
3. `detectCrossMRPatterns(Project, ?int $lookbackDays = 60)` — Analyzes FindingAcceptance across MRs to find file hotspots (same files flagged repeatedly), category clusters (same issue type across files), and emerging conventions (consistent acceptance/dismissal patterns). Returns `Collection<MemoryEntry>`.
**Interface changes:** New service.
**Data model changes:** None (reads from finding_acceptances, writes to memory_entries).

#### MemoryInjectionService
**Current behavior:** Does not exist.
**Proposed behavior:** Builds prompt-ready text from memory entries. Methods:
1. `buildReviewGuidance(Project)` — Returns natural-language guidance for code review prompts (e.g., "In this project, type-safety findings are dismissed 70% of the time — focus on logic errors instead"). Used by both VunnixAgent and Task Executor.
2. `buildConversationContext(Project)` — Returns key facts for chat context (e.g., "This project uses Redis Streams for real-time events (decided 2026-02-10)").
3. `buildCrossMRContext(Project)` — Returns file hotspot and pattern info for reviews.
All methods respect D200 token cap. Ranks entries by confidence, takes top-N that fit within budget.
**Interface changes:** New service.
**Data model changes:** None (reads from memory_entries).

#### VunnixAgent (modified)
**Current behavior:** `buildSystemPrompt()` assembles 8 sections. No memory awareness.
**Proposed behavior:** New `memorySection()` method added to `buildSystemPrompt()` array, positioned after `projectContextSection()`. Calls `MemoryInjectionService::buildConversationContext()` and `buildReviewGuidance()`. Returns empty string if memory disabled or empty (graceful degradation).
**Interface changes:** New protected method `memorySection(): string`.
**Data model changes:** None.

#### PruneConversationHistory Middleware (modified)
**Current behavior:** `pruneMessages()` creates summary, injects into message stream, discards summary text.
**Proposed behavior:** After generating summary, dispatches `ExtractConversationFacts` job with the summary text, project ID, and conversation metadata. Summary still injected into message stream as before. Job dispatch wrapped in try/catch — extraction failure does not affect pruning.
**Interface changes:** None (adds async side effect only).
**Data model changes:** None.

#### ProcessTaskResult Job (modified)
**Current behavior:** Validates result, dispatches downstream posting jobs (PostSummaryComment, PostInlineThreads, etc.).
**Proposed behavior:** After successful processing, checks if project has review learning enabled. If so, dispatches `ExtractReviewPatterns` job with the task ID. Placed after the existing dispatch chain (non-blocking, lowest priority).
**Interface changes:** New private method `shouldExtractMemory(Task): bool`.
**Data model changes:** None.

#### Task Executor (modified)
**Current behavior:** `build_prompt()` in `entrypoint.sh` assembles task-specific prompts. No memory awareness.
**Proposed behavior:** Reads optional `VUNNIX_MEMORY_CONTEXT` env var. If present, prepends to the task prompt as a `[Project Memory — Learned Patterns]` section. `TaskDispatcher` builds this context by calling `MemoryInjectionService::buildReviewGuidance()` and serializing to a string env var.
**Interface changes:** New optional env var `VUNNIX_MEMORY_CONTEXT`.
**Data model changes:** None.

#### ProjectMemoryController (new)
**Current behavior:** Does not exist.
**Proposed behavior:** API controller under `/api/v1/projects/{project}/memory`. Endpoints: `index` (list active entries, filterable by type/category, cursor-paginated), `stats` (aggregate stats), `destroy` (archive single entry). Authorized via existing Project policy (admin role required).
**Interface changes:** New routes, new controller, new FormRequest for index filters.
**Data model changes:** None.

#### Frontend Components (new + modified)
**Current behavior:** No memory visibility in UI.
**Proposed behavior:**
1. `ProjectMemoryPanel.vue` — Admin page tab showing memory entries per project. Filter by type, sort by confidence. Archive individual entries. Shows stats summary.
2. `MemoryStatsWidget.vue` — Dashboard widget showing learned patterns count, last extraction time, top categories. Reuses BaseCard component (ext-010).
3. `ChatPage.vue` (modified) — Small badge indicator showing "N patterns learned" for the active project.
4. `AdminPage.vue` (modified) — New "Memory" tab integrating ProjectMemoryPanel.
5. `api.ts` (modified) — New Zod schemas: MemoryEntrySchema, MemoryStatsSchema.

### Dependencies

- **Requires:** Nothing — all prerequisite infrastructure exists:
  - FindingAcceptance model and data collection pipeline (T86, T87)
  - VunnixAgent section-based prompt assembly
  - PruneConversationHistory middleware with summarization
  - ProcessTaskResult dispatch chain
  - Executor env var pattern (`VUNNIX_*`)
- **Unblocks:** Adaptive review quality per project, conversation continuity across sessions, self-improving executor, team-level pattern recognition (future ext), proactive quality gates (future ext)

### Data Migration

**Schema changes:**

| Table | Change | Reversible? |
|---|---|---|
| `memory_entries` | New table (see MemoryEntry Model section) | Yes — `php artisan migrate:rollback` drops table |

**Migration strategy:**
- [x] Additive migration only (new table, no modifications to existing tables)
- [ ] Backfill existing data — Not needed at launch. Existing FindingAcceptance data will be consumed on next review completion. Optional backfill command can be added later.
- [x] Update application code to use new schema
- [ ] Remove old columns/tables — N/A

**Zero-downtime approach:** Purely additive — new table, no existing schema changes. Deploy migration before code deployment. Application gracefully handles empty memory (returns empty guidance).

**Rollback procedure:**
- [x] Reverse migration drops `memory_entries` table
- [x] Application continues functioning — all memory injection methods return empty strings when table is missing or disabled
- [x] Feature flags (`memory.review_learning`, etc.) can disable without migration rollback
- [x] Estimated rollback time: < 1 minute (migration rollback) or instant (feature flag toggle)

### Risk Mitigation

| Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|
| Prompt token overhead exceeds budget | Increased API costs, slower responses | Low | D200: cap at 2000 tokens; monitor via TaskMetric token tracking |
| Extraction produces noisy/incorrect patterns | Wrong guidance degrades review quality | Medium | Confidence scoring (min threshold 40); 20+ sample minimum before creating review_pattern entries; admin can archive bad entries |
| Feedback loop amplification (suppress category → miss real issues) | Critical findings missed | Low | D113 over-reliance guards remain active; minimum severity floor for learned suppression; never suppress Critical findings |
| Cold start (no memory for new projects) | No immediate benefit | High (by design) | Graceful degradation — empty memory = no injection = no harm; value accumulates over first 5-10 reviews |
| CE/TE prompt divergence (D126) | Inconsistent behavior between chat and reviews | Medium | Single MemoryInjectionService serves both; shared format; integration test validates parity |
| Memory extraction job failures | Patterns not learned | Low | Async jobs with retries; extraction failure doesn't affect core review/chat pipeline; logged for monitoring |

### Rollback Plan

- **Feature flags:** Three independent flags in `config/vunnix.php` — set any to `false` to instantly disable without code deployment
- **Database:** Single `memory_entries` table. Rollback migration drops it cleanly. No foreign keys point TO this table (only FROM it to projects/tasks)
- **Git revert scope:** All tasks in this extension are additive. `git revert` of the merge commit removes all new files and modifications
- **Data recovery:** Memory entries are derived data (extractable from FindingAcceptance + conversation history). No unique data is lost on rollback

### Tasks

#### T216: Create memory_entries migration
**File(s):** `database/migrations/2026_02_18_100000_create_memory_entries_table.php`
**Action:** Create migration for `memory_entries` table. Guard with `DB::connection()->getDriverName() === 'pgsql'` for JSONB columns (SQLite compat per CLAUDE.md learnings). Columns: id, project_id (FK), type, category (nullable), content (jsonb), confidence (smallint), source_task_id (nullable FK), source_meta (jsonb nullable), applied_count (int default 0), archived_at (nullable timestamp), timestamps. Indices: composite (project_id, type, archived_at, confidence DESC), (project_id, created_at), (source_task_id).
**Verification:** `php artisan migrate` succeeds. `php artisan migrate:rollback --step=1` succeeds. Table exists with correct columns and indices.

#### T217: Create MemoryEntry model
**File(s):** `app/Models/MemoryEntry.php`
**Action:** Eloquent model with: `$fillable` for all columns, `$casts` for content (array), source_meta (array), confidence (integer), applied_count (integer), archived_at (datetime). Relationships: `project(): BelongsTo<Project>`, `sourceTask(): BelongsTo<Task>` (nullable). Scopes: `scopeActive($query)` (where archived_at is null), `scopeForProject($query, int $projectId)`, `scopeOfType($query, string $type)`, `scopeHighConfidence($query, int $min = 40)`. Add `@return` PHPDoc for relationships (Larastan).
**Verification:** `composer analyse` passes. Model can be instantiated in tinker.

#### T218: Add Project→memoryEntries relationship
**File(s):** `app/Models/Project.php`
**Action:** Add `memoryEntries(): HasMany<MemoryEntry>` relationship with `@return` PHPDoc. No other Project model changes.
**Verification:** `composer analyse` passes. `$project->memoryEntries` returns collection.

#### T219: Add memory configuration to config/vunnix.php
**File(s):** `config/vunnix.php`
**Action:** Add `'memory'` key with sub-keys: `'enabled' => (bool) env('VUNNIX_MEMORY_ENABLED', true)`, `'review_learning' => (bool) env('VUNNIX_MEMORY_REVIEW_LEARNING', true)`, `'conversation_continuity' => (bool) env('VUNNIX_MEMORY_CONVERSATION_CONTINUITY', true)`, `'cross_mr_patterns' => (bool) env('VUNNIX_MEMORY_CROSS_MR_PATTERNS', true)`, `'retention_days' => (int) env('VUNNIX_MEMORY_RETENTION_DAYS', 90)`, `'max_context_tokens' => (int) env('VUNNIX_MEMORY_MAX_CONTEXT_TOKENS', 2000)`, `'min_confidence' => (int) env('VUNNIX_MEMORY_MIN_CONFIDENCE', 40)`, `'min_sample_size' => (int) env('VUNNIX_MEMORY_MIN_SAMPLE_SIZE', 20)`.
**Verification:** `config('vunnix.memory.enabled')` returns `true`. All keys accessible.

#### T220: Create ProjectMemoryService
**File(s):** `app/Services/ProjectMemoryService.php`
**Action:** Service with methods: `getActiveMemories(Project $project, ?string $type = null): Collection` — queries memory_entries with active scope, optional type filter, ordered by confidence DESC, cached in Redis 5-min TTL. `archiveExpired(Project $project): int` — archives entries older than retention_days, returns count. `recordApplied(MemoryEntry $entry): void` — increments applied_count. `deleteEntry(MemoryEntry $entry): void` — sets archived_at. `getStats(Project $project): array` — returns aggregate counts by type, average confidence, last extraction time.
**Verification:** Unit tests pass. `composer analyse` passes.

#### T221: Create MemoryExtractionService with review pattern extraction
**File(s):** `app/Services/MemoryExtractionService.php`
**Action:** Service with method `extractFromFindings(Project $project, Collection $acceptances): Collection<MemoryEntry>`. Logic: group acceptances by category, calculate acceptance/dismissal rates per category. For categories with ≥`min_sample_size` findings and dismissal rate >60%, create `review_pattern` entry with `category: 'false_positive'`. For severity levels where acceptance rate differs >20% from global average, create `review_pattern` entry with `category: 'severity_calibration'`. Confidence = min(sample_size, 100). Content JSONB: `{pattern, category, acceptance_rate, dismissal_rate, sample_size, example_titles}`. Dedup: skip if identical pattern already exists for this project with confidence ≥ current.
**Verification:** Unit tests with mocked FindingAcceptance data pass. Patterns correctly extracted for edge cases (empty data, single finding, all accepted, all dismissed).

#### T222: Create ExtractReviewPatterns job
**File(s):** `app/Jobs/ExtractReviewPatterns.php`
**Action:** Queue job on `vunnix-server`. Accepts task_id. Loads task with FindingAcceptance relationship. Calls `MemoryExtractionService::extractFromFindings()` with the project and acceptances. Saves returned MemoryEntry instances. Invalidates Redis cache for this project's memories. Wrapped in try/catch — logs warning on failure, never throws (non-critical path).
**Verification:** Feature test: create task with FindingAcceptance records → dispatch job → verify memory_entries created.

#### T223: Modify ProcessTaskResult to dispatch ExtractReviewPatterns
**File(s):** `app/Jobs/ProcessTaskResult.php`
**Action:** After the existing dispatch chain (line ~117), add: `if ($this->shouldExtractMemory($task)) { ExtractReviewPatterns::dispatch($task->id); }`. New private method `shouldExtractMemory(Task $task): bool` — returns true when: task type is CodeReview or SecurityAudit, project has memory enabled (`config('vunnix.memory.enabled') && config('vunnix.memory.review_learning')`), and task has findings.
**Verification:** Feature test: process a code review task result → verify ExtractReviewPatterns job dispatched. Existing ProcessTaskResult tests still pass.

#### T224: Create MemoryInjectionService
**File(s):** `app/Services/MemoryInjectionService.php`
**Action:** Service with methods:
1. `buildReviewGuidance(Project $project): string` — Loads active review_pattern + cross_mr_pattern entries via ProjectMemoryService. Formats as natural-language guidance: "Based on previous reviews in this project: [pattern descriptions]". Respects max_context_tokens. Returns empty string if no entries or memory disabled.
2. `buildConversationContext(Project $project): string` — Loads active conversation_fact entries. Formats as key facts list. Returns empty string if none.
3. Both methods call `ProjectMemoryService::recordApplied()` for each entry used.
**Verification:** Unit tests with seeded memory entries. Output respects token cap. Empty project returns empty string.

#### T225: Add memorySection() to VunnixAgent
**File(s):** `app/Agents/VunnixAgent.php`
**Action:** Add `protected function memorySection(): string` that calls `MemoryInjectionService::buildReviewGuidance($this->project)` and `buildConversationContext($this->project)`. Wraps output in `[Project Memory — Learned Patterns]\n{guidance}\n\n[Project Memory — Key Facts]\n{facts}`. Returns empty string if project is null or both sections empty. Add to `buildSystemPrompt()` array after `projectContextSection()`.
**Verification:** Unit test: VunnixAgent with project that has memory entries → system prompt includes memory section. VunnixAgent without project → no memory section. `composer analyse` passes.

#### T226: Modify TaskDispatcher to pass memory context to executor
**File(s):** `app/Services/TaskDispatcher.php` (or wherever pipeline variables are built)
**Action:** When building pipeline variables for runner tasks, call `MemoryInjectionService::buildReviewGuidance($project)`. If non-empty, add `VUNNIX_MEMORY_CONTEXT` to the pipeline variables. Keep it optional — executor handles missing var gracefully.
**Verification:** Feature test: dispatch task for project with memory → verify `VUNNIX_MEMORY_CONTEXT` included in pipeline variables. Dispatch for project without memory → var absent.

#### T227: Modify executor to read VUNNIX_MEMORY_CONTEXT
**File(s):** `executor/entrypoint.sh`
**Action:** In `build_prompt()`, after the task-type-specific prompt, check if `VUNNIX_MEMORY_CONTEXT` is set and non-empty. If so, append: `\n\n[Project Memory — Learned Patterns]\n${VUNNIX_MEMORY_CONTEXT}`. Do NOT add to `validate_env()` required vars — this is optional.
**Verification:** Run executor with `VUNNIX_MEMORY_CONTEXT` set → prompt includes memory section. Run without → prompt unchanged.

#### T228: Modify PruneConversationHistory to extract conversation facts
**File(s):** `app/Agents/Middleware/PruneConversationHistory.php`
**Action:** In `pruneMessages()`, after the summary is generated (line ~101) and before `setPrunedMessages()`, dispatch `ExtractConversationFacts` job with: summary text, project ID (from agent's project), conversation ID. Wrap dispatch in try/catch — log warning on failure, proceed with pruning regardless. Only dispatch if conversation_continuity feature flag is enabled.
**Verification:** Feature test: long conversation triggers pruning → ExtractConversationFacts job dispatched. Pruning still works if job dispatch fails.

#### T229: Create ExtractConversationFacts job
**File(s):** `app/Jobs/ExtractConversationFacts.php`
**Action:** Queue job on `vunnix-server`. Accepts: summary (string), project_id (int), conversation_meta (array with conversation_id). Calls `MemoryExtractionService::extractFromConversationSummary()`. Saves returned entries. Method implementation: parse summary for declarative statements about architecture, decisions, preferences. Create `conversation_fact` entries with content containing the fact text and source metadata. Confidence based on specificity (named technologies/patterns = higher). Dedup: skip if similar fact already exists (fuzzy match on content key).
**Verification:** Unit test with sample summaries → facts extracted. Feature test: job dispatch → entries created in DB.

#### T230: Add extractFromConversationSummary to MemoryExtractionService
**File(s):** `app/Services/MemoryExtractionService.php`
**Action:** Add method `extractFromConversationSummary(Project $project, string $summary, array $meta): Collection<MemoryEntry>`. Strategy: split summary into sentences, filter for declarative statements containing architectural/technical terms (e.g., "uses", "decided", "chose", "configured", "structured"). Create `conversation_fact` entries. Confidence: 60 for general facts, 80 for facts mentioning specific technologies/files. Source_meta: conversation_id from $meta.
**Verification:** Unit tests with varied summaries (technical decisions, vague chat, empty string).

#### T231: Add cross-MR pattern detection to MemoryExtractionService
**File(s):** `app/Services/MemoryExtractionService.php`
**Action:** Add method `detectCrossMRPatterns(Project $project, int $lookbackDays = 60): Collection<MemoryEntry>`. Query FindingAcceptance for the project within lookback window. Detect: (1) file hotspots — files with ≥3 findings across different MRs, (2) category clusters — same category flagged in ≥3 different MRs, (3) repeat dismissals — same finding title dismissed in ≥2 MRs. Create `cross_mr_pattern` entries with category `hotspot` or `convention`. Confidence based on occurrence count.
**Verification:** Unit tests with multi-MR FindingAcceptance datasets.

#### T232: Create AnalyzeCrossMRPatterns scheduled command
**File(s):** `app/Console/Commands/AnalyzeCrossMRPatterns.php`, `app/Providers/AppServiceProvider.php` (or routes/console.php)
**Action:** Artisan command `memory:analyze-patterns` that iterates enabled projects and calls `MemoryExtractionService::detectCrossMRPatterns()`. Schedule weekly on Mondays at 03:00 UTC (`->weekly()->mondays()->at('03:00')->withoutOverlapping()`). Only runs if `config('vunnix.memory.cross_mr_patterns')` is enabled.
**Verification:** `php artisan memory:analyze-patterns` runs without error. Schedule registered in kernel.

#### T233: Create ArchiveExpiredMemories scheduled command
**File(s):** `app/Console/Commands/ArchiveExpiredMemories.php`
**Action:** Artisan command `memory:archive-expired` that iterates all projects and calls `ProjectMemoryService::archiveExpired()`. Schedule daily at 04:00 UTC. Logs count of archived entries per project.
**Verification:** `php artisan memory:archive-expired` runs. Entries older than retention_days get archived_at set.

#### T234: Create ProjectMemoryController with API endpoints
**File(s):** `app/Http/Controllers/Api/ProjectMemoryController.php`, `app/Http/Requests/ListMemoryEntriesRequest.php`, `routes/api.php`
**Action:** Controller with: `index(ListMemoryEntriesRequest, Project)` — list active entries, filterable by `type` and `category`, cursor-paginated (25 per page). `stats(Project)` — return aggregate stats. `destroy(Project, MemoryEntry)` — archive entry (set archived_at). Authorization: require project admin role via existing policy. FormRequest: optional `type` (in: review_pattern, conversation_fact, cross_mr_pattern), optional `category` (string). Routes: `GET /api/v1/projects/{project}/memory`, `GET /api/v1/projects/{project}/memory/stats`, `DELETE /api/v1/projects/{project}/memory/{memoryEntry}`.
**Verification:** Feature tests for each endpoint (list, stats, destroy) with auth checks.

#### T235: Create MemoryEntryResource and ProjectMemoryStatsResource
**File(s):** `app/Http/Resources/MemoryEntryResource.php`, `app/Http/Resources/ProjectMemoryStatsResource.php`
**Action:** MemoryEntryResource: id, type, category, content, confidence, applied_count, source_task_id, created_at. ProjectMemoryStatsResource: total_entries, by_type (counts), by_category (counts), average_confidence, last_created_at.
**Verification:** `composer analyse` passes. Resources render correctly in API responses.

#### T236: Add Zod schemas and API composable for frontend
**File(s):** `resources/js/types/api.ts`, `resources/js/composables/useProjectMemory.ts`
**Action:** Add Zod schemas: `MemoryEntrySchema` (id, type, category, content, confidence, applied_count, source_task_id, created_at), `MemoryStatsSchema` (total_entries, by_type, by_category, average_confidence, last_created_at). Create composable `useProjectMemory(projectId)` with methods: `fetchEntries(filters?)`, `fetchStats()`, `archiveEntry(entryId)`. Uses axios with proper typing.
**Verification:** `npm run typecheck` passes. `npm run lint` passes.

#### T237: Create ProjectMemoryPanel component for Admin
**File(s):** `resources/js/components/ProjectMemoryPanel.vue`
**Action:** Component displaying memory entries for a project. Uses `useProjectMemory` composable. Features: filter chips for type (review_pattern, conversation_fact, cross_mr_pattern), sortable by confidence, archive button per entry with confirmation. Shows entry content formatted by type. Uses BaseCard, BaseBadge, BaseFilterChips, BaseButton from ext-010 component library. Empty state via BaseEmptyState when no entries.
**Verification:** `npm test -- ProjectMemoryPanel` passes. Component renders all states (loading, empty, data, filtered).

#### T238: Add Memory tab to AdminPage
**File(s):** `resources/js/pages/AdminPage.vue`
**Action:** Add "Memory" tab to the admin page tab group. When selected, renders `ProjectMemoryPanel` for the currently selected project. Only visible when `config.memory.enabled` is true (passed from backend via existing config endpoint or hardcoded initially).
**Verification:** `npm test -- AdminPage` passes (updated for new tab). Memory tab renders ProjectMemoryPanel.

#### T239: Add MemoryStatsWidget to DashboardPage
**File(s):** `resources/js/components/MemoryStatsWidget.vue`, `resources/js/pages/DashboardPage.vue`
**Action:** Create `MemoryStatsWidget` using BaseCard. Shows: total learned patterns, breakdown by type (3 badges), average confidence bar, last extraction timestamp. Add to DashboardPage in the overview section. Fetches via `useProjectMemory` composable.
**Verification:** `npm test -- MemoryStatsWidget` and `npm test -- DashboardPage` pass.

#### T240: Unit tests for services and models
**File(s):** `tests/Unit/Services/ProjectMemoryServiceTest.php`, `tests/Unit/Services/MemoryExtractionServiceTest.php`, `tests/Unit/Services/MemoryInjectionServiceTest.php`, `tests/Unit/Models/MemoryEntryTest.php`
**Action:** Unit tests (Mockery, no Laravel container) for: ProjectMemoryService query/cache/archive logic, MemoryExtractionService pattern detection from findings and summaries, MemoryInjectionService prompt building and token cap, MemoryEntry model scopes and casts. Use factories for test data.
**Verification:** `php artisan test --filter=ProjectMemoryService && php artisan test --filter=MemoryExtraction && php artisan test --filter=MemoryInjection && php artisan test --filter=MemoryEntry` all pass.

#### T241: Feature tests for jobs and integration
**File(s):** `tests/Feature/Jobs/ExtractReviewPatternsTest.php`, `tests/Feature/Jobs/ExtractConversationFactsTest.php`, `tests/Feature/ProjectMemoryIntegrationTest.php`
**Action:** Feature tests with real DB: ExtractReviewPatterns job creates entries from seeded FindingAcceptance data. ExtractConversationFacts job creates entries from sample summaries. Integration test: full pipeline — create task with findings → ProcessTaskResult → ExtractReviewPatterns dispatched → entries created → MemoryInjectionService builds guidance → guidance non-empty. Use `Queue::fake([ExtractReviewPatterns::class])` in upstream tests that don't need full chain.
**Verification:** `php artisan test --filter=ExtractReviewPatterns && php artisan test --filter=ExtractConversationFacts && php artisan test --filter=ProjectMemoryIntegration` all pass.

#### T242: Feature tests for API endpoints
**File(s):** `tests/Feature/Api/ProjectMemoryControllerTest.php`
**Action:** Feature tests for: list entries (with filters, pagination), stats endpoint, archive entry, authorization (non-admin rejected). Seed memory entries via factory.
**Verification:** `php artisan test --filter=ProjectMemoryController` passes.

#### T243: Frontend component tests
**File(s):** `tests/js/components/ProjectMemoryPanel.test.ts`, `tests/js/components/MemoryStatsWidget.test.ts`
**Action:** Vitest + Vue Test Utils tests for: ProjectMemoryPanel rendering with mocked data, filter interaction, archive action. MemoryStatsWidget rendering with stats data, empty state. Mock axios calls.
**Verification:** `npm test -- ProjectMemoryPanel && npm test -- MemoryStatsWidget` pass.

#### T244: End-to-end verification test
**File(s):** `tests/Feature/ProjectMemoryEndToEndTest.php`
**Action:** Single integration test that exercises the full pipeline: (1) Create project with memory enabled, (2) Create task with FindingAcceptance records showing >60% dismissal rate for a category, (3) Run ExtractReviewPatterns, (4) Verify memory_entries created with correct patterns, (5) Call MemoryInjectionService::buildReviewGuidance(), (6) Verify output mentions the dismissed category, (7) Call archiveExpired with 0 retention → verify entries archived. This validates the complete data flywheel.
**Verification:** `php artisan test --filter=ProjectMemoryEndToEnd` passes.

### Verification

- [ ] `memory_entries` table created with correct schema and indices
- [ ] Review patterns extracted from FindingAcceptance data after task completion
- [ ] Conversation facts extracted from PruneConversationHistory summaries
- [ ] Cross-MR patterns detected by weekly scheduled command
- [ ] Memory guidance injected into VunnixAgent system prompt (Layer 2.5)
- [ ] Memory context passed to Task Executor via `VUNNIX_MEMORY_CONTEXT` env var
- [ ] API endpoints return correct data with proper authorization
- [ ] Admin UI displays memory entries with filtering and archival
- [ ] Dashboard shows memory stats widget
- [ ] Feature flags independently disable each sub-capability
- [ ] Empty memory (cold start) produces no injection (graceful degradation)
- [ ] Memory entries respect 90-day TTL via scheduled archival
- [ ] Token cap (2000) enforced on injected context
- [ ] All existing tests still pass (no regressions)
- [ ] New tests cover all services, jobs, controllers, and frontend components
- [ ] `composer analyse` passes (PHPStan level 8)
- [ ] `npm run typecheck` and `npm run lint` pass

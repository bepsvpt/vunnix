# Assessment: UI/UX Full Component Library Refactor

**Date:** 2026-02-18
**Requested by:** Kevin (project owner)
**Trigger:** UX audit identified 5 systemic pain points — wasted space on wide screens, indistinguishable navigation layers, chat readability ("text wall" effect), empty Dashboard showing bare zeros, and inconsistent styling across 31 Vue components (5 different border-radii, 4 padding patterns, 3 button radii, no shadows on cards).

## What

Extract a shared base component library (7 primitives: BaseCard, BaseBadge, BaseButton, BaseTabGroup, BaseFilterChips, BaseEmptyState, BaseSpinner), introduce a design token system via Tailwind `@theme` CSS custom properties, add an AppShell layout with content containment (`max-w-[1280px]`), establish a 3-tier navigation hierarchy (navbar underline → page tabs underline → filter chips pill), redesign chat message bubbles (width caps at `max-w-2xl` for assistant, `max-w-md` for user, increased line-height and paragraph spacing), replace all Dashboard/Admin empty states with onboarding CTAs, and unify all radii/shadows/padding/colors across the entire frontend.

## Classification

**Tier:** 3 (Architectural)
**Rationale:** This introduces a new abstraction layer (component library + design tokens) that does not currently exist. It touches all 31 Vue components, all 3 page components, the root layout (App.vue), the global CSS, all 4 Pinia stores, and 4–6 backend API controllers. The estimated scope is 120+ frontend files modified or created plus 6–10 backend files extended. It changes how every UI element is constructed — from ad-hoc inline Tailwind classes to token-backed base components. The closest precedent is the TypeScript migration (ext-008, Tier 3, 131 files), which succeeded due to the 97% test coverage safety net that also exists here.

**Modifiers:**
- [ ] `breaking` — No public API, DB schema, or external contract changes
- [ ] `multi-repo` — Single repository only
- [ ] `spike-required` — Tailwind 4 `@theme` is well-documented; no feasibility unknowns
- [ ] `deprecation` — No capabilities removed; inline styles are replaced, not sunset
- [ ] `migration` — No data migration; purely frontend refactoring

## Impact Analysis

### Components Affected

| Component | Impact | Files (est.) |
|---|---|---|
| **New: `resources/js/components/ui/`** | Create 7 base primitives (BaseCard, BaseBadge, BaseButton, BaseTabGroup, BaseFilterChips, BaseEmptyState, BaseSpinner) + tests | 14 new |
| **`resources/css/app.css`** | Extend `@theme` block with ~30 design tokens (spacing, radii, shadows, widths); add `.chat-bubble` typography overrides | 1 modified |
| **`resources/js/App.vue`** | Extract AppShell pattern — move padding/containment out of `<main>`, delegate to pages | 1 modified |
| **`resources/js/components/AppNavigation.vue`** | Sticky positioning, surface nav link icons, underline active state, subtle nav shadow | 1 modified |
| **`resources/js/pages/DashboardPage.vue`** | Content containment (`max-w-[--width-content]`), replace button tabs with BaseTabGroup (underline style) | 1 modified |
| **`resources/js/pages/AdminPage.vue`** | Content containment, BaseTabGroup, responsive header | 1 modified |
| **`resources/js/pages/ChatPage.vue`** | Minor — already full-bleed split layout; update references to design tokens | 1 modified |
| **`resources/js/components/DashboardOverview.vue`** | Replace hardcoded cards with BaseCard, add micro-visualizations (progress bars, trend deltas), three-state empty model (error / all-zeros onboarding / data) | 1 modified |
| **`resources/js/components/MessageBubble.vue`** | Width constraints (user: `max-w-md`, assistant: `max-w-2xl`), padding increase (`px-5 py-4`), `.chat-bubble` scoped typography | 1 modified |
| **`resources/js/components/MessageThread.vue`** | Message column cap (`max-w-4xl mx-auto`), `space-y-4`, error banner moved outside scroll container, streaming bubble alignment | 1 modified |
| **`resources/js/components/ConversationListItem.vue`** | Simplify: remove last-message preview, add active-task indicator with BaseBadge, left-border selected state | 1 modified |
| **`resources/js/components/ConversationList.vue`** | BaseEmptyState for empty list, BaseButton for "New Conversation" | 1 modified |
| **`resources/js/components/ActivityFeed.vue`** | Replace button tabs with BaseFilterChips (pill style), BaseEmptyState | 1 modified |
| **`resources/js/components/AdminProjectList.vue`** | Responsive table layout on `lg:`, BaseCard, BaseBadge, BaseButton, BaseEmptyState | 1 modified |
| **Remaining 19 components** | Replace inline Tailwind patterns with base components: BaseCard (12 consumers), BaseBadge (6), BaseButton (8), BaseSpinner (7) | 19 modified |
| **`resources/js/stores/dashboard.ts`** | Minor — add computed for "all zeros" empty state detection | 1 modified |
| **Frontend test files** | Update all 31 component tests + 3 page tests + App test for new component structure; add 7 new base component tests | 41 modified/new |
| **Backend API controllers** | Extend `DashboardOverviewController` to return `tasks_by_type_7d` trend deltas and `last_activity_description`; minor | 2–4 modified |
| **Backend API resources** | Extend `DashboardOverviewResource` with new fields | 1–2 modified |

**Total estimate: ~95 files modified + ~16 files created = ~111 files**

### Relevant Decisions

| Decision | Summary | Relationship |
|---|---|---|
| D135 | Responsive Web Design — desktop-first with fluid layouts adapting to tablet and mobile | **Enables** — establishes the responsive philosophy; this refactor formalizes it with a grid system and breakpoint tokens |
| D138 | Markdown rendering via `markdown-it` + `@shikijs/markdown-it` | **Enables** — the existing MarkdownContent.vue + `.markdown-content` CSS become the foundation for chat typography tuning |
| D47 | Application layout — separate pages: Chat, Dashboard, Admin | **Enables** — the three-page structure defines the scope of the AppShell and navigation hierarchy |
| D46 | Full SPA with Vue 3 | **Enables** — component library is a natural evolution of the existing Vue SPA |
| D66 | Pinia for state management | **Enables** — stores are already typed; empty-state detection logic fits naturally into computed properties |
| D91 | Claude Opus 4.6 for everything (no model tiering) | **No impact** — AI model selection is backend-only |

No decisions are **constrained** or **superseded** by this change. All relevant decisions are directionally aligned.

### Dependencies

- **Requires first:** Nothing external. All infrastructure (Vue SPA scaffold T61, Dashboard API T75–T82, Reverb/Echo T73–T74, Tailwind CSS, Pinia stores) already exists and is operational. The 97% frontend test coverage provides the regression safety net.
- **Unblocks:**
  - Faster Dashboard view development (T75–T82) — reusable card/filter/empty-state components reduce per-view boilerplate by ~30%
  - Faster Admin page refinement (T88–T93) — shared form patterns, table layouts
  - Frontend review skill quality (T21) — can reference actual design token names in prompt instead of ad-hoc class descriptions
  - Designer iteration workflow (T72) — AI executor can target consistent component APIs for UI adjustments
  - Mobile responsiveness (D135) — shared breakpoint system and responsive grid benefit all pages

## Risk Factors

- **Large blast radius (111 files):** Mitigated by 97% frontend test coverage and a 5-batch migration strategy (foundation → layout → dashboard/admin → chat → polish). Each batch is independently shippable.
- **Test churn:** All 41 test files will need updates to account for new component wrappers (e.g., testing a BaseCard inside DashboardOverview). Test structure changes are mechanical but time-consuming.
- **Backend API extension for trend data:** The `tasks_by_type_7d` trend delta requires a new query in `DashboardOverviewController`. This is a minor scope creep (1–2 files) but should be time-boxed — if complex, the trend deltas can be deferred and the empty-state redesign ships without them.
- **No prior component library patterns in codebase:** This is greenfield. Risk of over-engineering the primitives. Mitigation: start with the 7 identified primitives only, resist adding more until consumers demand them.
- **Potential merge conflicts:** If other work touches the same 31 component files during the refactor. Mitigation: use a git worktree for isolation (per `using-git-worktrees` skill).

## Recommendation

**Proceed to `planning-extensions`.**

This is Tier 3 with no modifiers — a large but well-understood refactor with zero feasibility risk (no spike needed), zero breaking changes, and zero data migration. The 97% test coverage and 5-batch migration strategy provide strong guardrails. The TypeScript migration (ext-008) demonstrated that big-bang frontend refactors of this scale succeed in this codebase.

The planning document should produce:
1. Atomic task list for each of the 5 batches
2. Verification criteria per batch (which tests must pass, which visual checks to run)
3. Decision on whether backend trend-data API extension is in-scope or deferred
4. File-level change manifest for each batch

## Extension 010: UI/UX Full Component Library Refactor

**Status: ✅ Implemented** — `b9d34cc`

### Trigger
UX audit identified 5 systemic pain points: (1) wasted space on wide screens — Dashboard/Admin content stretches to edges with no max-width containment; (2) indistinguishable navigation layers — page tabs and inline filter tabs use identical styling; (3) chat readability — assistant messages expand to 80% viewport width creating text walls; (4) empty Dashboard shows bare "0" counts with no onboarding guidance; (5) inconsistent styling — 5 border-radii, 4 padding patterns, 3 button radii, no card shadows across 31 components.

### Scope
What it does:
- Extracts 7 base UI primitives into `resources/js/components/ui/`: BaseCard, BaseBadge, BaseButton, BaseTabGroup, BaseFilterChips, BaseEmptyState, BaseSpinner
- Introduces design token system via Tailwind `@theme` CSS custom properties (~30 tokens for spacing, radii, shadows, widths, typography)
- Adds AppShell layout pattern with content containment (max-width 1280px) for Dashboard and Admin
- Establishes 3-tier navigation hierarchy: sticky navbar (heaviest) → page underline tabs (medium) → inline filter pills (lightest)
- Redesigns chat message bubbles: assistant capped at `max-w-2xl` (672px), user at `max-w-md` (448px), increased line-height and paragraph spacing
- Replaces all Dashboard/Admin empty states with onboarding CTAs via BaseEmptyState
- Unifies all radii, shadows, padding, colors, and interaction states across all 31 components

What it does NOT do:
- No database schema changes or migrations
- No new API endpoints (only extends 1–2 existing dashboard responses with optional fields)
- No changes to backend business logic, queue topology, or AI pipeline
- No new Pinia stores or composables beyond minor computed property additions
- No dark mode redesign — uses existing `dark:` prefix system, just unifies the values
- No mobile-specific redesign — respects D135 desktop-first; improves wide-screen layout
- No form component library (Admin forms keep their current structure)

### Architecture Fit
- **Components affected:** All 31 Vue components, 3 page components, App.vue, app.css, 4 Pinia stores, 41 test files, 1–2 backend API controllers/resources
- **Extension points used:** Tailwind `@theme` (existing, extended), Vue component composition (existing pattern)
- **New tables/endpoints/services:** None. Optionally extends `DashboardOverviewController` response with `tasks_by_type_7d` and `last_activity_description` fields (additive, non-breaking)

### New Decisions
- **D189:** Design token system via Tailwind `@theme` CSS custom properties — tokens for spacing (page, section, card), radii (card 12px, button 8px, input 10px, bubble 20px, badge pill), shadows (card, card-hover, dropdown, nav), and content widths (1280px content, 1024px narrow, 320px sidebar). Rationale: tokens enforce consistency without adding a build step or separate config file; `@theme` is already in use for the font stack.
- **D190:** 3-tier navigation visual hierarchy — Global nav uses icon+text links with underline active state (heaviest weight), page-level tabs use underline-style `BaseTabGroup` anchored to a bottom border (medium weight), inline filters use filled pill-style `BaseFilterChips` (lightest weight). Rationale: the current identical styling across all three layers creates ambiguous hierarchy; distinct visual patterns reduce cognitive load.
- **D191:** Chat message bubble width constraints — assistant messages `max-w-2xl` (672px, ~65 chars/line), user messages `max-w-md` (448px), message column `max-w-4xl` (896px). Typography: `leading-[1.75]`, paragraph spacing `my-3`, scoped via `.chat-bubble` class. Rationale: optimal reading width is 45–75 characters; the current `max-w-[80%]` allows 100+ chars/line on wide screens.
- **D192:** Three-state empty model for Dashboard — State A (API error): retry CTA; State B (data loaded, all zeros): onboarding guidance with "Enable a project" + "Start a conversation" CTAs; State C (data present): normal render. Discrimination: `overview === null` → A, all counts zero → B, else → C. Rationale: bare zeros provide no affordance; onboarding CTAs drive first-time activation.
- **D193:** Base UI component library at `resources/js/components/ui/` — 7 primitives (BaseCard, BaseBadge, BaseButton, BaseTabGroup, BaseFilterChips, BaseEmptyState, BaseSpinner) with typed props, variant systems, and design-token-backed styling. Rationale: 31 components currently use ad-hoc inline Tailwind with 5 different border-radii and 4 padding patterns; shared primitives enforce consistency by construction.

### Affected Existing Decisions

| Decision | Current State | Proposed Change | Rationale |
|---|---|---|---|
| D135 | "Desktop-first with breakpoints" (unspecified values) | Formalized: content max-width 1280px, 12-column grid with responsive `col-span` breakpoints at `md` and `lg` | The refactor implements what D135 described in principle |
| D47 | "Separate pages: Chat, Dashboard, Admin" | Each page type gets a defined container strategy: Dashboard/Admin use centered content containment, Chat uses full-bleed split-panel | Adds layout specifics that D47 left to implementation |

No decisions are superseded.

### Component Design

#### Design Token System (`resources/css/app.css`)
**Current behavior:** `@theme` block defines only `--font-sans`. All spacing, radii, shadows, and widths are ad-hoc Tailwind utility classes per component.
**Proposed behavior:** `@theme` block extended with ~30 tokens covering spacing (page, section, card, card-lg), radii (card 12px, button 8px, input 10px, bubble 20px, badge 9999px), shadows (card, card-hover, dropdown, nav), and content widths (content 80rem, narrow 64rem, sidebar 20rem). Chat-specific `.chat-bubble .markdown-content` overrides for `leading-[1.75]`, `my-3` paragraphs, `my-1` list items.
**Interface changes:** None — CSS-only, consumed via Tailwind utility classes referencing custom properties.
**Data model changes:** None.

#### Base Primitives (`resources/js/components/ui/`)
**Current behavior:** Don't exist. Each of the 31 components defines its own button, card, badge, tab, empty state, and spinner styling inline.
**Proposed behavior:** 7 new Vue components with typed props:
- `BaseCard` — props: `padded` (bool), `hoverable` (bool), `variant` ('default'|'success'|'danger'). Renders `rounded-[--radius-card]`, `shadow-[--shadow-card]`, `border`, with hover shadow lift.
- `BaseBadge` — props: `variant` ('neutral'|'success'|'warning'|'danger'|'info'). Renders pill shape, consistent padding, font-medium.
- `BaseButton` — props: `variant` ('primary'|'secondary'|'ghost'|'danger'), `size` ('sm'|'md'|'lg'), `loading` (bool), `disabled` (bool). Built-in spinner for loading state.
- `BaseTabGroup` — props: `tabs` (array of {key, label}), `modelValue` (string). Underline style with bottom border, horizontal scroll overflow with fade indicator.
- `BaseFilterChips` — props: `chips` (array of {label, value}), `modelValue` (string|null). Filled pill style, small text.
- `BaseEmptyState` — slots: `icon`, `title`, `description`, `action`. Centered layout with icon-in-circle, title/description hierarchy, CTA button slot.
- `BaseSpinner` — props: `size` ('sm'|'md'|'lg'). Replaces the 7 identical inline SVG spinners.
**Interface changes:** New public components. No changes to existing component public APIs.
**Data model changes:** None.

#### AppNavigation (`resources/js/components/AppNavigation.vue`)
**Current behavior:** Non-sticky `<nav>` with max-w-7xl container. Desktop links show text only (emoji icons defined in `navLinks` array but not rendered). Active state: subtle background change (`bg-zinc-100`). No shadow.
**Proposed behavior:** Sticky `top-0 z-30`. Subtle bottom shadow (`--shadow-nav`). Desktop links render `link.icon` emoji + text label. Active state changes to bottom-border underline (`border-b-2 border-zinc-900`). Logo area gets a simple geometric mark alongside "Vunnix" text.
**Interface changes:** None — internal template changes only.
**Data model changes:** None.

#### App.vue / Layout Shell (`resources/js/App.vue`)
**Current behavior:** `<main class="flex-1 p-4 lg:p-8">` wraps `<router-view />` with uniform padding and no max-width.
**Proposed behavior:** `<main class="flex-1">` with no padding — pages control their own containment. Dashboard/Admin apply `max-w-[var(--width-content)] mx-auto px-6 lg:px-8 py-6`. Chat remains full-bleed.
**Interface changes:** None — pages already receive full-width `<main>`; the change is removing the implicit padding.
**Data model changes:** None.

#### DashboardPage (`resources/js/pages/DashboardPage.vue`)
**Current behavior:** `<h1>` + button-style tabs (`px-4 py-2 rounded-lg border`) + conditional component rendering, all in unsized `<div>`.
**Proposed behavior:** Content container with `max-w-[var(--width-content)]`. `<h1>` + `<BaseTabGroup>` (underline style) in a header zone. Tab content in a padded body zone. Horizontal tab overflow with scroll.
**Interface changes:** None externally.
**Data model changes:** None.

#### AdminPage (`resources/js/pages/AdminPage.vue`)
**Current behavior:** Same as DashboardPage — `<h1>` + identical button-style tabs.
**Proposed behavior:** Same content container and `<BaseTabGroup>` pattern as DashboardPage.
**Interface changes:** None externally.
**Data model changes:** None.

#### DashboardOverview (`resources/js/components/DashboardOverview.vue`)
**Current behavior:** 3-column summary cards (`grid-cols-3`) showing Active Tasks / Success Rate / Recent Activity as bare `text-2xl` numbers. 4-column type cards showing emoji + count + label centered. Empty state: "No overview data available." All-zeros state: renders numbers as `0`.
**Proposed behavior:** Summary cards use `BaseCard` with subtle shadow. Add micro-visualizations: segmented bar for active tasks (queued vs running), horizontal progress bar for success rate, "what + when" for recent activity. Type cards show count + weekly trend delta ("+2 this week"). Three-state empty model (D192): error → retry CTA, all-zeros → onboarding guidance, data → normal render.
**Interface changes:** Consumes optional new fields from overview API: `tasks_by_type_7d` (Record<string, number>), `last_activity_description` (string|null), `active_task_breakdown` ({queued: number, running: number}). All fields optional — graceful degradation if absent.
**Data model changes:** None (API response extension only, additive).

#### MessageBubble (`resources/js/components/MessageBubble.vue`)
**Current behavior:** `max-w-[80%]`, `rounded-2xl`, `px-4 py-3`. User: `bg-blue-600 text-white`. Assistant: `bg-zinc-100 dark:bg-zinc-800`. Timestamp: `mt-1 text-xs opacity-60`.
**Proposed behavior:** User: `max-w-md`, `rounded-[--radius-bubble] rounded-br-sm`, `px-4 py-3`, `text-sm leading-relaxed`. Assistant: `max-w-2xl`, `rounded-[--radius-bubble] rounded-bl-sm`, `px-5 py-4`, `.chat-bubble` wrapper for scoped typography. Timestamp: `px-4 pb-2 text-[11px] opacity-50`.
**Interface changes:** None — same props.
**Data model changes:** None.

#### MessageThread (`resources/js/components/MessageThread.vue`)
**Current behavior:** `space-y-3 max-w-3xl mx-auto` message column. Error banners inside scroll container. Streaming bubble duplicates hardcoded bubble styles.
**Proposed behavior:** `space-y-4 max-w-4xl mx-auto py-6` message column. Error banners moved outside scroll container (fixed between header and scroll area). Streaming bubble uses same `max-w-2xl` + `.chat-bubble` styling as MessageBubble.
**Interface changes:** None.
**Data model changes:** None.

#### ConversationListItem (`resources/js/components/ConversationListItem.vue`)
**Current behavior:** Shows title + last-message preview (truncated) + project badge (inline with title) + relative time. Selected state: `bg-zinc-100`.
**Proposed behavior:** Title + project badge on its own line (using BaseBadge neutral). Replace last-message preview with active-task indicator (BaseBadge info) when a task is running. Selected state: `bg-zinc-50 border-l-2 border-l-blue-500`.
**Interface changes:** May require `active_task` field on conversation object (optional — graceful degradation if absent).
**Data model changes:** None.

#### ActivityFeed (`resources/js/components/ActivityFeed.vue`)
**Current behavior:** Button-style filter tabs (same visual as page tabs). Empty state: "No activity yet."
**Proposed behavior:** `BaseFilterChips` (pill style, visually distinct from page tabs). `BaseEmptyState` with clock icon and descriptive copy.
**Interface changes:** None.
**Data model changes:** None.

#### AdminProjectList (`resources/js/components/AdminProjectList.vue`)
**Current behavior:** Stacked cards (`space-y-3`) with `rounded-lg border p-4`. Buttons use mixed radii and colors. Empty state: plain text.
**Proposed behavior:** On `lg:` breakpoint, switch to a dense row layout using responsive CSS grid (`grid-cols-[1fr_auto_auto_auto_auto]`). Cards collapse to stacked on smaller screens. All buttons via `BaseButton`, status via `BaseBadge`, empty state via `BaseEmptyState`.
**Interface changes:** None.
**Data model changes:** None.

#### Remaining 19 Components
Mechanical refactor: replace inline Tailwind patterns with base component equivalents. Specific mappings:
- `rounded-lg border border-zinc-200 bg-white p-4` → `<BaseCard>`
- `rounded-full px-2 py-0.5 text-xs font-medium bg-*` → `<BaseBadge variant="*">`
- `rounded-lg bg-* px-3 py-1.5 text-sm font-medium` → `<BaseButton variant="*" size="sm">`
- `animate-spin h-5 w-5` SVG spinners → `<BaseSpinner size="md">`
- Empty state `<div class="text-center text-zinc-400">` → `<BaseEmptyState>`

### Dependencies
- **Requires:** Nothing external. All infrastructure exists (Vue SPA, Tailwind, Pinia, 97% test coverage). The backend API extension for trend data is optional scope.
- **Unblocks:** Faster dashboard view development (T75–T82), admin page refinement (T88–T93), frontend review skill quality (T21), designer iteration consistency (T72), mobile responsiveness (D135).

### Risk Mitigation

| Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|
| Large blast radius (~111 files) | Regression in UI rendering | Med | 97% test coverage + 5-batch migration (each independently shippable) |
| Test churn (41 test files) | Slow review, mechanical errors | High | Tests updated in same task as component; run full suite after each task |
| Base component over-engineering | Unused props, premature abstraction | Low | Strict 7-primitive cap; no additions until consumers demand them |
| Merge conflicts with parallel work | Blocked integration | Low | Use git worktree for isolation; rebase frequently |
| Backend trend-data scope creep | Delays the frontend refactor | Low | Backend API extension is the final task; if complex, defer and ship without trend deltas |

### Rollback Plan
- **No database changes** — no migration rollback needed
- **No feature flags needed** — each batch is a self-contained set of component changes
- **Git revert scope:** Each batch is 1 commit (or a small set of commits). Revert the batch commit(s) to restore previous state.
- **No data recovery needed** — purely presentational changes

### Tasks

#### T189: Create design token system in app.css
**File(s):** `resources/css/app.css`
**Action:** Extend the existing `@theme` block with ~30 CSS custom properties: spacing tokens (--spacing-page, --spacing-section, --spacing-card, --spacing-card-lg), radii tokens (--radius-card 0.75rem, --radius-button 0.5rem, --radius-input 0.625rem, --radius-bubble 1.25rem, --radius-badge 9999px), shadow tokens (--shadow-card, --shadow-card-hover, --shadow-dropdown, --shadow-nav), and content width tokens (--width-content 80rem, --width-content-narrow 64rem, --width-sidebar 20rem). Add `.chat-bubble .markdown-content` typography overrides (leading-[1.75], my-3 paragraphs, my-1 list items, p-4 code blocks).
**Verification:** `npm run build` succeeds. `npm run dev` renders existing pages without visual breakage. Tokens are accessible via `var(--token-name)` in browser DevTools.

#### T190: Create BaseSpinner component
**File(s):** `resources/js/components/ui/BaseSpinner.vue`, `resources/js/components/ui/__tests__/BaseSpinner.test.ts`
**Action:** Extract the SVG spinner (duplicated in 7 components) into BaseSpinner with `size` prop ('sm'|'md'|'lg' → h-4 w-4, h-5 w-5, h-8 w-8). Write Vitest tests covering all size variants and default rendering.
**Verification:** `npm test -- BaseSpinner` passes. Component renders correctly in isolation.

#### T191: Create BaseBadge component
**File(s):** `resources/js/components/ui/BaseBadge.vue`, `resources/js/components/ui/__tests__/BaseBadge.test.ts`
**Action:** Create BaseBadge with `variant` prop ('neutral'|'success'|'warning'|'danger'|'info'). Pill shape (`rounded-[--radius-badge]`), `px-2 py-0.5 text-xs font-medium leading-tight`, slot for content. Each variant maps to a specific bg/text color pair for light and dark mode. Standardize on emerald for success (not green).
**Verification:** `npm test -- BaseBadge` passes. All 5 variants render correct colors.

#### T192: Create BaseButton component
**File(s):** `resources/js/components/ui/BaseButton.vue`, `resources/js/components/ui/__tests__/BaseButton.test.ts`
**Action:** Create BaseButton with `variant` ('primary'|'secondary'|'ghost'|'danger'), `size` ('sm'|'md'|'lg'), `loading` (bool), `disabled` (bool), `type` ('button'|'submit'). Unified radius (`--radius-button`), built-in BaseSpinner for loading state, consistent focus ring (`focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2`), active scale (`active:scale-[0.98]`), disabled state (`opacity-50 cursor-not-allowed`). Slot for content.
**Verification:** `npm test -- BaseButton` passes. Loading state shows spinner. Disabled state prevents clicks.

#### T193: Create BaseCard component
**File(s):** `resources/js/components/ui/BaseCard.vue`, `resources/js/components/ui/__tests__/BaseCard.test.ts`
**Action:** Create BaseCard with `padded` (bool, default true), `hoverable` (bool, default false), `variant` ('default'|'success'|'danger'). Radius `--radius-card`, shadow `--shadow-card`, border `border-zinc-200 dark:border-zinc-700`, bg `white dark:bg-zinc-900`. Hoverable adds `transition-shadow hover:shadow-[--shadow-card-hover]`. Success/danger variants change border color to emerald/red.
**Verification:** `npm test -- BaseCard` passes. Hover shadow lift visible in dev.

#### T194: Create BaseEmptyState component
**File(s):** `resources/js/components/ui/BaseEmptyState.vue`, `resources/js/components/ui/__tests__/BaseEmptyState.test.ts`
**Action:** Create BaseEmptyState with named slots: `icon`, `title`, `description`, `action`. Layout: centered flex-col, `py-16 px-4`, icon slot inside `w-12 h-12 rounded-full bg-zinc-100 dark:bg-zinc-800`, title as `text-sm font-medium text-zinc-900`, description as `text-sm text-zinc-500 max-w-sm`, action slot below with `mb-4` spacing.
**Verification:** `npm test -- BaseEmptyState` passes. Renders all slots correctly, renders gracefully with missing optional slots.

#### T195: Create BaseTabGroup component
**File(s):** `resources/js/components/ui/BaseTabGroup.vue`, `resources/js/components/ui/__tests__/BaseTabGroup.test.ts`
**Action:** Create BaseTabGroup with `tabs` (Array<{key: string, label: string}>), `modelValue` (string), emits `update:modelValue`. Underline style: `border-b border-zinc-200`, tabs as `px-4 py-2.5 text-sm font-medium -mb-px border-b-2`, active state `border-zinc-900 text-zinc-900`, inactive `border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300`. Horizontal scroll overflow with `overflow-x-auto scrollbar-width-none`.
**Verification:** `npm test -- BaseTabGroup` passes. v-model two-way binding works. Overflow scrolls without scrollbar.

#### T196: Create BaseFilterChips component
**File(s):** `resources/js/components/ui/BaseFilterChips.vue`, `resources/js/components/ui/__tests__/BaseFilterChips.test.ts`
**Action:** Create BaseFilterChips with `chips` (Array<{label: string, value: string|null}>), `modelValue` (string|null), emits `update:modelValue`. Pill style: `px-3 py-1 text-xs font-medium rounded-full`, active `bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900`, inactive `bg-zinc-100 dark:bg-zinc-800 text-zinc-600 hover:bg-zinc-200`. Wrap with `flex flex-wrap gap-1.5`.
**Verification:** `npm test -- BaseFilterChips` passes. v-model two-way binding works.

#### T197: Refactor App.vue layout shell
**File(s):** `resources/js/App.vue`, `resources/js/components/AppNavigation.vue`, tests for both
**Action:** In App.vue: change `<main class="flex-1 p-4 lg:p-8">` to `<main class="flex-1">`. In AppNavigation.vue: add `sticky top-0 z-30`, add `shadow-[var(--shadow-nav)]` to `<nav>`, render `link.icon` emoji in desktop nav links, change active state from `bg-zinc-100` to `border-b-2 border-zinc-900 dark:border-zinc-100` underline. Update tests for new structure.
**Verification:** `npm test -- App` and `npm test -- AppNavigation` pass. Nav is sticky in browser. Active link shows underline.

#### T198: Refactor DashboardPage and AdminPage layout + tabs
**File(s):** `resources/js/pages/DashboardPage.vue`, `resources/js/pages/AdminPage.vue`, tests for both
**Action:** Wrap page content in `<div class="max-w-[var(--width-content)] mx-auto px-6 lg:px-8 py-6">`. Replace inline button-style tab loops with `<BaseTabGroup :tabs="views" v-model="activeView" />`. Separate header zone (title + tabs) from content zone (padded below). Update tests.
**Verification:** `npm test -- DashboardPage` and `npm test -- AdminPage` pass. Content is contained at 1280px in browser. Tabs show underline style.

#### T199: Refactor DashboardOverview with BaseCard and empty states
**File(s):** `resources/js/components/DashboardOverview.vue`, `resources/js/stores/dashboard.ts`, tests for both
**Action:** Replace hardcoded card markup with `<BaseCard>`. Add `isAllZeros` computed to dashboard store (`overview.active_tasks === 0 && overview.total_completed + overview.total_failed === 0`). Implement three-state empty model: null → error retry via BaseEmptyState, all-zeros → onboarding CTAs ("Enable a project" → /admin, "Start a conversation" → /chat), data → normal render with BaseCard. Replace inline spinners with `<BaseSpinner>`. Update tests for all three states.
**Verification:** `npm test -- DashboardOverview` and `npm test -- dashboard.test` pass. All three empty states render correctly.

#### T200: Refactor ActivityFeed with BaseFilterChips and BaseEmptyState
**File(s):** `resources/js/components/ActivityFeed.vue`, test file
**Action:** Replace button-style filter tabs with `<BaseFilterChips :chips="tabs" v-model="dashboard.activeFilter" />`. Replace empty state with `<BaseEmptyState>` (clock icon, "No activity yet", descriptive copy). Replace inline spinner with `<BaseSpinner>`. Update tests.
**Verification:** `npm test -- ActivityFeed` passes. Filter chips are visually distinct from page tabs.

#### T201: Refactor MessageBubble typography and width
**File(s):** `resources/js/components/MessageBubble.vue`, test file
**Action:** User bubble: change `max-w-[80%]` to `max-w-md`, keep `px-4 py-3 text-sm leading-relaxed`. Assistant bubble: change `max-w-[80%]` to `max-w-2xl`, change `px-4 py-3` to `px-5 py-4`, wrap MarkdownContent in `<div class="chat-bubble">` for scoped typography. Change `rounded-br-md` to `rounded-br-sm`, `rounded-bl-md` to `rounded-bl-sm`. Timestamp: change `mt-1 text-xs` to `px-4 pb-2 text-[11px]`. Update radii to use `rounded-[var(--radius-bubble)]`. Update tests.
**Verification:** `npm test -- MessageBubble` passes. Assistant messages are 672px max in browser. Line height is visibly more spacious.

#### T202: Refactor MessageThread layout and error positioning
**File(s):** `resources/js/components/MessageThread.vue`, test file
**Action:** Change message column from `space-y-3 max-w-3xl` to `space-y-4 max-w-4xl mx-auto py-6`. Move error banners (retryable + terminal) from inside `scrollContainer` to a fixed position between the scroll area and composer (outside the scrollable div). Align streaming bubble styles with MessageBubble: `max-w-2xl`, `rounded-[var(--radius-bubble)] rounded-bl-sm`, `px-5 py-4`, `.chat-bubble` wrapper. Replace inline spinner with `<BaseSpinner>`. Update tests.
**Verification:** `npm test -- MessageThread` passes. Error banners don't scroll with messages.

#### T203: Refactor ConversationList and ConversationListItem
**File(s):** `resources/js/components/ConversationList.vue`, `resources/js/components/ConversationListItem.vue`, tests for both
**Action:** In ConversationList: replace "New Conversation" button with `<BaseButton variant="primary" size="md">`, replace empty states with `<BaseEmptyState>`, replace spinner with `<BaseSpinner>`. In ConversationListItem: move project badge to its own line using `<BaseBadge variant="neutral">`, change selected state to `bg-zinc-50 dark:bg-zinc-800/80 border-l-2 border-l-blue-500`. Update tests.
**Verification:** `npm test -- ConversationList` and `npm test -- ConversationListItem` pass. Selected conversation shows blue left border.

#### T204: Refactor AdminProjectList with BaseCard, BaseBadge, BaseButton
**File(s):** `resources/js/components/AdminProjectList.vue`, test file
**Action:** Replace inline card markup with `<BaseCard>`. Replace status badges with `<BaseBadge variant="success">Enabled</BaseBadge>` / `<BaseBadge variant="neutral">Disabled</BaseBadge>`. Replace webhook badge with `<BaseBadge variant="info">`. Replace all buttons with `<BaseButton>` (Enable → primary, Configure → secondary, Disable → secondary). Replace empty state with `<BaseEmptyState>` (building icon, descriptive copy, link to GitLab OAuth docs). Add responsive grid layout on `lg:` breakpoint. Update tests.
**Verification:** `npm test -- AdminProjectList` passes. Dense row layout visible on wide screens, cards on narrow.

#### T205: Migrate remaining components to base primitives
**File(s):** All remaining components in `resources/js/components/` not covered by T197–T204: ResultCard, ActionPreviewCard, PinnedTaskBar, ToolUseIndicators, TypingIndicator, MarkdownContent, NewConversationDialog, MessageComposer, ActivityFeedItem, DashboardQuality, DashboardCost, DashboardEfficiency, DashboardAdoption, DashboardPMActivity, DashboardDesignerActivity, DashboardInfrastructure, AdminRoleList, AdminRoleAssignments, AdminGlobalSettings, AdminDeadLetterQueue, AdminProjectConfig, AdminPrdTemplate + all test files
**Action:** Mechanical replacement pass: inline card patterns → `<BaseCard>`, inline badges → `<BaseBadge>`, inline buttons → `<BaseButton>`, inline spinners → `<BaseSpinner>`, inline empty states → `<BaseEmptyState>`. Unify all focus rings to `focus-visible:ring-2 focus-visible:ring-blue-500`. Remove all raw `rounded-md`, `rounded-lg`, `rounded-xl` on cards/buttons and replace with token-backed equivalents. Update all corresponding test files.
**Verification:** `npm test` (full Vitest suite) passes with 0 failures. `npm run typecheck` passes. `npm run lint` passes.

#### T206: Extend Dashboard overview API with trend data (optional)
**File(s):** `app/Http/Controllers/Api/DashboardOverviewController.php` (or equivalent), `app/Http/Resources/DashboardOverviewResource.php` (or equivalent), PHP test files
**Action:** Add optional response fields: `tasks_by_type_7d` (count of tasks per type in the last 7 days), `last_activity_description` (human-readable string like "Code Review on acme/frontend"), `active_task_breakdown` ({queued: int, running: int}). All fields are additive — existing consumers are unaffected. If implementation is complex (requires new queries or materialized view changes), defer this task and ship the frontend without trend deltas.
**Verification:** `php artisan test --filter=DashboardOverview` passes. API response includes new fields. Frontend DashboardOverview gracefully handles missing fields.

#### T207: Run full verification and update documentation
**File(s):** `CLAUDE.md`, `docs/reference/spec/decisions-index.md`
**Action:** Run `npm test`, `npm run typecheck`, `npm run lint`, `npm run build`. Verify all pass with 0 errors. Add D189–D193 to decisions-index.md. Update CLAUDE.md if any new commands, conventions, or learnings emerge from the refactor.
**Verification:** All CI-equivalent checks pass locally. Decisions index includes D189–D193. CLAUDE.md is current.

### Verification
- [ ] All 7 base components (`ui/Base*.vue`) exist with passing tests
- [ ] Design tokens in `@theme` are accessible and used by base components
- [ ] AppNavigation is sticky, shows icons, uses underline active state
- [ ] Dashboard and Admin pages have max-width content containment (1280px)
- [ ] Page tabs (Dashboard, Admin) use underline style (BaseTabGroup)
- [ ] Activity feed filters use pill style (BaseFilterChips) — visually distinct from page tabs
- [ ] Chat message bubbles have width constraints (assistant ≤672px, user ≤448px)
- [ ] Chat typography uses `leading-[1.75]` with `my-3` paragraph spacing
- [ ] Dashboard overview shows onboarding CTA when all counts are zero
- [ ] All empty states across the app use BaseEmptyState with icon + title + description + action
- [ ] No raw `rounded-md/lg/xl` on cards or buttons — all use design tokens
- [ ] All focus rings are `focus-visible:ring-2 focus-visible:ring-blue-500`
- [ ] `npm test` passes with 0 failures
- [ ] `npm run typecheck` passes with 0 errors
- [ ] `npm run lint` passes with 0 violations
- [ ] `npm run build` succeeds
- [ ] All existing tests still pass (no regressions)

# Decision Index

> One-line summary of every architectural decision.
> Updated whenever new decisions are made — during planning, implementation, or bug fixing.

| # | Summary | Source | Status |
|---|---|---|---|
| D1 | GitLab plan — Free, permanently | vunnix-v1 | Active |
| D2 | GitLab version — v18.8+ with auto-update | vunnix-v1 | Active |
| D3 | Target audience — PM, Designer, Engineer, Manager (cross-team) | vunnix-v1 | Active |
| D4 | PM entry point — Vunnix chat UI (conversational) | vunnix-v1 | Active |
| D5 | Designer entry point — Vunnix chat UI (conversational) | vunnix-v1 | Active |
| D6 | Engineer entry point — GitLab (webhooks) | vunnix-v1 | Active |
| D7 | Authentication — GitLab OAuth | vunnix-v1 | Active |
| D8 | PRD refinement — Conversation only, no editor UI | vunnix-v1 | Active |
| D9 | Conversation persistence — Threads survive across sessions | vunnix-v1 | Active |
| D10 | AI role — Neutral quality gate, depersonalizes pushback | vunnix-v1 | Active |
| D11 | Artifact storage — GitLab only (Issues, MRs, Wiki) | vunnix-v1 | Active |
| D12 | Action dispatch — Permission-based, any role | vunnix-v1 | Active |
| D13 | CE + GitLab access — AI has tool-use to browse repos in chat | vunnix-v1 | Active |
| D14 | Real-time updates — SSE for chat, Reverb for dashboard | vunnix-v1 | Active |
| D15 | Diagrams format — Mermaid (not ASCII) | vunnix-v1 | Active |
| D16 | Network — Bidirectional firewall between GitLab and Vunnix | vunnix-v1 | Active |
| D17 | §3 depth — High-level interfaces; details in specialized sections | vunnix-v1 | Active |
| D18 | Auth model — RBAC, admin-configurable | vunnix-v1 | Active |
| D19 | CE tool-use detail — Described in §14, not §3 | vunnix-v1 | Active |
| D20 | Execution mode — All-CLI, full codebase access via claude CLI | vunnix-v1 | Active |
| D21 | Execution on GitLab Runner — CI pipelines execute claude CLI | vunnix-v1 | Active |
| D22 | Executor as Docker image — Skills/MCP/scripts packaged in image | vunnix-v1 | Active |
| D23 | Three-layer intelligence — Vunnix → Executor → Project config | vunnix-v1 | Active |
| D24 | Conversation Engine — Stays in Vunnix, not via runner | vunnix-v1 | Active |
| D25 | Results flow — API (primary) + CI artifact (debug log) | vunnix-v1 | Active |
| D26 | Webhook auto-configuration — Auto-configure on project enable | vunnix-v1 | Active |
| D27 | CI pipeline location — Runs in project's own CI/CD | vunnix-v1 | Active |
| D28 | Cross-project conversations — Single chat spans multiple projects | vunnix-v1 | Active |
| D29 | Dashboard visibility — Project-scoped, cost data admin-only | vunnix-v1 | Active |
| D30 | GitLab bot account — Dedicated bot posts all AI comments | vunnix-v1 | Active |
| D31 | Issue authorship — Bot creates Issue, PM as assignee | vunnix-v1 | Active |
| D32 | Failed execution — Auto-retry, then failure comment on MR | vunnix-v1 | Active |
| D33 | Incremental review — Same finding = same thread, no duplicates | vunnix-v1 | Active |
| D34 | Deep analysis timeout — No soft timeout, 20-min CI hard limit | vunnix-v1 | Active |
| D35 | Designer iteration — Chat → push commits → engineer reviews | vunnix-v1 | Active |
| D36 | Designer visual verification — Screenshot capture in v1 (D131) | vunnix-v1 | Active |
| D37 | Chat rendering — Rich markdown + syntax highlighting via SSE | vunnix-v1 | Active |
| D38 | Tool-use indicators — Real-time for CE, status-only for TE | vunnix-v1 | Active |
| D39 | Action confirmation — Structured preview card with Confirm/Cancel | vunnix-v1 | Active |
| D40 | Non-blocking task dispatch — Continue chatting while task runs | vunnix-v1 | Active |
| D41 | PRD template — Standardized, configurable, progressively filled | vunnix-v1 | Active |
| D42 | Incremental review summary — Update original comment in-place | vunnix-v1 | Active |
| D43 | Error presentation — AI explains conversationally with alternatives | vunnix-v1 | Active |
| D44 | Per-action card content — Different fields per action type | vunnix-v1 | Active |
| D45 | Dashboard UX scope — Stays in §5, not §4 | vunnix-v1 | Active |
| D46 | §5 scope — Expanded to "Vunnix Web Application" (full SPA) | vunnix-v1 | Active |
| D47 | Application layout — Separate pages: Chat, Dashboard, Admin | vunnix-v1 | Active |
| D48 | Metrics scope — All roles (engineering + PM + Designer + cost) | vunnix-v1 | Active |
| D49 | Conversation list — Flat chronological, filterable, searchable | vunnix-v1 | Active |
| D50 | Activity feed — Single feed with filter tabs | vunnix-v1 | Active |
| D51 | Conversation visibility — Visible to all project members | vunnix-v1 | Active |
| D52 | New conversation flow — Select primary project first | vunnix-v1 | Active |
| D53 | Non-blocking result delivery — Result card appears silently | vunnix-v1 | Active |
| D54 | Cross-project visibility — Members of ANY project see full convo | vunnix-v1 | Active |
| D55 | Deep analysis — No preview card for read-only CLI dispatches | vunnix-v1 | Active |
| D56 | Incremental review labels — Labels reflect latest review state | vunnix-v1 | Active |
| D57 | PRD template customization — RBAC-controlled permission | vunnix-v1 | Active |
| D58 | Activity feed "All" tab — Shows all activity types | vunnix-v1 | Active |
| D59 | Tool-use failure handling — AI silently handles, user-friendly msg | vunnix-v1 | Active |
| D60 | Disabled project data — Remains visible in read-only mode | vunnix-v1 | Active |
| D61 | Immediate action result — Brief AI message with result card | vunnix-v1 | Active |
| D62 | Conversation title — Auto-generated from first message | vunnix-v1 | Active |
| D63 | GitLab API client — Raw Laravel HTTP Client (no library) | vunnix-v1 | Active |
| D64 | ~~Claude API client~~ — Superseded by D73 (Laravel AI SDK) | vunnix-v1 | Superseded |
| D65 | ~~Executor image registry — GitLab Container Registry~~ — Superseded by D163 | vunnix-v1 | Superseded |
| D66 | Frontend state management — Pinia | vunnix-v1 | Active |
| D67 | §6 structure — Grouped subsections (9 with tables) | vunnix-v1 | Active |
| D68 | Labels format — GitLab `::` scoped labels | vunnix-v1 | Active |
| D69 | Application server — FrankenPHP (replaces Nginx) | vunnix-v1 | Active |
| D70 | Dashboard view access — Permission-controlled via RBAC | vunnix-v1 | Active |
| D71 | Code quality tools — eslint, PHPStan, stylelint (not MCP) | vunnix-v1 | Active |
| D72 | Database — PostgreSQL (pgvector deferred, D145) | vunnix-v1 | Active |
| D73 | CE AI client — Laravel AI SDK (replaces D64) | vunnix-v1 | Active |
| D74 | Multi-provider AI — Built into AI SDK, Claude primary | vunnix-v1 | Active |
| D75 | Laravel MCP — Noted for future, not v1 | vunnix-v1 | Active |
| D76 | Laravel Boost — Dev dependency for AI-assisted development | vunnix-v1 | Active |
| D77 | Section order — Business → Technical → Roadmap + Testing | vunnix-v1 | Active |
| D78 | MVP scope — Full platform, both paths, no feature cuts | vunnix-v1 | Active |
| D79 | Timeline — No hard deadline, quality over speed | vunnix-v1 | Active |
| D80 | MR volume baseline — ~100–200 MRs/month | vunnix-v1 | Active |
| D81 | ROI model — No justification needed, budget pre-approved | vunnix-v1 | Active |
| D82 | Launch definition — Deployed + 1 pilot project with real usage | vunnix-v1 | Active |
| D83 | Success framework — OKRs, not KPIs | vunnix-v1 | Active |
| D84 | §7 title — "Business Goals & OKRs" (from KPIs) | vunnix-v1 | Active |
| D85 | §8 structure — Lean, references earlier sections | vunnix-v1 | Active |
| D86 | Pilot project — Pre-selected, not specified in doc | vunnix-v1 | Active |
| D87 | Budget enforcement — Soft cap, alert at 2× average, never block | vunnix-v1 | Active |
| D88 | Hosting — Cloud (AWS/GCP), not on-premise | vunnix-v1 | Active |
| D89 | Cost spike response — Alert-only, no task termination | vunnix-v1 | Active |
| D90 | Cost estimates — Included with Feb 2026 pricing date | vunnix-v1 | Active |
| D91 | Model for all tasks — Opus 4.6 for everything (no tiering) | vunnix-v1 | Active |
| D92 | Onboarding — Live demo per role + self-serve docs | vunnix-v1 | Active |
| D93 | Rollout pace — 3–5 projects per wave, 1–2 weeks between | vunnix-v1 | Active |
| D94 | Rollback — Disable per project + structured post-mortem | vunnix-v1 | Active |
| D95 | Data residency — api.anthropic.com direct, global routing | vunnix-v1 | Active |
| D96 | Data retention — Indefinite, all data kept forever | vunnix-v1 | Active |
| D97 | Secrets in diffs — AI flags as critical, no pre-scan | vunnix-v1 | Active |
| D98 | Audit trail — Full content, complete prompts and responses | vunnix-v1 | Active |
| D99 | AI review role — Mandatory first gate before human review | vunnix-v1 | Active |
| D100 | AI-created MR approval — AI self-reviews, then human approval | vunnix-v1 | Active |
| D101 | Code liability — Team-level, no individual blame | vunnix-v1 | Active |
| D102 | Language config — Global setting with user-language fallback | vunnix-v1 | Active |
| D103 | Prompt versioning — Version-tracked skills, rollback via git | vunnix-v1 | Active |
| D104 | Prompt injection defense — Prompt-level + architectural layers | vunnix-v1 | Active |
| D105 | Extended thinking — Always on for Task Executor, off for CE | vunnix-v1 | Active |
| D106 | Token budgets — Soft guidance only, no hard limits | vunnix-v1 | Active |
| D107 | Model reference — Single `opus` alias, auto-updates on release | vunnix-v1 | Active |
| D108 | API outage — Queue with 2h expiry + latest wins | vunnix-v1 | Active |
| D109 | Review latency — No hard target, quality over speed | vunnix-v1 | Active |
| D110 | Admin alerting — Dashboard + team chat (webhook-based) | vunnix-v1 | Active |
| D111 | Engineer feedback — GitLab emoji reactions (👍/👎) | vunnix-v1 | Active |
| D112 | Prompt review cadence — Metric-triggered only, no fixed schedule | vunnix-v1 | Active |
| D113 | Over-reliance response — Alert at >95% acceptance + spot-checks | vunnix-v1 | Active |
| D114 | API scope — Internal + documented, API key auth for automation | vunnix-v1 | Active |
| D115 | CLI tool — No CLI in v1, deferred to post-launch | vunnix-v1 | Active |
| D116 | Webhook output — Team chat only (D110), no generic webhooks | vunnix-v1 | Active |
| D117 | Queue priority — FIFO within priority level, no project weighting | vunnix-v1 | Active |
| D118 | Per-project rate limits — None, runner capacity is throttle | vunnix-v1 | Active |
| D119 | Horizontal scaling — Future option, single VM for v1 | vunnix-v1 | Active |
| D120 | Team chat notifications — Admin alerts + task completions | vunnix-v1 | Active |
| D121 | Email digests — No email in v1, deferred | vunnix-v1 | Active |
| D122 | External PM tools — Not planned (GitLab Issues only) | vunnix-v1 | Active |
| D123 | Task execution mode — Runner for CLI, server-side for API calls | vunnix-v1 | Active |
| D124 | §21 task granularity — 116 tasks, milestone-grouped, T-numbered | vunnix-v1 | Active |
| D125 | §22 test strategy — Pest + Vitest + AI SDK fakes + Http::fake() | vunnix-v1 | Active |
| D126 | CLI/SDK alignment — Shared rules, build-time drift check | vunnix-v1 | Active |
| D127 | Scheduling vs execution timeout — Separate phases, token TTL | vunnix-v1 | Active |
| D128 | Cross-project visibility warning — Confirmation dialog on add | vunnix-v1 | Active |
| D129 | Bot membership pre-check — Verify Maintainer role on enable | vunnix-v1 | Active |
| D130 | Processing state — Brief "Processing…" for server-side actions | vunnix-v1 | Active |
| D131 | Screenshot capture — Playwright in executor, base64 in result | vunnix-v1 | Active |
| D132 | Deep analysis mode — CE suggests runner dispatch for complex Qs | vunnix-v1 | Active |
| D133 | Runner load awareness — Pipeline status in chat UI | vunnix-v1 | Active |
| D134 | Server-side queue isolation — Separate Redis queues | vunnix-v1 | Active |
| D135 | Responsive web design — Desktop-first with breakpoints | vunnix-v1 | Active |
| D136 | Embedding pipeline + RAG — Deferred to post-v1 (D145) | vunnix-v1 | Active |
| D137 | Config file naming — `.vunnix.toml` (not `.ai-orchestrator.toml`) | vunnix-v1 | Active |
| D138 | Markdown rendering — markdown-it + @shikijs/markdown-it | vunnix-v1 | Active |
| D139 | E2E testing — Pest browser testing (Playwright) | vunnix-v1 | Active |
| D140 | Push-during-active-review — Latest-wins superseding | vunnix-v1 | Active |
| D141 | Conversation archiving — Archive/unarchive, hidden from list | vunnix-v1 | Active |
| D142 | AI-generated conversation titles — On first AI response | vunnix-v1 | Active |
| D143 | Visibility after access revocation — Not retroactively hidden | vunnix-v1 | Active |
| D144 | Bot PAT rotation reminder — Automated alert at 5.5 months | vunnix-v1 | Active |
| D145 | RAG/pgvector — Deferred to post-v1, keyword search for now | vunnix-v1 | Active |
| D146 | OAuth session — 7-day lifetime, transparent token refresh | vunnix-v1 | Active |
| D147 | Periodic membership re-validation — Cached 15 min per request | vunnix-v1 | Active |
| D148 | Conversation keyword search — PostgreSQL FTS on title + content | vunnix-v1 | Active |
| D149 | Webhook-driven acceptance tracking — Event-driven, no polling | vunnix-v1 | Active |
| D150 | ~~Executor image registry access — Vunnix project internal/public~~ — Superseded by D163 | vunnix-v1 | Superseded |
| D151 | GitLab OAuth scopes — read_user + read_api | vunnix-v1 | Active |
| D152 | API key hashing — SHA-256, shown once at creation | vunnix-v1 | Active |
| D153 | Anthropic API key storage — .env only, never in database | vunnix-v1 | Active |
| D154 | Bot event filtering — Bot note events discarded, MR events kept | vunnix-v1 | Active |
| D155 | @ai command fallback — Help response for unrecognized commands | vunnix-v1 | Active |
| D156 | Project enablement auto-creates CI trigger token (extends D26) | impl | Active |
| D157 | Push events ignored when MR exists — MR update event handles review | impl | Active |
| D158 | Trust all proxies — required for reverse proxy/tunnel deployments | impl | Active |
| D159 | SPA authenticates via session cookies, CSRF excluded for API routes | impl | Active |
| D160 | Database backup — pg_dump -Z 9, 30-day retention, stored in storage/backups/ | impl | Active |
| D161 | Executor turn limit — --max-turns 30 per CLI invocation | impl | Active |
| D162 | Vunnix development hosted on GitHub (public) — unlimited free CI via GitHub Actions | ext-001 | Active |
| D163 | Executor image registry — public GHCR at `ghcr.io/bepsvpt/vunnix/executor`, no auth needed (supersedes D65, D150) | ext-001 | Active |
| D164 | CI template distributed via `include: remote:` URL from GitHub raw content | ext-001 | Active |
| D165 | PHP minimum version `^8.5` — targeting PHP 8.5 in Docker + dev | ext-002 | Active |
| D166 | PostgreSQL 18 for development and production — PG 18.2 with async I/O | ext-002 | Active |
| D167 | Redis 8 for cache/session/queue — tri-licensed, acceptable for self-hosted | ext-002 | Active |
| D168 | Node 24 LTS for executor image — Active LTS (EOL Apr 2028) | ext-002 | Active |
| D169 | Pest 4 + PHPUnit 12 for test suite — enables test sharding, requires PHP 8.3+ | ext-002 | Active |
| D170 | App image in public GHCR at `ghcr.io/bepsvpt/vunnix/app` — same registry and auth model as executor (D163) | ext-003 | Active |
| D171 | Multi-stage Docker build for frontend assets — Node 24 build stage, only compiled `public/assets/` in final image | ext-003 | Active |
| D172 | CI tests run against PostgreSQL 18 service container — workflow env vars override phpunit.xml via `<env force="false">` | ext-004 | Active |
| D173 | Use `shivammathur/setup-php@v2` for PHP 8.5 in CI — confirmed supported since v2.36.0 | ext-004 | Active |
| D174 | Use Larastan 3.x (not standalone PHPStan) for dev static analysis — Laravel-aware stubs | ext-005 | Active |
| D175 | Target PHPStan level 8 (not max/10) — levels 9-10 mixed-type strictness excessive for Laravel | ext-005 | Active |
| D176 | PHPStan CI runs as separate job without database services — static analysis needs no DB | ext-005 | Active |
| D177 | Laravel Pint `laravel` preset + strict_comparison, void_return, ordered_class_elements, PHPDoc cleanup | ext-006 | Active |
| D178 | Claude Code PostToolUse hook auto-formats PHP files on Edit/Write via Pint | ext-006 | Active |
| D179 | Pint CI runs as separate lightweight job using `--test` dry-run mode | ext-006 | Active |
| D180 | Use @antfu/eslint-config with ESLint Stylistic for JS/Vue linting + formatting (replaces ESLint + Prettier) | ext-007 | Active |
| D181 | Stylistic overrides: semicolons on, 4-space indent, single quotes — matching existing codebase + .editorconfig | ext-007 | Active |
| D182 | ESLint CI runs as separate lightweight job — Node.js only, no DB services | ext-007 | Active |
| D183 | TypeScript strict mode for Vue 3 frontend — `strict: true` from day one, all new frontend code fully typed | ext-008 | Active |
| D184 | Zod schemas as single source of truth for API response types — `z.infer<>` for static types, `.parse()` for runtime validation | ext-008 | Active |
| D185 | Ban `any` via ESLint `ts/no-explicit-any: error` — forces `unknown` + type guards, no escape hatches | ext-008 | Active |
| D186 | `vue-tsc` type checking in CI — separate lightweight job (Node.js only, no DB services), consistent with D176/D182 | ext-008 | Active |
| D187 | Structured SSE error event for AI provider failures during streaming — emits `{"type":"error","error":{...}}` when rate limited or overloaded mid-stream | ext-009 | Active |
| D188 | Client-side recovery for streaming errors — frontend shows retryable/terminal error banners, refetches persisted messages via REST API | ext-009 | Active |
| D189 | Design token system via Tailwind `@theme` CSS custom properties — tokens for radii, shadows, and content widths enforce consistency without a build step | ext-010 | Active |
| D190 | 3-tier navigation visual hierarchy — navbar underline (heaviest), page underline tabs BaseTabGroup (medium), inline pill-style BaseFilterChips (lightest) | ext-010 | Active |
| D191 | Chat message bubble width constraints — assistant `max-w-2xl` (672px), user `max-w-md` (448px), `leading-[1.75]` + `my-3` paragraph spacing via `.chat-bubble` | ext-010 | Active |
| D192 | Three-state empty model for Dashboard — error → retry CTA, all-zeros → onboarding CTAs, data present → normal render | ext-010 | Active |
| D193 | Base UI component library at `components/ui/` — 7 primitives (BaseCard, BaseBadge, BaseButton, BaseTabGroup, BaseFilterChips, BaseEmptyState, BaseSpinner) with typed props and design-token-backed styling | ext-010 | Active |

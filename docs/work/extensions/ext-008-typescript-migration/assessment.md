# Assessment: Frontend TypeScript Migration

**Date:** 2026-02-17
**Requested by:** Kevin
**Trigger:** Long-term maintainability concern for the Vue 3 frontend

## What

Migrate the entire Vue 3 frontend from JavaScript to TypeScript in a single big-bang conversion. Add strict type safety across all components, stores, composables, and utilities. Use Zod schemas as the single source of truth for API response types, providing both compile-time type inference and runtime validation.

## Classification

**Tier:** 3 (Architectural)
**Rationale:** Touches 131 files (88 source + 43 tests) across all frontend modules. Requires new tooling infrastructure (`tsconfig.json`, Zod, ESLint TypeScript rules). Changes how all future frontend code is written. Introduces new patterns (Zod schemas, typed props, typed stores).

**Modifiers:**
- [ ] `breaking` — No runtime behavior changes; TypeScript is a strict superset of JavaScript
- [ ] `multi-repo` — Single repository only
- [ ] `spike-required` — Well-understood technology with established Vue 3 patterns
- [ ] `deprecation` — Not removing any capability
- [x] `migration` — Code migration from JS to TS across all frontend files

## Decisions Made

| # | Decision | Choice | Rationale |
|---|---|---|---|
| 1 | Strictness level | `strict: true` from day one | Catches more bugs, no second pass needed |
| 2 | Migration strategy | Big-bang (all files in one extension) | Clean cut, no mixed JS/TS period |
| 3 | API response types | Zod schemas (`z.infer<>` for static types + `.parse()` for runtime validation) | Single source of truth; catches backend drift at runtime in dev |
| 4 | `any` policy | Ban via ESLint (`@typescript-eslint/no-explicit-any: error`) | Forces proper typing; use `unknown` + type guards instead |

## Impact Analysis

### Components Affected

| Component | Impact | Files (est.) |
|---|---|---|
| **Build config** | Add `tsconfig.json`, update vite/vitest/eslint configs | 4-5 |
| **Type definitions (new)** | Zod schemas for 11 API Resources + 5 Enums | 5-8 (new) |
| **Vue components** | Add `lang="ts"`, type props via `defineProps<T>()`, type emits | 35 |
| **Pinia stores** | Type state, getters, actions; use Zod-inferred types | 4 |
| **Composables** | Add parameter/return types | 2 |
| **Router** | Type route meta, navigation guards | 1 |
| **Lib utilities** | Rename `.js` → `.ts`, add function signatures | 2 |
| **Entry files** | Rename `app.js`/`bootstrap.js` → `.ts` | 2 |
| **Test files** | Rename `.test.js` → `.test.ts`, update imports, type mocks | 43 |

### API Resources → Zod Schema Mapping

11 Laravel API Resources will each get a corresponding Zod schema:

| Resource | Complexity | Notes |
|---|---|---|
| AdminProjectResource | Low | Straightforward scalars |
| AdminRoleResource | Medium | Relationship access, plucked array |
| GlobalSettingResource | Medium | Dynamic `value` field (JSON) |
| ProjectConfigResource | High | Service-dependent `effective` field |
| ActivityResource | Medium | Enum values, optional relationships |
| AuditLogResource | Medium | Optional relationships, dynamic properties |
| ExternalTaskResource | Medium-High | Conditional `result` field |
| MessageResource | Medium | JSON-decoded tool_calls/tool_results |
| ConversationDetailResource | High | Nested MessageResource collection |
| ConversationResource | High | Conditional nested objects |
| UserResource | High | Complex inline project transformation |

5 Enums → TypeScript string literal unions:
- TaskStatus, TaskType, TaskPriority, TaskOrigin, ReviewStrategy

### Relevant Decisions

| Decision | Summary | Relationship |
|---|---|---|
| D180 | @antfu/eslint-config with `typescript: false` | **Needs update** — enable `typescript: true` |
| D181 | Stylistic overrides (semicolons, 4-space indent, single quotes) | Unchanged — applies to TS equally |
| D182 | ESLint CI job | May add `vue-tsc` type-check step |
| D71 | Code quality tools | Extends — adds TypeScript + Zod as quality tools |

### Dependencies

- **Requires first:** Nothing — can start immediately. Vite, Vue 3, and @antfu/eslint-config all have built-in TypeScript support.
- **Unblocks:** Typed API client layer, typed WebSocket events, typed route params, better IDE support for all future frontend work.

## Risk Factors

- **Scope:** 131 files is large for a single extension, but big-bang avoids the worse risk of a half-migrated codebase lingering indefinitely.
- **Store typing complexity:** `conversations.js` (22KB) and `admin.js` (17KB) have deeply nested reactive state. Typing these correctly requires understanding all data shapes.
- **Zod bundle size:** Zod adds ~13KB gzipped. Acceptable for this application but worth noting.
- **Test maintenance:** 43 test files need updating. Mock types may need type assertions (`as unknown as Type`).
- **ESLint rule burst:** Enabling TypeScript in `@antfu/eslint-config` activates new rules that may flag existing patterns.
- **Strict mode friction:** `strict: true` with `no-explicit-any` is the hardest combination. Some patterns (e.g., event handlers, third-party library gaps) may need `unknown` + type guards.

## Recommendation

**Proceed to planning-extensions.** The migration is well-understood technology but the scope (131 files) and cross-cutting nature require a structured task list. The big-bang strategy means this is a single focused extension with clear completion criteria: all files converted, `vue-tsc` passes with zero errors, all tests pass, ESLint clean.

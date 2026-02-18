## Extension 008: Frontend TypeScript Migration

**Status: ✅ Implemented** — `01852ac`

### Trigger

Long-term maintainability concern for the Vue 3 frontend. The codebase has zero TypeScript infrastructure — no `.ts` files, no `tsconfig.json`, no typed props or stores. Adding type safety improves refactoring confidence, IDE support, and catches bugs at compile time.

### Scope

What it does:
- Converts all 88 frontend source files from JavaScript to TypeScript (strict mode)
- Adds Zod schemas as the single source of truth for API response types (compile-time + runtime validation)
- Converts all 43 frontend test files from `.test.js` to `.test.ts`
- Enables TypeScript support in ESLint (`@antfu/eslint-config`)
- Adds `vue-tsc` type checking to CI pipeline
- Bans `any` via ESLint rule

What it does NOT do:
- Change any runtime behavior — TypeScript is stripped at build time by Vite/esbuild
- Modify any backend code (PHP/Laravel)
- Add database migrations or schema changes
- Change the API contract between frontend and backend

### Architecture Fit

- **Components affected:** Build config, all Vue components, Pinia stores, composables, router, lib utilities, test files
- **Extension points used:** ESLint config (D180), Vitest config, Vite config
- **New tables/endpoints/services:** None
- **New dependencies:** `typescript`, `vue-tsc`, `zod` (npm devDependencies, except `zod` which is a runtime dependency)

### New Decisions

- **D183:** TypeScript strict mode for Vue 3 frontend — `strict: true` in `tsconfig.json` from day one. Catches more bugs, eliminates the need for a second tightening pass. All new frontend code must be fully typed.
- **D184:** Zod schemas as single source of truth for API response types — Define schemas in `resources/js/types/api.ts`, extract static types via `z.infer<>`, use `.parse()` for runtime validation in development. Catches backend contract drift at runtime.
- **D185:** Ban `any` via ESLint `@typescript-eslint/no-explicit-any: error` — Forces proper typing with `unknown` + type guards. No escape hatches.
- **D186:** `vue-tsc` type checking in CI — Runs as a separate lightweight job (Node.js only, no DB services), consistent with D176/D182 pattern.

### Affected Existing Decisions

| Decision | Current State | Proposed Change | Rationale |
|---|---|---|---|
| D180 | `@antfu/eslint-config` with `typescript: false` | Change to `typescript: true` | Enable TS-aware linting rules |

### Component Design

#### Build Configuration
**Current behavior:** Vite builds `.js` and `.vue` files. No TypeScript tooling.
**Proposed behavior:** Vite builds `.ts` and `.vue` (with `lang="ts"`) files. `vue-tsc` validates types before build.
**Interface changes:**
- New `tsconfig.json` and `tsconfig.app.json` (app code) + `tsconfig.node.json` (config files)
- `index.html` entry point changes from `app.js` to `app.ts`
- ESLint config enables TypeScript
- Vitest config updated for `.test.ts` pattern
- New npm scripts: `typecheck` (`vue-tsc --noEmit`)
**Data model changes:** None

#### Type System (New)
**Current behavior:** No type definitions exist.
**Proposed behavior:** Central type definitions in `resources/js/types/`:
- `enums.ts` — TypeScript string literal unions matching PHP enums (TaskStatus, TaskType, TaskPriority, TaskOrigin, ReviewStrategy)
- `api.ts` — Zod schemas for all 11 API Resources with inferred types
- `index.ts` — Barrel re-exports
**Interface changes:** All stores and components import types from `@/types`
**Data model changes:** None

#### Pinia Stores
**Current behavior:** Stores use `ref()`, `computed()`, `reactive()` without type annotations. State shapes are implicit.
**Proposed behavior:** All store state typed via interfaces or Zod-inferred types. Function parameters and return types annotated. Reactive refs use generic form: `ref<User | null>(null)`.
**Interface changes:** Store return types become typed — consumers get autocomplete and compile-time checks.
**Data model changes:** None

#### Vue Components
**Current behavior:** Components use `<script setup>` with runtime `defineProps({ prop: { type: Object } })`.
**Proposed behavior:** Components use `<script setup lang="ts">` with type-based `defineProps<{ prop: SomeType }>()`. Emits typed via `defineEmits<{ (e: 'update', value: string): void }>()`.
**Interface changes:** Props become compile-time typed. Template expressions get type checking via `vue-tsc`.
**Data model changes:** None

#### Test Infrastructure
**Current behavior:** Tests in `.test.js` files using Vitest + Vue Test Utils. Mocks untyped.
**Proposed behavior:** Tests in `.test.ts` files. Mock factories typed. `vi.mock()` returns typed modules. Test utilities get type annotations.
**Interface changes:** Vitest config `include` pattern changes from `**/*.test.js` to `**/*.test.ts`.
**Data model changes:** None

### Dependencies

- **Requires:** Nothing — can start immediately. Vite, Vue 3, and @antfu/eslint-config all have built-in TypeScript support.
- **Unblocks:** Typed API client layer, typed WebSocket events, typed route params, better IDE experience for all future frontend work.

### Risk Mitigation

| Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|
| Store typing complexity (conversations.js is 22KB) | Slow progress on largest files | Medium | Type stores incrementally — start with state interfaces, then annotate functions |
| ESLint TypeScript rules flag many existing patterns | Burst of lint errors blocking CI | Medium | Run `npm run lint:fix` after enabling — most are auto-fixable |
| `no-explicit-any` too strict for some edge cases | Migration blocked by hard-to-type patterns | Low | Use `unknown` + type narrowing; for truly untyped third-party gaps, use `as unknown as Type` |
| Test files need extensive mock typing | Test conversion takes longer than source | Medium | Use `vi.mocked()` helper for typed mocks; accept `as` casts in test-only code |
| Zod bundle size (~13KB gzipped) | Slightly larger production bundle | Low | Acceptable for this application; tree-shaking removes unused validators |

### Rollback Plan

This is a pure code migration with no data or schema changes:
- **Database rollback:** Not applicable — no migrations involved
- **Feature flag strategy:** Not applicable — TypeScript is build-time only, no runtime feature flag needed
- **Git revert scope:** Revert the ext-008 commits. The codebase returns to JavaScript immediately since TypeScript is stripped at build time.
- **Data recovery:** Not applicable — no data migration involved

### Data Migration

**Schema changes:** None — this is a code-only migration with no database involvement.

**Migration strategy:**
- [x] No database schema changes
- [x] No data backfill needed
- [x] Code-only migration: `.js` → `.ts`, `.test.js` → `.test.ts`, `.vue` adds `lang="ts"`

**Zero-downtime approach:** Not applicable — TypeScript is stripped at build time by Vite/esbuild. The production JavaScript output is functionally identical.

**Rollback procedure:**
- [x] Git revert restores all `.js` files
- [x] No data to recover
- [x] Estimated rollback time: Immediate (git revert + rebuild)

### Tasks

#### T160: Install TypeScript dependencies
**File(s):** `package.json`
**Action:** `npm install -D typescript vue-tsc` and `npm install zod`
**Verification:** `npx vue-tsc --version` succeeds; `node -e "require('zod')"` succeeds

#### T161: Create TypeScript configuration files
**File(s):** `tsconfig.json`, `tsconfig.app.json`, `tsconfig.node.json`
**Action:** Create three-file tsconfig setup:
- `tsconfig.json` — project references to app and node configs
- `tsconfig.app.json` — strict: true, target ES2020, Vue support, path aliases (`@/*` → `resources/js/*`), include `resources/js/**/*`
- `tsconfig.node.json` — for config files (vite.config.ts, vitest.config.ts, eslint.config.js)
**Verification:** `npx vue-tsc --noEmit` runs (may have errors — that's expected at this stage)

#### T162: Create enum type definitions
**File(s):** `resources/js/types/enums.ts`
**Action:** Define TypeScript string literal union types matching the 5 PHP enums:
- `TaskStatus`: `'received' | 'queued' | 'running' | 'completed' | 'failed' | 'superseded'`
- `TaskType`: `'code_review' | 'issue_discussion' | 'feature_dev' | 'ui_adjustment' | 'prd_creation' | 'security_audit' | 'deep_analysis'`
- `TaskPriority`: `'high' | 'normal' | 'low'`
- `TaskOrigin`: `'webhook' | 'conversation'`
- `ReviewStrategy`: `'frontend-review' | 'backend-review' | 'mixed-review' | 'security-audit'`
**Verification:** File compiles with `npx tsc --noEmit resources/js/types/enums.ts`

#### T163: Create Zod schemas for API resources
**File(s):** `resources/js/types/api.ts`, `resources/js/types/index.ts`
**Action:** Create Zod schemas for all 11 API Resources based on their `toArray()` return shapes. Export inferred types via `z.infer<>`. Create barrel `index.ts` re-exporting all types and schemas. Schemas:
- `AdminProjectSchema` / `AdminProject`
- `AdminRoleSchema` / `AdminRole`
- `GlobalSettingSchema` / `GlobalSetting`
- `ProjectConfigSchema` / `ProjectConfig`
- `ActivitySchema` / `Activity`
- `AuditLogSchema` / `AuditLog`
- `ExternalTaskSchema` / `ExternalTask`
- `MessageSchema` / `Message`
- `ConversationDetailSchema` / `ConversationDetail`
- `ConversationSchema` / `Conversation`
- `UserSchema` / `User`

Also define shared schemas: `PaginatedResponse<T>`, `ApiErrorResponse`.
**Verification:** `npx tsc --noEmit resources/js/types/api.ts` compiles cleanly

#### T164: Rename entry files and update HTML entry point
**File(s):** `resources/js/app.js` → `resources/js/app.ts`, `resources/js/bootstrap.js` → `resources/js/bootstrap.ts`, `resources/views/index.html` (or equivalent Blade/HTML entry)
**Action:** Rename `.js` → `.ts`. Add type annotations where needed (e.g., `window.axios` declarations). Update `index.html` script src from `app.js` to `app.ts`. Add `env.d.ts` for Vite client types (`/// <reference types="vite/client" />`).
**Verification:** `npm run dev` starts without import errors

#### T165: Convert lib/ utilities to TypeScript
**File(s):** `resources/js/lib/sse.js` → `sse.ts`, `resources/js/lib/markdown.js` → `markdown.ts`
**Action:** Rename files. Add function parameter types, return types, and callback types. `sse.ts` already has JSDoc — convert to native TS annotations. Type the SSE event callbacks and streaming interfaces.
**Verification:** Files compile with `vue-tsc --noEmit`

#### T166: Convert composables to TypeScript
**File(s):** `resources/js/composables/*.js` → `*.ts`
**Action:** Rename files. Add parameter types, return types. Type reactive refs with generics (`ref<string>('')`). Type composable return objects.
**Verification:** Files compile with `vue-tsc --noEmit`

#### T167: Convert router to TypeScript
**File(s):** `resources/js/router/index.js` → `index.ts`
**Action:** Rename file. Type route meta fields. Import `RouteRecordRaw` from `vue-router`. Type navigation guards.
**Verification:** File compiles with `vue-tsc --noEmit`

#### T168: Convert auth store to TypeScript
**File(s):** `resources/js/stores/auth.js` → `auth.ts`
**Action:** Rename file. Import `User` type from `@/types`. Type state refs: `ref<User | null>(null)`, `ref<boolean>(false)`. Type all action parameters and return types.
**Verification:** File compiles. Existing store tests still pass after test conversion.

#### T169: Convert conversations store to TypeScript
**File(s):** `resources/js/stores/conversations.js` → `conversations.ts`
**Action:** Rename file. This is the largest store (22KB). Define local interfaces for complex state shapes (streaming state, action preview state, task tracking). Import `Conversation`, `Message` types from `@/types`. Type all refs, computed properties, and action functions.
**Verification:** File compiles. All conversation-related tests pass.

#### T170: Convert dashboard store to TypeScript
**File(s):** `resources/js/stores/dashboard.js` → `dashboard.ts`
**Action:** Rename file. Import `Activity` type from `@/types`. Type activity items array, filter state, metrics refs.
**Verification:** File compiles. Dashboard tests pass.

#### T171: Convert admin store to TypeScript
**File(s):** `resources/js/stores/admin.js` → `admin.ts`
**Action:** Rename file. Second largest store (17KB). Import `AdminProject`, `AdminRole`, `GlobalSetting`, `ProjectConfig` types from `@/types`. Type all refs including nested validation error objects. Type action parameters and return types.
**Verification:** File compiles. Admin tests pass.

#### T172: Convert all Vue components to TypeScript
**File(s):** All 35 `.vue` files in `resources/js/components/`, `resources/js/pages/`, `resources/js/App.vue`
**Action:** For each `.vue` file:
1. Change `<script setup>` to `<script setup lang="ts">`
2. Convert `defineProps({...})` to `defineProps<{...}>()`  with typed interfaces
3. Type `defineEmits` with event signatures
4. Add types to `ref()`, `computed()`, `watch()` where inference is insufficient
5. Import types from `@/types` for API data used in templates
6. Type event handler parameters (`(event: Event)`, `(value: string)`)
**Verification:** `npx vue-tsc --noEmit` passes for all component files

#### T173: Update ESLint config — enable TypeScript
**File(s):** `eslint.config.js`
**Action:** Change `typescript: false` to `typescript: true` in `@antfu/eslint-config` options. Add `@typescript-eslint/no-explicit-any: 'error'` rule. Run `npm run lint:fix` to auto-fix any new violations. Manually fix remaining issues.
**Verification:** `npm run lint` passes with zero errors

#### T174: Update Vitest config for TypeScript test files
**File(s):** `vitest.config.js`
**Action:** Update `include` pattern from `resources/js/**/*.test.js` to `resources/js/**/*.test.ts`. Verify coverage config still works.
**Verification:** `npm test` runs with the new pattern (tests may fail if not yet converted — that's expected at this point)

#### T175: Convert all test files to TypeScript
**File(s):** All 43 `.test.js` files → `.test.ts`
**Action:** Rename all test files. For each test file:
1. Add type imports for component props, store types, and API types
2. Type mock return values (`vi.fn<() => Promise<AxiosResponse<{ data: User }>>>()`)
3. Use `vi.mocked()` for typed mock access
4. Add type assertions where needed for test fixtures (`as unknown as User`)
5. Type `wrapper` variables from Vue Test Utils mount
**Verification:** `npm test` passes — all 43 test files execute successfully

#### T176: Add vue-tsc type check script and CI job
**File(s):** `package.json`, `.github/workflows/ci.yml` (or equivalent)
**Action:** Add npm script: `"typecheck": "vue-tsc --noEmit"`. Add CI job (following D176/D182 pattern — separate lightweight job, Node.js only):
```yaml
typecheck:
  runs-on: ubuntu-latest
  steps:
    - uses: actions/checkout@v4
    - uses: actions/setup-node@v4
      with:
        node-version: 24
        cache: npm
    - run: npm ci
    - run: npm run typecheck
```
**Verification:** `npm run typecheck` passes locally. CI job passes.

#### T177: Run full verification
**File(s):** (all)
**Action:** Run complete verification suite:
1. `npm run typecheck` — zero type errors
2. `npm test` — all 43 test files pass
3. `npm run lint` — zero ESLint errors
4. `npm run build` — production build succeeds
5. `npm run dev` — dev server starts, app loads in browser
**Verification:** All 5 checks pass. No regressions.

#### T178: Update CLAUDE.md
**File(s):** `CLAUDE.md`
**Action:** Add to Commands table: `npm run typecheck` — Run vue-tsc type checking. Update Tech Stack to note TypeScript + Zod. Update Coding Standards Vue 3 section to mention TypeScript, strict mode, Zod schemas, and no-any policy. Add learning about Zod + vue-tsc patterns if any emerge during implementation.
**Verification:** CLAUDE.md accurately reflects new tooling

#### T179: Update decisions index
**File(s):** `docs/spec/decisions-index.md`
**Action:** Add decisions D183–D186 with source `ext-008`. Update D180 entry to note TypeScript is now enabled.
**Verification:** Decision index entries match the New Decisions section above

### Verification

- [ ] `npm run typecheck` passes with zero errors (vue-tsc --noEmit)
- [ ] `npm test` passes — all 43 test files execute successfully
- [ ] `npm run lint` passes with zero ESLint errors
- [ ] `npm run build` produces a working production bundle
- [ ] `npm run dev` starts dev server and app loads correctly in browser
- [ ] No `any` types in codebase (enforced by ESLint rule)
- [ ] All Zod schemas match corresponding Laravel API Resource `toArray()` shapes
- [ ] All existing tests still pass (no regressions)
- [ ] CI pipeline passes (typecheck + tests + lint)
- [ ] D183–D186 added to decisions index

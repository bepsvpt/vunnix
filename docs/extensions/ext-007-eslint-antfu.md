## Extension 007: ESLint with @antfu/eslint-config

### Trigger
Frontend has zero linting or formatting tooling. CLAUDE.md states "Formatting: ESLint + Prettier" as the Vue standard, but neither is installed. PHP side has full coverage (Pint ext-006 + PHPStan ext-005 + Rector); JS/Vue side has no enforcement. D71 established ESLint as a code quality tool but it was never operationalized.

### Scope
What it does:
- Installs `@antfu/eslint-config` (v7.x) for combined linting + formatting via ESLint Stylistic — single tool replaces ESLint + Prettier
- Creates `eslint.config.js` (ESLint 9 flat config) configured for Vue 3, with style overrides matching the existing codebase conventions
- Adds `npm run lint` / `npm run lint:fix` scripts
- Adds a Claude Code PostToolUse hook that auto-formats JS/Vue files after every Edit/Write
- Adds a lightweight ESLint CI job to GitHub Actions (same pattern as Pint D179 and PHPStan D176)
- Runs an initial whole-codebase lint fix as a standalone commit

What it does NOT do:
- Does not add Prettier — ESLint Stylistic handles formatting (this is the explicit design choice)
- Does not add stylelint — CSS/Tailwind linting is a separate concern (also in D71, deferred)
- Does not add TypeScript — the project uses plain JavaScript; TypeScript migration is a separate decision
- Does not add git pre-commit hooks — Claude Code hooks handle the AI workflow; human developers can run `npm run lint` manually

### Architecture Fit
- **Components affected:** `eslint.config.js` (new), `package.json` (modify), `.claude/settings.json` (modify — add hook alongside Pint), `.claude/hooks/run-eslint.sh` (new), `.github/workflows/tests.yml` (modify), `CLAUDE.md` (modify), `docs/spec/decisions-index.md` (modify)
- **Extension points used:** Claude Code PostToolUse hooks (alongside existing Pint hook), GitHub Actions CI workflow (ext-004), npm scripts
- **New tables/endpoints/services:** None

### New Decisions

- **D180:** Use `@antfu/eslint-config` with ESLint Stylistic for combined JS/Vue linting and formatting — replaces the planned "ESLint + Prettier" stack (CLAUDE.md). Single-tool approach eliminates ESLint/Prettier conflicts and mirrors Pint's single-tool philosophy on the PHP side. `@antfu/eslint-config` bundles `eslint-plugin-vue`, `@stylistic/eslint-plugin`, and `eslint-plugin-perfectionist`. ~305K weekly npm downloads, maintained by Vue/Vite core team member.
- **D181:** Stylistic overrides: semicolons on, 4-space indent, single quotes — matching existing codebase conventions and `.editorconfig`. Vue `<template>` uses 2-space indent (Vue ecosystem convention, already present in codebase). These override `@antfu/eslint-config` defaults (no semicolons, 2-space indent).
- **D182:** ESLint CI runs as separate lightweight job without database or PHP services — only needs Node.js + npm. Same pattern as Pint (D179) and PHPStan (D176). Runs `npx eslint` (exits non-zero on violations).

### Dependencies
- **Requires:** Nothing — npm and CI workflow already exist
- **Unblocks:** Consistent JS/Vue code quality enforcement; foundation for stylelint integration (also D71); potential future TypeScript adoption (eslint-config supports it natively)

### Tasks

#### T152: Install dependencies and create eslint.config.js
**File(s):** `package.json` (modify — new devDependencies), `eslint.config.js` (new)
**Action:** Install `@antfu/eslint-config` + `eslint`, create flat config with Vue 3 + stylistic overrides (4-space indent, semicolons, single quotes, 1tbs brace style, no-alert off).
**Verification:** `npx eslint --print-config resources/js/app.js` outputs config without errors.

#### T153: Run initial whole-codebase lint fix (separate commit)
**File(s):** All JS/Vue files under `resources/js/`
**Action:** Run `npx eslint --fix resources/js/`, fix manual issues (unused vars, scoping bugs), verify tests pass. Commit as standalone formatting-only commit.
**Verification:** `npx eslint resources/js/` exits 0. `npm test` passes.

#### T154: Add npm lint scripts
**File(s):** `package.json`
**Action:** Add `lint` and `lint:fix` scripts.
**Verification:** `npm run lint` exits 0.

#### T155: Create Claude Code PostToolUse hook for JS/Vue files
**File(s):** `.claude/hooks/run-eslint.sh` (new)
**Action:** Create hook that runs `eslint --fix` on JS/Vue files, exits silently for non-matching files.
**Verification:** Hook exits 0 for JS and non-JS files.

#### T156: Register hook in Claude Code settings
**File(s):** `.claude/settings.json` (modify)
**Action:** Add run-eslint.sh hook alongside existing Pint hook.
**Verification:** Settings has both hooks.

#### T157: Add ESLint CI job to GitHub Actions
**File(s):** `.github/workflows/tests.yml`
**Action:** Add eslint job — Node 24 + npm ci + npx eslint.
**Verification:** Job definition present in workflow file.

#### T158: Update CLAUDE.md
**File(s):** `CLAUDE.md`
**Action:** Add lint commands, update Vue formatting standard.
**Verification:** Both entries present.

#### T159: Update decisions index
**File(s):** `docs/spec/decisions-index.md`
**Action:** Append D180-D182.
**Verification:** `grep -c 'D18[012]' docs/spec/decisions-index.md` returns 3.

### Verification
- [ ] `eslint.config.js` exists with @antfu/eslint-config, stylistic overrides for semicolons + 4-space indent + single quotes
- [ ] Initial lint fix applied as a standalone commit (separate from all other changes)
- [ ] `npx eslint resources/js/` exits 0 (all files clean)
- [ ] `npm run lint` and `npm run lint:fix` scripts work
- [ ] Claude Code PostToolUse hook runs ESLint on JS/Vue files after Edit/Write
- [ ] `.claude/hooks/run-eslint.sh` exits silently for non-JS/Vue files
- [ ] CI workflow has a passing `ESLint` job
- [ ] `npm test` passes after lint fix
- [ ] D180–D182 added to decisions index
- [ ] CLAUDE.md updated with `npm run lint` / `npm run lint:fix` and corrected Vue formatting standard

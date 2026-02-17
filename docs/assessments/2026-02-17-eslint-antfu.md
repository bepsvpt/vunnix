# Assessment: ESLint with @antfu/eslint-config

**Date:** 2026-02-17
**Requested by:** Kevin
**Trigger:** Frontend has zero linting or formatting tooling. CLAUDE.md states "Formatting: ESLint + Prettier" as the Vue standard, but neither is installed. PHP side has full coverage (Pint + PHPStan + Rector); JS/Vue side has none.

## What

Integrate ESLint via `@antfu/eslint-config` for the Vue 3 frontend — covering both linting (logic errors, Vue best practices) and formatting (via ESLint Stylistic, replacing Prettier). This mirrors the PHP-side pattern where Laravel Pint handles both style enforcement and CI gating. The choice of `@antfu/eslint-config` over ESLint + Prettier was evaluated in conversation: single tool, no conflicts, Vue ecosystem standard (~305K weekly npm downloads, maintained by Vue/Vite core team member).

## Classification

**Tier:** 2 (Feature-scoped)
**Rationale:** New automated capability (ESLint config + CI enforcement) within existing architecture. Touches 5-7 files across 3 components (ESLint config, npm scripts, CI workflow). Requires 2-3 new decisions about configuration choices. Directly parallels ext-006 (Pint integration).

**Modifiers:**
- [ ] `breaking` — Changes public API, DB schema, or external contracts
- [ ] `multi-repo` — Affects more than one repository
- [ ] `spike-required` — Feasibility uncertain, needs research first
- [ ] `deprecation` — Removes or sunsets existing capability
- [ ] `migration` — Requires data migration or rollout coordination

## Impact Analysis

### Components Affected
| Component | Impact | Files (est.) |
|---|---|---|
| ESLint config | New `eslint.config.js` at project root with @antfu/eslint-config | 1 |
| package.json | Add `eslint` + `@antfu/eslint-config` to devDependencies, add `lint` / `lint:fix` scripts | 1 |
| CI workflow | New `eslint` job in `.github/workflows/tests.yml` | 1 |
| CLAUDE.md | Document new commands, update "Formatting: ESLint + Prettier" to reflect actual tooling | 1 |
| Decisions index | Append D180-D182 | 1 |
| Vue/JS source files | Auto-fixed formatting on initial lint pass (88 files under resources/js/) | ~88 |

### Relevant Decisions
| Decision | Summary | Relationship |
|---|---|---|
| D71 | Code quality tools — eslint, PHPStan, stylelint (not MCP) | Enables: this extension operationalizes the ESLint part of D71 |
| D177 | Laravel Pint `laravel` preset + strict rules | Pattern: Pint config → hook → CI mirrors what ESLint setup will do for JS |
| D178 | Claude Code PostToolUse hook auto-formats PHP files via Pint | Pattern: may want equivalent hook for JS/Vue files (optional, deferred) |
| D179 | Pint CI runs as separate lightweight job using `--test` dry-run mode | Pattern: ESLint CI job follows same pattern — lightweight, no DB |

### Dependencies
- **Requires first:** Nothing — npm and CI workflow already exist
- **Unblocks:** Consistent JS/Vue code quality enforcement; foundation for a Claude Code PostToolUse hook for JS files if desired later; potential future addition of stylelint (also in D71)

## Risk Factors
- Initial `eslint --fix` pass will reformat all 88 frontend files (mitigated: run as standalone formatting commit, same pattern as Pint ext-006)
- `@antfu/eslint-config` is opinionated (no semicolons, single quotes by default) — may differ from current code style (mitigated: configurable via `eslint.config.js` overrides)
- `@antfu/eslint-config` rule changes in minor versions aren't treated as breaking changes (mitigated: pin to major version in package.json, review changelogs on update)
- `.editorconfig` specifies 4-space indentation — must verify ESLint Stylistic respects this or configure explicitly

## Recommendation
Proceed to planning-extensions. Tier 2 — straightforward tooling integration with a clear precedent (ext-006 Pint). Needs an explicit task list for: config creation, initial formatting pass, npm script registration, CI integration, and CLAUDE.md updates.

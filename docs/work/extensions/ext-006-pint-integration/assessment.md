# Assessment: Laravel Pint Integration

**Date:** 2026-02-17
**Requested by:** Kevin
**Trigger:** Pint is installed but not automated — runs only when manually invoked. No Claude Code hook, no CI enforcement, and no project-level configuration beyond the default Laravel preset.

## What

Integrate Laravel Pint at three levels: (1) a Claude Code PostToolUse hook that auto-formats modified PHP files after every Edit/Write, (2) a CI job in GitHub Actions that enforces formatting on PRs and pushes to main, and (3) a best-practice `pint.json` configuration that goes beyond the default `laravel` preset with additional rules for code quality, PHPDoc consistency, and modern PHP idioms.

## Classification

**Tier:** 2 (Feature-scoped)
**Rationale:** New automated capability (hook + CI enforcement) within existing architecture. Touches 5-7 files across 3 components (Claude hooks, CI workflow, Pint config). Requires 2-3 new decisions about configuration choices.

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
| Pint config | New `pint.json` at project root with best-practice ruleset | 1 |
| Claude Code hooks | New PostToolUse hook script + settings.json hook registration | 2-3 |
| CI workflow | New `pint` job in `.github/workflows/tests.yml` | 1 |
| Composer scripts | Add `format` / `format:check` scripts | 1 |
| CLAUDE.md | Document new commands and hook behavior | 1 |
| Decisions index | Append D177-D179 | 1 |

### Relevant Decisions
| Decision | Summary | Relationship |
|---|---|---|
| D176 | PHPStan CI runs as separate job without database services | Pattern: Pint CI job follows same pattern — lightweight, no DB |
| (CLAUDE.md) | PSR-12, enforce with Laravel Pint | Enables: this extension operationalizes what was previously stated policy |

### Dependencies
- **Requires first:** Nothing — Pint is already installed, CI workflow exists
- **Unblocks:** Consistent code style enforcement across all contributors (human and AI); foundation for pre-commit hooks if desired later

## Risk Factors
- Running Pint on every Edit/Write adds ~200-500ms per file operation (mitigated: Pint on a single file is fast)
- A strict `pint.json` applied to the existing 900+ file codebase may produce a large initial formatting diff (mitigated: run Pint once as a standalone formatting commit before enabling CI enforcement)
- Hook must handle non-PHP files gracefully (exit early) and not block Claude's workflow on failure

## Recommendation
Proceed to planning-extensions. Tier 2 — straightforward but involves multiple integration points that benefit from an explicit task list.

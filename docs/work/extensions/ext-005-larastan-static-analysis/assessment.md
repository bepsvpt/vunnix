# Assessment: Larastan (PHPStan) Static Analysis

**Date:** 2026-02-16
**Requested by:** Kevin
**Trigger:** Long-term maintainability improvement — enforce type safety incrementally

## What

Introduce [Larastan](https://github.com/larastan/larastan) (the Laravel-specific PHPStan wrapper) and supporting PHPStan extensions as dev dependencies, configure for the main application starting at level 0, and incrementally raise to level 8. This brings static analysis into the local dev workflow and CI pipeline, complementing the executor-side PHPStan already installed globally in the Docker image (D71). The project's CLAUDE.md coding standards already list PHPStan as the static analysis tool but no configuration or enforcement exists yet.

## Classification

**Tier:** 2 (Feature-scoped)
**Rationale:** New capability (enforced static analysis in dev/CI) within existing architecture. Touches 3-5 files for initial setup, aligns with existing spec decisions. Each level increase follows a repeatable pattern (run, fix, bump level). No architectural changes required.

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
| `composer.json` | Add Larastan + extensions as dev dependencies | 1 |
| `phpstan.neon.dist` (new) | PHPStan configuration (committed) | 1 |
| `phpstan-baseline.neon` (new) | Baseline of existing errors to suppress | 1 |
| `.gitignore` | Add `phpstan.neon` for local overrides | 1 |
| `.github/workflows/tests.yml` | Add PHPStan analysis step to CI | 1 |
| `CLAUDE.md` | Add PHPStan commands to Commands table | 1 |
| `app/**/*.php` (177 files) | Fix type errors incrementally per level | varies per level |

### Compatibility Matrix

| Dependency | Project Version | Requirement | Compatible |
|---|---|---|---|
| PHP | ^8.5 | ^8.2 (Larastan 3.x) | Yes |
| Laravel | 12.51.0 (`^12.0`) | ^11.44.2 \|\| ^12.4.1 (Larastan 3.x) | Yes |
| PHPStan | (new) | ^2.1.32 (auto-installed by Larastan) | Yes |
| Carbon | ^3.8.4 (transitive) | extension.neon available | Yes |
| Mockery | ^1.6 (existing dev dep) | phpstan-mockery ^2.0 available | Yes |

### Packages

**Day 1 (install together):**

| Package | Version | Purpose |
|---|---|---|
| `larastan/larastan` | ^3.0 (latest: 3.9.2) | Laravel-aware PHPStan analysis; includes PHPStan ^2.1.32 |
| `phpstan/extension-installer` | ^1.4 (latest: 1.4.3) | Auto-registers all extensions via Composer plugin |
| `phpstan/phpstan-mockery` | ^2.0 (latest: 2.0.0) | Type-aware Mockery mock analysis |
| `phpstan/phpstan-deprecation-rules` | ^2.0 (latest: 2.0.4) | Flags `@deprecated` usage |

**Add at level 5+ (later PRs):**

| Package | Version | Purpose |
|---|---|---|
| `phpstan/phpstan-strict-rules` | ^2.0 (latest: 2.0.10) | Rules beyond max level: boolean conditionals, no loose `==`, strict `in_array()` |
| `tomasvotruba/type-coverage` | ^2.1 (latest: 2.1.0) | Enforce minimum typed params/returns/properties % in CI |

### Relevant Decisions

| Decision | Summary | Relationship |
|---|---|---|
| D71 | eslint, PHPStan, stylelint are static analysis tools in executor | Enables — executor already uses PHPStan; this extends to dev workflow |
| D51 | Backend review strategy activates phpstan for .php files | Constrains — executor PHPStan findings are classified by severity |

### Dependencies

- **Requires first:** Nothing — can be added independently
- **Unblocks:** Consistent type safety enforcement across dev, CI, and executor environments

## Exploration Findings

### Codebase Readiness

| Factor | Status | Notes |
|---|---|---|
| PHP version | ^8.5 | Fully supported by Larastan 3.x (requires ^8.2) |
| Laravel version | 12.51.0 | Fully supported by Larastan 3.x (requires ^11.44.2 or ^12.4.1) |
| Type annotations | Good foundation | Extensive PHPDoc (`@var`, `@return`, `@param`) already present |
| Eloquent relations | Well-typed | Explicit return type declarations on all relations |
| Facade usage | Moderate (~39 in app, ~40 in tests) | Larastan resolves these via its extension.neon |
| Magic methods | None custom | Only inherited Eloquent magic — Larastan handles this |
| Service/Controller pattern | Clean | Standard Laravel patterns, well-supported |
| App file count | 177 | Manageable for incremental adoption |

### PHPStan Level Reference (0–10, target: 8)

| Level | What it checks | Expected effort |
|---|---|---|
| 0 | Basic checks: unknown classes, unknown functions, unknown methods called on `$this` | Low |
| 1 | Possibly undefined variables, unknown magic methods/properties | Low |
| 2 | Unknown methods on all expressions (not just `$this`), PHPDoc verification | Low-Medium |
| 3 | Return types verified, property types verified | Medium |
| 4 | Dead code: unreachable branches, always-true/false conditions | Medium |
| 5 | Argument types to method/function calls checked | Medium-High |
| 6 | Missing typehints reported | High |
| 7 | Partially incorrect union types | High |
| 8 | Nullable types strictly checked (null on non-nullable) — **target** | High |
| 9 | Operations on explicit `mixed` types (skipped — excessive for Laravel) | Very High |
| 10 | Operations on implicit `mixed` types (skipped — excessive for Laravel) | Very High |

### Larastan 3.x Default Rules

Starting from Larastan 3.0, two rules are enabled by default:
- `NoEnvCallsOutsideOfConfigRule` — flags `env()` calls outside `config/` files
- `ModelAppendsRule` — flags unused `$appends` attributes

### Configuration Best Practices

- Use `phpstan.neon.dist` (committed) + `phpstan.neon` (gitignored) convention per PHPStan docs
- Use `extension-installer` to auto-register extensions — eliminates manual `includes:` for all extensions
- Only manual `includes:` needed is for `phpstan-baseline.neon`
- Start with `checkMissingIterableValueType: false` and `checkGenericClassInNonGenericObjectType: false`; enable at level 6+

## Risk Factors

- **Baseline churn at higher levels:** Levels 6–8 may produce hundreds of errors requiring a large baseline. Mitigate by fixing in batches, not all at once.
- **False positives from dynamic Laravel patterns:** Some Laravel idioms (e.g., `config()` return types, `request()->input()` mixed types) produce false positives at level 7+. Mitigate with targeted `ignoreErrors` rules.
- **CI time increase:** PHPStan analysis on ~177 files adds ~10-30 seconds. Negligible impact.
- **PHP 8.5 edge cases:** Larastan 3.x requires PHP ^8.2 and should work on 8.5, but new 8.5 syntax not yet in PHPStan's parser could cause parse errors. Mitigate by running level 0 first to surface any issues early.

## Recommendation

**Proceed to planning-extensions.** This is a straightforward Tier 2 extension with clear implementation steps and no blockers. The incremental level-by-level approach naturally limits risk at each step.

## Sources

- [Larastan GitHub](https://github.com/larastan/larastan) — v3.9.2, PHP ^8.2, Laravel ^11.44.2 || ^12.4.1
- [Larastan on Packagist](https://packagist.org/packages/larastan/larastan) — requires phpstan/phpstan:^2.1.32
- [PHPStan Rule Levels](https://phpstan.org/user-guide/rule-levels) — 11 levels (0–10)
- [PHPStan Config Reference](https://phpstan.org/config-reference) — `.dist` convention, parameters
- [PHPStan Baseline](https://phpstan.org/user-guide/baseline) — `--generate-baseline` flag
- [phpstan/extension-installer](https://packagist.org/packages/phpstan/extension-installer) — v1.4.3
- [phpstan/phpstan-mockery](https://packagist.org/packages/phpstan/phpstan-mockery) — v2.0.0
- [phpstan/phpstan-deprecation-rules](https://packagist.org/packages/phpstan/phpstan-deprecation-rules) — v2.0.4
- [phpstan/phpstan-strict-rules](https://packagist.org/packages/phpstan/phpstan-strict-rules) — v2.0.10
- [tomasvotruba/type-coverage](https://packagist.org/packages/tomasvotruba/type-coverage) — v2.1.0

# Assessment: Laravel IDE Helper (Models Only, Inline)

**Date:** 2026-02-17
**Requested by:** Kevin
**Trigger:** Better developer experience — IDE autocomplete for Eloquent model properties, relations, and scopes

## What

Integrate `barryvdh/laravel-ide-helper` as a Composer dev dependency, scoped to Eloquent model annotation only. Use `--write` mode to generate inline PHPDoc directly in model files. The annotations are committed, providing both IDE autocomplete and schema documentation. Auto-discovery handles provider registration; no AppServiceProvider changes needed.

## Classification

**Tier:** 1
**Rationale:** Dev-only dependency using existing Composer/artisan extension points. No runtime impact, no schema changes, no new architecture. Auto-discovery eliminates provider registration.

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
| `composer.json` | Add dev dependency + Composer script | 1 |
| 18 models in `app/Models/` | Inline `@property`/`@method` PHPDoc generated | 18 |
| `CLAUDE.md` | Add `composer ide-helper:models` to Commands table | 1 |

### Relevant Decisions
| Decision | Summary | Relationship |
|---|---|---|
| D174 | Larastan 3.x for dev static analysis | Complementary — inline PHPDoc is read natively by PHPStan |
| D175 | PHPStan level target: 8 | Complementary — generated annotations reduce false positives |

### Dependencies
- **Requires first:** Nothing (Larastan/PHPStan already installed via ext-005)
- **Unblocks:** Better DX for all future development

## Risk Factors
- **PHP 8.5 / Laravel 12 compatibility:** Package requires `^8.2` + `^11.15 || ^12` — compatible
- **Diff churn on model changes:** Re-running after migrations creates diffs in model files — acceptable trade-off for documentation value

## Recommendation

**Go ahead, no plan needed.** Tier 1 configuration change.

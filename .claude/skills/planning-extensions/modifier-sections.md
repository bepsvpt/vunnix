# Modifier Sections

Add these sections to the tier template when the corresponding modifier is checked in the assessment. Insert after the Dependencies section and before Tasks.

---

## `breaking` — Migration Plan

Add when the extension changes public API, DB schema, or external contracts.

```markdown
### Migration Plan

**What breaks:** {list every breaking change — endpoint removals, schema changes, contract modifications}

**Versioning strategy:**
- API: {new version prefix `/api/v{N}/` or backward-compatible additions}
- DB: {migration approach — additive first, then remove old columns after transition}
- External contracts: {structured output schema changes, webhook payload changes}

**Deprecation timeline:**
| Phase | Duration | What Happens |
|---|---|---|
| Announce | {timeframe} | Old behavior marked deprecated, warnings in responses |
| Dual support | {timeframe} | Both old and new behavior work |
| Remove | {timeframe} | Old behavior removed |

**Backward compatibility:** {specific measures during transition — default values, aliases, adapters}
```

---

## `multi-repo` — Cross-Repo Coordination

Add when the extension affects more than one repository.

```markdown
### Cross-Repo Coordination

**Repositories affected:**
| Repository | Changes | Deploy Order |
|---|---|---|
| {repo} | {what changes} | {1st / 2nd / 3rd} |

**Coordination approach:**
- {How to keep repos in sync during development}
- {Branch naming convention across repos}
- {Integration testing strategy — how to test the combined change}

**Deploy sequence:** {Order matters — which repo must deploy first and why}

**Rollback scope:** {If one repo's changes fail, what happens to the others?}
```

---

## `spike-required` — Spike Plan

Add when feasibility is uncertain. This section should be executed BEFORE the main tasks.

```markdown
### Spike Plan

**Question to answer:** {the specific uncertainty this spike resolves}

**Spike scope:**
- Timeboxed to: {duration — e.g., 1 day, 2 days}
- Deliverable: {what the spike produces — proof of concept, benchmark, feasibility report}
- Success criteria: {specific measurable criteria for proceeding}

**If spike succeeds:** Proceed with tasks below.
**If spike fails:** {alternative approach, or abandon the extension with rationale}
```

---

## `deprecation` — Sunset Plan

Add when the extension removes or sunsets existing capability.

```markdown
### Sunset Plan

**What's being removed:** {feature/capability being deprecated}

**User impact:** {who uses this, how are they affected}

**Communication:**
- [ ] Announce deprecation to affected users
- [ ] Document migration path (what to use instead)
- [ ] Add deprecation warnings in the UI/API

**Timeline:**
| Phase | Duration | What Happens |
|---|---|---|
| Announce | {timeframe} | Feature marked as deprecated |
| Warn | {timeframe} | Active warnings shown to users |
| Remove | {timeframe} | Feature removed from codebase |

**Data handling:** {what happens to data associated with the removed feature}
```

---

## `migration` — Data Migration Plan

Add when the extension requires data migration or rollout coordination.

```markdown
### Data Migration

**Schema changes:**
| Table | Change | Reversible? |
|---|---|---|
| {table} | {add/modify/remove column, new table, new index} | {yes/no} |

**Migration strategy:**
- [ ] Additive migration first (add new columns/tables)
- [ ] Backfill existing data
- [ ] Update application code to use new schema
- [ ] Remove old columns/tables (after verification period)

**Zero-downtime approach:** {how to migrate without service interruption}
- {e.g., dual-write during transition, lazy migration, background job}

**Rollback procedure:**
- [ ] Reverse migration script exists and is tested
- [ ] Data can be recovered from {backup/old columns/audit log}
- [ ] Estimated rollback time: {duration}

**Verification:**
- [ ] Migration completes without errors on staging data
- [ ] Application functions correctly with migrated data
- [ ] Performance benchmarks show no regression
```

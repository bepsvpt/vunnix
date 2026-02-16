# Tier 3 Template — Full Design Addendum

Use this template for Tier 3 (Architectural) extensions. Includes everything from Tier 2, plus additional sections for architectural impact.

---

```markdown
## Extension {NNN}: {Name}

### Trigger
{From assessment — what prompted this}

### Scope
What it does:
- {bullet}
- {bullet}

What it does NOT do:
- {bullet}
- {bullet}

### Architecture Fit
- **Components affected:** {list from assessment}
- **Extension points used:** {which existing v1 extension points, if any}
- **New tables/endpoints/services:** {list new infrastructure}

### New Decisions
- **D{N}:** {decision} — {rationale}
- **D{N+1}:** {decision} — {rationale}

### Affected Existing Decisions
{Decisions from the spec that this extension modifies or supersedes.}

| Decision | Current State | Proposed Change | Rationale |
|---|---|---|---|
| D{N} | {what it says now} | {what it should say after this extension} | {why the change} |

### Component Design
{One subsection per affected component. Detail what changes.}

#### {Component Name}
**Current behavior:** {what it does now}
**Proposed behavior:** {what it will do after this extension}
**Interface changes:** {API/contract changes, new methods, changed signatures}
**Data model changes:** {new columns, tables, indexes, or "None"}

#### {Component Name}
**Current behavior:** ...
**Proposed behavior:** ...
**Interface changes:** ...
**Data model changes:** ...

### Dependencies
- **Requires:** {what must exist first}
- **Unblocks:** {what this enables}

### Risk Mitigation

| Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|
| {risk} | {what happens if it occurs} | {Low/Med/High} | {how to prevent or recover} |

### Rollback Plan
{How to revert this change if it goes wrong. Include:}
- Database rollback approach (if migrations involved)
- Feature flag strategy (if applicable)
- Git revert scope (which commits)
- Data recovery steps (if data migration involved)

### Tasks
{Flat atomic task list. Continue T-numbering.}

#### T{N}: {Specific action}
**File(s):** {exact paths}
**Action:** {what to do}
**Verification:** {how to confirm it works}

#### T{N+1}: {Specific action}
**File(s):** {exact paths}
**Action:** {what to do}
**Verification:** {how to confirm it works}

### Verification
- [ ] {acceptance criterion}
- [ ] {acceptance criterion}
- [ ] All existing tests still pass (no regressions)
- [ ] New tests cover the changed components
```

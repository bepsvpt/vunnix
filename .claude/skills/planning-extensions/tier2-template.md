# Tier 2 Template — Single-Page Decision Doc

Use this template for Tier 2 (Feature-scoped) extensions. Copy and fill in from assessment data.

---

```markdown
## Extension {NNN}: {Name}

### Trigger
{From assessment — what user feedback, metric, or deferred decision prompted this}

### Scope
What it does:
- {bullet — concrete capability}
- {bullet}
- {bullet}

What it does NOT do:
- {bullet — explicit exclusion to prevent scope creep}
- {bullet}

### Architecture Fit
- **Components affected:** {list from assessment Impact Analysis}
- **Extension points used:** {which existing v1 extension points, if any}
- **New tables/endpoints/services:** {list any new infrastructure, or "None"}

### New Decisions
{Continue D-numbering from highest existing. Each decision gets a one-line summary + rationale.}

- **D{N}:** {decision summary} — {rationale}
- **D{N+1}:** {decision summary} — {rationale}

### Dependencies
- **Requires:** {from assessment — what must exist first}
- **Unblocks:** {what this extension enables}

### Tasks
{Flat atomic task list. Continue T-numbering from highest existing.}

#### T{N}: {Specific action}
**File(s):** {exact paths}
**Action:** {what to do}
**Verification:** {how to confirm it works}

#### T{N+1}: {Specific action}
**File(s):** {exact paths}
**Action:** {what to do}
**Verification:** {how to confirm it works}

### Verification
{Overall acceptance criteria for the entire extension}
- [ ] {criterion}
- [ ] {criterion}
- [ ] {criterion}
```

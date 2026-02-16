# Exploration Subagent Prompts

Prompt templates for the exploration subagents dispatched during Stage 2 of the assessment process. Use the Task tool with `subagent_type=Explore` for all of these.

## Component Scan

```
Analyze the proposed change: "{description}"

Explore the codebase and identify:
1. Which directories/modules would this change touch?
2. Estimated number of files affected (count existing files in those areas)
3. Which architectural layers are involved? (controller, service, model, migration, frontend component, API endpoint, queue job, etc.)
4. Are there existing extension points that could handle this? (executor skills, project CLAUDE.md, strategy selection, dashboard views, webhook events, conversation tools, code quality tools)

Return a structured list of affected components with estimated file counts.
```

## Decision Search

```
Search the specification and decision log for decisions related to: "{description}"

Look in:
- docs/spec/vunnix-v1.md (the full specification — especially the Discussion Log section)
- docs/spec/decisions-index.md (one-line summary of every D-numbered decision)
- Any existing extension documents in docs/extensions/
- Any existing assessment documents in docs/assessments/

For each relevant decision found, return:
- Decision number (D{N})
- One-line summary
- Relationship to the proposed change: does it CONSTRAIN this change, ENABLE it, or would it be SUPERSEDED by it?

Also flag any decisions explicitly marked as "deferred", "post-v1", or "not planned" that relate to this change.
```

## Dependency Trace

```
For the proposed change: "{description}"

Trace dependencies in both directions:
1. **Prerequisites:** What must exist before this can be built? Check:
   - Database tables/migrations that must exist
   - API endpoints this depends on
   - Services or models referenced
   - Frontend components or stores needed
   - Configuration or environment variables

2. **Downstream:** What would this change enable or unblock?
   - Other deferred features that depend on this
   - New capabilities this makes possible
   - Integration points this opens up

Return a dependency list with specific references to existing tasks (T{N}) or components.
```

## Pattern Match

```
For the proposed change: "{description}"

Search for similar past work:
1. Check git log for commits that suggest similar extensions were done before
2. Look in docs/extensions/ for similar extension plans
3. Look in docs/assessments/ for similar assessments
4. Check the codebase for patterns that suggest how similar changes were implemented

If similar work exists, return:
- What was done and when
- What patterns/conventions it established
- What can be reused or referenced
- What went wrong (if apparent from commit history)

If no similar work exists, say so — that's useful information too.
```

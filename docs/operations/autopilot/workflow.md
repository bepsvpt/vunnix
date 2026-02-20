# Vunnix Autopilot Workflow

Extracted from `CLAUDE.md` — these instructions governed the autonomous development loop (`run.sh`) that built the Vunnix codebase across 107 code tasks.

**Status:** Archived. The codebase is feature-complete. These instructions are preserved for reference.

---

## Resume Instructions

**Read this first on every session start.**

1. Read `progress.md` — find the **bolded** task (that's the current one)
2. Read `handoff.md` — if it has content, it contains sub-step progress, errors encountered, and approach context from the previous session. **Use this to resume mid-task instead of starting fresh.**
3. Run `git log --oneline -5` — see what was last committed
4. Run `git status` — detect uncommitted work from interrupted sessions
5. If uncommitted changes exist:
   - Run `git diff` to review them
   - Cross-reference with `handoff.md` sub-steps to understand what was intentional
   - If the changes look complete → run verification → commit → update progress.md → clear handoff.md
   - If partial → continue implementing from where `handoff.md` left off
6. If no changes and progress.md shows a task in bold → start that task fresh
7. Read the task details in `docs/reference/spec/vunnix-v1.md` §21
8. Implement → verify → update progress.md → clear handoff.md → commit → next task

## One Task Per Session (MANDATORY)

**Complete at most ONE task per session.** After verifying and committing a task, **stop**. Do not start the next task. The runner (`run.sh`) will launch a fresh session for the next task.

A single task may require multiple sessions — that's fine. Use `handoff.md` to carry state between sessions.

## Task Lifecycle

1. Find current task in `progress.md` (the **bolded** entry)
2. Check all its dependencies are `[x]` (completed)
3. Read the full task description in `docs/reference/spec/vunnix-v1.md` §21
4. **Write handoff.md** — set the current task, break it into sub-steps
5. Implement the task — **update handoff.md sub-steps as you go** (mark `[x]`, log errors)
6. Write tests per the milestone's Verification subsection in §21
7. Run verification (see protocol below)
8. Update `progress.md`: check the box `[x]`, update milestone count, bold the next task, update summary
9. **Promote learnings** — move any reusable insights from handoff.md "Errors & Blockers" / "Approach & Decisions" into the `## Learnings` section of CLAUDE.md
10. **Clear handoff.md** back to empty template
11. Commit with task reference (commit includes both progress.md and CLAUDE.md if learnings were added)
12. **Stop.** Do not start the next task — the runner will launch a new session.

> **Learnings promotion flow:** handoff.md "Errors & Blockers" → ask yourself *"would a future session hit this same problem?"* → if yes, distill it into a one-line actionable rule and add it to `## Learnings` in CLAUDE.md.

## Verification Protocol (MANDATORY)

**NEVER mark a task complete without passing verification.**

Run in this order:

```bash
# 1. Laravel tests (when applicable — after T1 scaffold exists)
php artisan test

# 2. Milestone structural checks
python3 verify/verify_m1.py   # (use verify_m{N}.py for current milestone)
```

- **Both must pass** before committing and marking the task done
- If tests fail → fix the issue, do not skip
- If structural checks fail → investigate and fix
- The verification scripts are the gatekeeper — not self-assessment

## Session Handoff Protocol

**`handoff.md` is your mid-task memory.** Maintain it throughout every session to enable seamless resume if the session is interrupted.

### When to Write

| Event | Action |
|---|---|
| **Starting a task** | Write the task ID, break it into sub-steps with `[ ]` checkboxes |
| **Completing a sub-step** | Mark it `[x]` in handoff.md |
| **Encountering an error** | Log the error message, what caused it, and any attempted fix under "Errors & Blockers" |
| **Making a design decision** | Note it under "Approach & Decisions" so the next session doesn't re-evaluate |
| **Solved a non-obvious problem** | Promote the insight to `## Learnings` in CLAUDE.md — this is how short-term memory becomes long-term |
| **Task fully complete** | Promote any reusable insights from handoff.md to `## Learnings`, then clear handoff.md |

### Template

```markdown
## Current Task
T{N}: {description}

## Sub-steps
- [x] Completed sub-step
- [ ] Remaining sub-step
- [ ] Another remaining sub-step

## Errors & Blockers
- `composer require foo/bar` failed: requires php ^8.3 — fixed by updating platform config
- Test `test_xyz` fails: expects column that migration hasn't created yet — need to run T3 first

## Approach & Decisions
- Using package X v2.0 instead of v1.x because of Y
- Chose strategy A over B because of Z

## Next Steps
1. Immediate next action
2. Then this
3. Then verify
```

### Rules

- **Write early, write often** — update after each sub-step, not just at session end
- **Be specific about errors** — include the actual error message, not just "it failed"
- **Don't duplicate progress.md** — handoff.md is for *within-task* state; progress.md is for *cross-task* state
- **Clear on completion** — when a task is verified and committed, reset handoff.md to its empty template

## After Compaction or Session Restart

The file system is the source of truth, not conversation context:

- `CLAUDE.md` — tells you what to do
- `progress.md` — shows exactly which task is current
- `handoff.md` — shows sub-step progress, errors, and context from the previous session
- `git log` — shows what was last committed
- `git status` — shows uncommitted work from interrupted sessions
- `verify/verify_m{N}.py` — confirms what actually works

## Handling Interrupted Tasks

If `git status` shows uncommitted changes from a previous session:

| Scenario | What you see | Action |
|---|---|---|
| **Complete but uncommitted** | All expected files changed, tests pass, handoff.md sub-steps all `[x]` | Run verification → commit → mark done → clear handoff.md |
| **Partial work** | Some files changed, handoff.md shows remaining `[ ]` sub-steps | Continue from the first unchecked sub-step in handoff.md |
| **No changes, handoff has context** | Clean tree, but handoff.md has errors/notes from previous attempt | Read the errors, adjust approach, restart the task |
| **No changes, handoff empty** | Clean working tree, empty handoff.md | Start the bolded task fresh |

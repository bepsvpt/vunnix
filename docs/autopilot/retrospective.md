# Vunnix Autopilot — Build Retrospective

Data-driven analysis of the autonomous development run that built the Vunnix codebase.

## Summary

| Metric | Value |
|---|---|
| **Total sessions** | 240 |
| **Wall clock time** | 31.2 hours (Feb 14 13:09 → Feb 15 20:22) |
| **Active session time** | 22.8 hours |
| **Tasks completed** | 107 (of 107 code tasks) |
| **Throughput** | 4.6 tasks/hour (active), 3.3 tasks/wall-hour |
| **Runner restarts** | 7 (manual stops/starts for parameter tuning) |
| **Model** | Claude Opus 4.6 |
| **Turn budget** | 50 per session |
| **Rate limit events** | 14 |
| **Errors** | 1 (CLI flag incompatibility on first session) |

## Session Outcomes

```
completed     ██████████████████████  104 (43.3%)
max_turns     █████████████████████████  121 (50.4%)
rate_limited  ██                         14 ( 5.8%)
error         ▏                           1 ( 0.4%)
```

**Key finding:** More than half of all sessions exhausted the 50-turn limit without completing a task. Each restart loses context and adds overhead. This was the primary source of inefficiency.

## Session Duration

| Metric | All Sessions | Completed | Max Turns |
|---|---|---|---|
| **Average** | 5.7 min | 5.2 min | 6.3 min |
| **Median** | 5.7 min | 5.2 min | 6.5 min |
| **Min** | 0.1 min | — | — |
| **Max** | 33.1 min | — | — |

Max-turns sessions averaged 6.3 minutes vs 5.2 for completed sessions. The similarity suggests sessions were hitting the turn limit just before task completion — a larger turn budget would have captured many of these.

## Multi-Session Tasks

66% of tasks required more than one session to complete:

| Metric | Value |
|---|---|
| **Single-session tasks** | 36 (34%) |
| **Multi-session tasks** | 69 (66%) |

### Most Session-Hungry Tasks

| Task | Sessions | Total Time | Description |
|---|---|---|---|
| T69 | 9 | 47 min | Pinned task bar (runner load awareness) |
| T40 | 6 | 37 min | Incremental review |
| T59 | 6 | 6 min | Language configuration injection (mostly rate-limited) |
| T70 | 5 | 33 min | Result cards (screenshots) |
| T72 | 5 | 24 min | Designer iteration flow |
| T94 | 5 | 26 min | Cost monitoring alerts |
| T100 | 5 | 28 min | API versioning + external access |
| T55 | 4 | 20 min | Action dispatch from conversation |
| T64 | 4 | 20 min | New conversation flow |
| T75 | 4 | 20 min | Dashboard activity feed |
| T84 | 4 | 14 min | Metrics aggregation |
| T91 | 4 | 20 min | Per-project configuration |
| T95 | 4 | 23 min | Over-reliance detection |
| T102 | 4 | 20 min | Prompt versioning |
| T104 | 4 | 29 min | Infrastructure monitoring alerts |

T69 was the most complex: 9 sessions, 47 minutes total. It involved real-time WebSocket state management with runner load awareness — a task that required reading and understanding multiple existing systems.

## Rate Limit Analysis

14 rate limit events across 240 sessions (5.8% rate):

| When | Task | Time Before Limit |
|---|---|---|
| Feb 14, 13:11 | T1 | 33.1 min |
| Feb 14, 16:03 | T18 | 0.8 min |
| Feb 14, 18:40 | T36 | 6.7 min |
| Feb 14, 21:17 | T47 | 7.8 min |
| **Feb 15, 00:04** | **T59** | **2.3 min** |
| **Feb 15, 00:11** | **T59** | **0.1 min** |
| **Feb 15, 00:17** | **T59** | **0.1 min** |
| **Feb 15, 00:22** | **T59** | **0.1 min** |
| **Feb 15, 00:27** | **T59** | **0.1 min** |
| Feb 15, 10:18 | T70 | 4.0 min |
| Feb 15, 13:12 | T84 | 0.7 min |
| Feb 15, 15:32 | T91 | 0.8 min |
| Feb 15, 17:53 | T99 | 7.5 min |
| Feb 15, 20:11 | T105 | 2.3 min |

### The T59 Rate Limit Storm

Sessions 96-100 (around midnight) triggered 5 consecutive rate limits on T59. Each retry attempted work after the fixed 300-second backoff, but was rate-limited again within 6 seconds. The pattern:

```
Session 96: rate limited (2.3 min work) → wait 300s
Session 97: rate limited (0.1 min work) → wait 300s
Session 98: rate limited (0.1 min work) → wait 300s
Session 99: rate limited (0.1 min work) → wait 300s
Session 100: rate limited (0.1 min work) → wait 300s
(eventually cleared after ~25 min total wait)
```

**Root cause:** Fixed 300s backoff with no escalation. Each retry burned through remaining rate limit quota almost immediately.

**Fix:** Exponential backoff — double the wait time on each consecutive rate limit.

## Error Analysis

Only 1 error in 240 sessions — a CLI flag incompatibility on the very first session:

```
Error: When using --print, --output-format=stream-json requires --verbose
```

The runner was initially invoking Claude without `--verbose` while using `--output-format stream-json`. Fixed immediately by adding `--verbose` to the command.

## Runner Restarts

The runner was manually stopped and restarted 7 times:

1. **Dry runs** (3x) — testing configuration before the real run
2. **CLI flag fix** — adding `--verbose` after the first error
3. **Rate limit recovery** — manual restart after rate limit storm
4. **Working directory change** — moved project directory mid-run
5. **Final shutdown** — stopped after T106 (ops task, no more code)

## Efficiency Metrics

| Metric | Value |
|---|---|
| **Sessions per task** (average) | 2.3 |
| **Sessions per task** (median) | 2.0 |
| **Idle time** (wall - active) | 8.4 hours (27%) |
| **Rate limit wait time** | ~70 min (14 events × 5 min avg) |
| **Session overhead** | ~40s per restart (10s between + 30s startup) |
| **Total session overhead** | ~160 min (240 restarts × 40s) |
| **Overhead percentage** | ~12% of active time |

## Recommendations for run.sh

Based on this data, the following improvements would meaningfully reduce build time:

### 1. Increase `MAX_TURNS` to 100

50 turns caused 50.4% of sessions to restart without completing a task. Each restart wastes context and adds startup overhead. However, 12.5% of sessions (30/241) already hit context window limits at 50 turns — with auto-compact disabled, context is a hard ceiling. 100 turns is a moderate increase that captures more completed-in-one-session tasks while staying below the context ceiling for most sessions. Going to 200+ would require enabling auto-compact.

### 2. Exponential Backoff for Rate Limits

Replace fixed 300s backoff with: 300s → 600s → 1200s → 2400s (cap). The T59 storm burned 25 minutes with fixed backoff; exponential would have resolved in ~15 minutes.

### 3. Track and Log Session Duration

Log the actual session duration at session end for monitoring. Currently requires timestamp arithmetic from the log.

### 4. Consecutive Rate Limit Detection

After 3+ consecutive rate limits, log a warning and switch to longer backoff. Consider pausing for a configurable "cooldown period" (e.g., 30 minutes).

### 5. End-of-Run Statistics

On graceful shutdown, display a summary: total sessions, tasks completed, rate limits hit, total active time, throughput.

### 6. Cost Tracking

If `--max-budget-usd` is set, track cumulative spend across sessions. Claude Code returns cost information in JSONL output that could be parsed.

## Data Sources

- `runner.log` — 1,356 lines of structured session events
- `verify/logs/*.jsonl` — 241 session files, 146 MB total (full Claude Code transcripts)
- Analysis performed on 2026-02-15

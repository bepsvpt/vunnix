#!/bin/bash
# ============================================================
# Vunnix Autonomous Development Runner
# ============================================================
#
# Runs Claude Code CLI in non-interactive mode, automatically
# restarting sessions when they end. Each session starts fresh
# (no --continue) to avoid context overflow since auto-compact
# is disabled. State lives in progress.md, not conversation.
#
# Usage:
#   ./run.sh                         Run with defaults
#   ./run.sh --dry-run               Show command without executing
#   ./run.sh --max-turns 50          Override turns per session
#   ./run.sh --model sonnet          Use Sonnet instead of Opus
#   ./run.sh --budget 5.00           Per-session spend limit (USD)
#   ./run.sh --retry-delay 600       Override rate-limit wait (sec)
#
# Monitor in another terminal:
#   bash verify/watch_progress.sh    Progress dashboard
#   tail -f verify/logs/runner.log   Runner log
# ============================================================

set -uo pipefail

# --- Configuration (tunable) ---
MAX_TURNS=50              # Agentic turns per session (~3-5 tasks)
RETRY_DELAY=300            # Wait on rate limit (5 min)
ERROR_DELAY=60             # Wait on other errors (1 min)
BETWEEN_SESSIONS=10        # Pause between normal sessions (10 sec)
MODEL="opus"               # Model: opus, sonnet, or full name
PROMPT="continue"          # Prompt sent each session
DRY_RUN=false
BUDGET=""
MAX_CONSECUTIVE_ERRORS=5   # Stop after this many errors in a row

# --- Paths ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_DIR="$SCRIPT_DIR/verify/logs"
RUNNER_LOG="$LOG_DIR/runner.log"
PROGRESS_FILE="$SCRIPT_DIR/progress.md"

# --- Parse CLI arguments ---
while [[ $# -gt 0 ]]; do
    case $1 in
        --dry-run)      DRY_RUN=true; shift ;;
        --max-turns)    MAX_TURNS="$2"; shift 2 ;;
        --model)        MODEL="$2"; shift 2 ;;
        --budget)       BUDGET="$2"; shift 2 ;;
        --retry-delay)  RETRY_DELAY="$2"; shift 2 ;;
        --error-delay)  ERROR_DELAY="$2"; shift 2 ;;
        --prompt)       PROMPT="$2"; shift 2 ;;
        -h|--help)
            echo "Vunnix Autonomous Development Runner"
            echo ""
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --dry-run          Show command without executing"
            echo "  --max-turns N      Agentic turns per session (default: 50)"
            echo "  --model MODEL      Model to use: opus, sonnet (default: opus)"
            echo "  --budget USD       Per-session spend limit"
            echo "  --retry-delay SEC  Wait on rate limit (default: 300)"
            echo "  --error-delay SEC  Wait on errors (default: 60)"
            echo "  --prompt TEXT      Override prompt (default: continue)"
            echo "  -h, --help         Show this help"
            echo ""
            echo "Monitor in another terminal:"
            echo "  bash verify/watch_progress.sh    Progress dashboard"
            echo "  tail -f verify/logs/runner.log   Runner log"
            exit 0 ;;
        *)
            echo "Unknown option: $1 (use --help for usage)"
            exit 1 ;;
    esac
done

# --- Setup ---
mkdir -p "$LOG_DIR"

# --- Helper functions ---

log() {
    local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $*"
    echo "$msg"
    echo "$msg" >> "$RUNNER_LOG"
}

tasks_remaining() {
    local count
    count=$(grep -c '^\- \[ \]' "$PROGRESS_FILE" 2>/dev/null) || count=116
    echo "$count"
}

tasks_completed() {
    local count
    count=$(grep -c '^\- \[x\]' "$PROGRESS_FILE" 2>/dev/null) || count=0
    echo "$count"
}

show_progress() {
    local done
    local remaining
    done=$(tasks_completed)
    remaining=$(tasks_remaining)
    local total=$((done + remaining))
    local pct=0
    if [ "$total" -gt 0 ]; then
        pct=$((done * 100 / total))
    fi
    log "Progress: $done/$total ($pct%) — $remaining remaining"
}

current_task() {
    # Extract the bolded task ID (e.g., **T1:**)
    grep -m1 '^\- \[ \] \*\*T' "$PROGRESS_FILE" 2>/dev/null \
        | sed 's/.*\*\*\(T[0-9]*\):\*\*.*/\1/' \
        || echo "unknown"
}

is_rate_limited() {
    local logfile="$1"
    grep -qi \
        -e "rate.limit" \
        -e "too.many.requests" \
        -e "\"error\".*429" \
        -e "overloaded" \
        -e "capacity" \
        -e "rate_limit" \
        "$logfile" 2>/dev/null
}

is_context_full() {
    local logfile="$1"
    grep -qi \
        -e "context.*full" \
        -e "context.*limit" \
        -e "token.*limit" \
        -e "conversation.*too.*long" \
        -e "max.*context" \
        "$logfile" 2>/dev/null
}

is_max_turns_reached() {
    local logfile="$1"
    grep -qi \
        -e "max.*turns" \
        -e "turn.*limit" \
        "$logfile" 2>/dev/null
}

# --- Graceful shutdown ---

RUNNING=true
CLAUDE_PID=""

cleanup() {
    log ""
    log "Received shutdown signal — stopping after current session"
    RUNNING=false
    if [ -n "$CLAUDE_PID" ] && kill -0 "$CLAUDE_PID" 2>/dev/null; then
        log "Waiting for Claude session (PID $CLAUDE_PID) to finish..."
        wait "$CLAUDE_PID" 2>/dev/null
    fi
    log ""
    show_progress
    log "Runner stopped gracefully"
    exit 0
}

trap cleanup SIGINT SIGTERM

# --- Build claude command ---

build_cmd() {
    local cmd="claude"
    cmd+=" -p \"$PROMPT\""
    cmd+=" --output-format stream-json"
    cmd+=" --verbose"
    cmd+=" --model $MODEL"
    cmd+=" --max-turns $MAX_TURNS"
    cmd+=" --dangerously-skip-permissions"
    if [ -n "$BUDGET" ]; then
        cmd+=" --max-budget-usd $BUDGET"
    fi
    echo "$cmd"
}

# --- Main ---

log ""
log "╔══════════════════════════════════════════════════════════╗"
log "║         VUNNIX AUTONOMOUS DEVELOPMENT RUNNER            ║"
log "╚══════════════════════════════════════════════════════════╝"
log ""
log "  Model:         $MODEL"
log "  Max turns:     $MAX_TURNS per session"
log "  Rate limit:    wait ${RETRY_DELAY}s"
log "  Error wait:    ${ERROR_DELAY}s"
log "  Between:       ${BETWEEN_SESSIONS}s"
log "  Budget:        ${BUDGET:-unlimited} per session"
log "  Max errors:    $MAX_CONSECUTIVE_ERRORS consecutive"
log "  Working dir:   $SCRIPT_DIR"
log "  Logs:          $LOG_DIR"
log ""
show_progress
log ""

# Dry run: show command and exit
if $DRY_RUN; then
    log "[DRY RUN] Would execute:"
    log "  $(build_cmd)"
    log ""
    log "[DRY RUN] In directory: $SCRIPT_DIR"
    log "[DRY RUN] Logs to: $LOG_DIR"
    log "[DRY RUN] Exiting without running."
    exit 0
fi

# --- Session loop ---

session_num=0
consecutive_errors=0

while $RUNNING; do
    # Check if all tasks are done
    remaining=$(tasks_remaining)
    if [ "$remaining" -eq 0 ]; then
        log ""
        log "╔══════════════════════════════════════════════════════════╗"
        log "║              ALL 116 TASKS COMPLETE!                    ║"
        log "╚══════════════════════════════════════════════════════════╝"
        log ""
        show_progress
        exit 0
    fi

    session_num=$((session_num + 1))
    session_ts=$(date '+%Y%m%d_%H%M%S')
    session_log="$LOG_DIR/session_${session_num}_${session_ts}.jsonl"
    task_id=$(current_task)

    log ""
    log "━━━ Session $session_num starting ━━━ Current: $task_id ━━━"

    # Run Claude in non-interactive mode
    # All output (stdout + stderr) goes to the session log file.
    # The terminal only shows our log() messages for clean monitoring.
    cd "$SCRIPT_DIR"
    claude -p "$PROMPT" \
        --output-format stream-json \
        --verbose \
        --model "$MODEL" \
        --max-turns "$MAX_TURNS" \
        --dangerously-skip-permissions \
        ${BUDGET:+--max-budget-usd "$BUDGET"} \
        > "$session_log" 2>&1 &
    CLAUDE_PID=$!

    # Wait for Claude to finish
    wait "$CLAUDE_PID" 2>/dev/null
    exit_code=$?
    CLAUDE_PID=""

    log "Session $session_num ended (exit code: $exit_code)"

    # --- Analyze session outcome ---

    if [ $exit_code -eq 0 ]; then
        # Success — check if progress was made
        consecutive_errors=0
        new_remaining=$(tasks_remaining)

        if [ "$new_remaining" -lt "$remaining" ]; then
            tasks_done=$((remaining - new_remaining))
            log "Completed $tasks_done task(s) this session"
        elif is_max_turns_reached "$session_log"; then
            log "Max turns reached (may be mid-task — will continue next session)"
        else
            log "No new tasks completed (may be working on a complex task)"
        fi

        sleep "$BETWEEN_SESSIONS"

    elif is_rate_limited "$session_log"; then
        consecutive_errors=$((consecutive_errors + 1))
        log "Rate limited. Waiting ${RETRY_DELAY}s before retry..."
        sleep "$RETRY_DELAY"

    elif is_context_full "$session_log"; then
        # Context full is expected behavior, not an error
        consecutive_errors=0
        log "Context full — starting fresh session"
        sleep "$BETWEEN_SESSIONS"

    else
        consecutive_errors=$((consecutive_errors + 1))
        log "Session error (exit $exit_code). Waiting ${ERROR_DELAY}s..."

        # Log last few lines for debugging
        if [ -f "$session_log" ]; then
            last_output=$(tail -5 "$session_log" 2>/dev/null | head -c 500)
            if [ -n "$last_output" ]; then
                log "Last output: $last_output"
            fi
        fi

        sleep "$ERROR_DELAY"
    fi

    # Safety: stop if too many consecutive errors
    if [ "$consecutive_errors" -ge "$MAX_CONSECUTIVE_ERRORS" ]; then
        log ""
        log "ERROR: $MAX_CONSECUTIVE_ERRORS consecutive errors — stopping"
        log "Check session logs at: $LOG_DIR"
        log ""
        show_progress
        exit 1
    fi

    show_progress
done

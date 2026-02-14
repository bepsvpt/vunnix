#!/usr/bin/env bash
#
# Vunnix Output Formatter
#
# Transforms raw Claude CLI JSON output into the structured result payload
# expected by the Vunnix Runner Result API (POST /api/v1/tasks/:id/result).
#
# The Claude CLI (--output-format json) produces a JSON object with cost,
# duration, and result fields. This script extracts the AI's structured output,
# validates it has the required shape for the task type, and wraps it in the
# API envelope defined in §20.4.
#
# Task types and their expected result schemas (§14.4):
#   code_review     — { version, summary, findings[], labels[], commit_status }
#   security_audit  — Same schema as code_review
#   feature_dev     — { version, branch, mr_title, mr_description, files_changed[], tests_added, notes }
#   ui_adjustment   — Same schema as feature_dev
#   issue_discussion — { version, response, references[] }
#
# Usage:
#   format-output.sh <task-type> <claude-output-file> [options]
#
# Arguments:
#   task-type          — One of: code_review, security_audit, feature_dev, ui_adjustment, issue_discussion
#   claude-output-file — Path to the Claude CLI JSON output file
#
# Options:
#   --strategy <name>      — Strategy name (e.g., frontend-review, backend-review)
#   --executor-version <v> — Executor image version (default: from VUNNIX_EXECUTOR_VERSION env)
#   --duration <seconds>   — Execution duration in seconds (default: 0)
#   --output <path>        — Write formatted result to file (default: stdout)
#
# Exit codes:
#   0 — Formatted successfully
#   1 — Invalid arguments or missing input file
#   2 — Claude CLI output is not valid JSON
#   3 — Result does not match expected schema for task type
#
# Output:
#   JSON payload matching §20.4 Runner Result API request body:
#   {
#     "status": "completed",
#     "result": { ... },             // Task-type-specific structured output
#     "tokens": { "input": N, "output": N, "thinking": N },
#     "duration_seconds": N,
#     "prompt_version": { "skill": "...", "claude_md": "...", "schema": "..." }
#   }
#
# @see §14.4 Structured Output Schemas
# @see §20.4 Runner Result API

set -euo pipefail

# ── Constants ────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCHEMA_VERSION="1.0"

# ── Usage ────────────────────────────────────────────────────────────
usage() {
    echo "Usage: format-output.sh <task-type> <claude-output-file> [options]"
    echo ""
    echo "Task types: code_review, security_audit, feature_dev, ui_adjustment, issue_discussion"
    echo ""
    echo "Options:"
    echo "  --strategy <name>        Strategy name (e.g., frontend-review)"
    echo "  --executor-version <v>   Executor version (default: \$VUNNIX_EXECUTOR_VERSION)"
    echo "  --duration <seconds>     Execution duration (default: 0)"
    echo "  --output <path>          Output file (default: stdout)"
    exit 1
}

# ── Logging ──────────────────────────────────────────────────────────
log() {
    echo "[format-output] $*" >&2
}

log_error() {
    echo "[format-output] ERROR: $*" >&2
}

# ── Parse arguments ──────────────────────────────────────────────────
if [[ $# -lt 2 ]]; then
    usage
fi

TASK_TYPE="$1"
CLAUDE_OUTPUT_FILE="$2"
shift 2

STRATEGY="${VUNNIX_STRATEGY:-unknown}"
EXECUTOR_VERSION="${VUNNIX_EXECUTOR_VERSION:-unknown}"
DURATION=0
OUTPUT_FILE=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --strategy)
            STRATEGY="$2"
            shift 2
            ;;
        --executor-version)
            EXECUTOR_VERSION="$2"
            shift 2
            ;;
        --duration)
            DURATION="$2"
            shift 2
            ;;
        --output)
            OUTPUT_FILE="$2"
            shift 2
            ;;
        *)
            log_error "Unknown option: $1"
            usage
            ;;
    esac
done

# ── Validate task type ───────────────────────────────────────────────
case "$TASK_TYPE" in
    code_review|security_audit|feature_dev|ui_adjustment|issue_discussion)
        ;;
    *)
        log_error "Unknown task type: $TASK_TYPE"
        log_error "Expected: code_review, security_audit, feature_dev, ui_adjustment, issue_discussion"
        exit 1
        ;;
esac

# ── Read and validate Claude CLI output ──────────────────────────────
if [[ ! -f "$CLAUDE_OUTPUT_FILE" ]]; then
    log_error "Claude output file not found: $CLAUDE_OUTPUT_FILE"
    exit 1
fi

if [[ ! -s "$CLAUDE_OUTPUT_FILE" ]]; then
    log_error "Claude output file is empty: $CLAUDE_OUTPUT_FILE"
    exit 2
fi

# Validate it's valid JSON
if ! jq empty "$CLAUDE_OUTPUT_FILE" 2>/dev/null; then
    log_error "Claude output is not valid JSON"
    exit 2
fi

# ── Extract the AI's structured result ───────────────────────────────
# Claude CLI --output-format json produces:
# {
#   "type": "result",
#   "subtype": "success",
#   "cost_usd": 0.05,
#   "duration_ms": 15000,
#   "duration_api_ms": 12000,
#   "is_error": false,
#   "num_turns": 5,
#   "result": "...the AI's text output...",
#   "session_id": "..."
# }
#
# The AI's output is in the "result" field as a string. Since we instructed
# the executor CLAUDE.md to output strict JSON (no markdown fencing), we
# parse the result string as JSON.

CLAUDE_RESULT=$(jq -r '.result // empty' "$CLAUDE_OUTPUT_FILE" 2>/dev/null)

if [[ -z "$CLAUDE_RESULT" ]]; then
    # Fallback: maybe the file IS the raw result (not wrapped by CLI)
    CLAUDE_RESULT=$(cat "$CLAUDE_OUTPUT_FILE")
    log "No .result field found — treating entire file as result"
fi

# Try to parse the result as JSON
PARSED_RESULT=$(echo "$CLAUDE_RESULT" | jq '.' 2>/dev/null) || {
    # The result might have markdown fencing despite our instructions — strip it
    STRIPPED=$(echo "$CLAUDE_RESULT" | sed -n '/^```json/,/^```$/p' | sed '1d;$d')
    if [[ -n "$STRIPPED" ]]; then
        PARSED_RESULT=$(echo "$STRIPPED" | jq '.' 2>/dev/null) || {
            log_error "Result is not valid JSON (even after stripping markdown fencing)"
            exit 2
        }
        log "Stripped markdown fencing from result"
    else
        log_error "Result string is not valid JSON"
        exit 2
    fi
}

# ── Extract token usage from Claude CLI output ───────────────────────
# Claude CLI JSON includes cost_usd but not raw token counts.
# We extract what's available and estimate tokens from cost if needed.
TOKENS_INPUT=$(jq -r '.usage.input_tokens // .input_tokens // 0' "$CLAUDE_OUTPUT_FILE" 2>/dev/null || echo "0")
TOKENS_OUTPUT=$(jq -r '.usage.output_tokens // .output_tokens // 0' "$CLAUDE_OUTPUT_FILE" 2>/dev/null || echo "0")
TOKENS_THINKING=$(jq -r '.usage.thinking_tokens // .thinking_tokens // 0' "$CLAUDE_OUTPUT_FILE" 2>/dev/null || echo "0")
COST_USD=$(jq -r '.cost_usd // 0' "$CLAUDE_OUTPUT_FILE" 2>/dev/null || echo "0")
CLI_DURATION_MS=$(jq -r '.duration_ms // 0' "$CLAUDE_OUTPUT_FILE" 2>/dev/null || echo "0")

# Use CLI-reported duration if our duration is 0
if [[ "$DURATION" == "0" && "$CLI_DURATION_MS" != "0" ]]; then
    DURATION=$(( CLI_DURATION_MS / 1000 ))
fi

# ── Schema validation ────────────────────────────────────────────────
# Validate the parsed result has the required fields for the task type.
# This is a lightweight check — the backend does full schema validation (T30/T31).

validate_code_review() {
    local has_summary has_findings
    has_summary=$(echo "$PARSED_RESULT" | jq 'has("summary")' 2>/dev/null)
    has_findings=$(echo "$PARSED_RESULT" | jq 'has("findings")' 2>/dev/null)

    if [[ "$has_summary" != "true" ]]; then
        log_error "Code review result missing required field: summary"
        return 1
    fi
    if [[ "$has_findings" != "true" ]]; then
        log_error "Code review result missing required field: findings"
        return 1
    fi

    # Validate summary has required subfields
    local has_risk_level
    has_risk_level=$(echo "$PARSED_RESULT" | jq '.summary | has("risk_level")' 2>/dev/null)
    if [[ "$has_risk_level" != "true" ]]; then
        log_error "Code review summary missing required field: risk_level"
        return 1
    fi

    log "Code review schema validation passed"
    return 0
}

validate_feature_dev() {
    local has_branch has_files_changed
    has_branch=$(echo "$PARSED_RESULT" | jq 'has("branch")' 2>/dev/null)
    has_files_changed=$(echo "$PARSED_RESULT" | jq 'has("files_changed")' 2>/dev/null)

    if [[ "$has_branch" != "true" ]]; then
        log_error "Feature dev result missing required field: branch"
        return 1
    fi
    if [[ "$has_files_changed" != "true" ]]; then
        log_error "Feature dev result missing required field: files_changed"
        return 1
    fi

    log "Feature dev schema validation passed"
    return 0
}

validate_issue_discussion() {
    local has_response
    has_response=$(echo "$PARSED_RESULT" | jq 'has("response")' 2>/dev/null)

    if [[ "$has_response" != "true" ]]; then
        log_error "Issue discussion result missing required field: response"
        return 1
    fi

    log "Issue discussion schema validation passed"
    return 0
}

# Run validation for the task type
case "$TASK_TYPE" in
    code_review|security_audit)
        if ! validate_code_review; then
            exit 3
        fi
        SCHEMA_NAME="review:${SCHEMA_VERSION}"
        ;;
    feature_dev|ui_adjustment)
        if ! validate_feature_dev; then
            exit 3
        fi
        SCHEMA_NAME="feature:${SCHEMA_VERSION}"
        ;;
    issue_discussion)
        if ! validate_issue_discussion; then
            exit 3
        fi
        SCHEMA_NAME="discussion:${SCHEMA_VERSION}"
        ;;
esac

# ── Ensure version field ─────────────────────────────────────────────
# Add version field if the AI didn't include it
PARSED_RESULT=$(echo "$PARSED_RESULT" | jq --arg v "$SCHEMA_VERSION" '
    if has("version") then . else { version: $v } + . end
')

# ── Build the API payload (§20.4) ────────────────────────────────────
PAYLOAD=$(jq -n \
    --arg status "completed" \
    --argjson result "$PARSED_RESULT" \
    --argjson input_tokens "$TOKENS_INPUT" \
    --argjson output_tokens "$TOKENS_OUTPUT" \
    --argjson thinking_tokens "$TOKENS_THINKING" \
    --argjson duration "$DURATION" \
    --arg strategy "$STRATEGY" \
    --arg executor_version "$EXECUTOR_VERSION" \
    --arg schema_name "$SCHEMA_NAME" \
    '{
        status: $status,
        result: $result,
        tokens: {
            input: $input_tokens,
            output: $output_tokens,
            thinking: $thinking_tokens
        },
        duration_seconds: $duration,
        prompt_version: {
            skill: ($strategy + ":" + $executor_version),
            claude_md: ("executor:" + $executor_version),
            schema: $schema_name
        }
    }')

# ── Output ───────────────────────────────────────────────────────────
if [[ -n "$OUTPUT_FILE" ]]; then
    echo "$PAYLOAD" > "$OUTPUT_FILE"
    log "Formatted result written to $OUTPUT_FILE"
else
    echo "$PAYLOAD"
fi

exit 0

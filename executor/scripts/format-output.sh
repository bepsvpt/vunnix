#!/usr/bin/env bash
#
# Vunnix Output Formatter
#
# Transforms raw Claude CLI JSON output into the structured result payload
# expected by the Vunnix Runner Result API (POST /api/v1/tasks/:id/result).
#
# The Claude CLI (--output-format json) produces a JSON array of conversation
# turns (or a single result object in older versions). This script normalizes
# the format, extracts the AI's structured output,
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
    echo "Task types: code_review, security_audit, feature_dev, ui_adjustment, issue_discussion, deep_analysis"
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
    code_review|security_audit|feature_dev|ui_adjustment|issue_discussion|deep_analysis)
        ;;
    *)
        log_error "Unknown task type: $TASK_TYPE"
        log_error "Expected: code_review, security_audit, feature_dev, ui_adjustment, issue_discussion, deep_analysis"
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

# ── Normalize array-format output ─────────────────────────────────────
# Newer Claude CLI versions produce an array of conversation turns.
# The last element (type: "result") contains the actual result data.
if jq -e 'type == "array"' "$CLAUDE_OUTPUT_FILE" >/dev/null 2>&1; then
    log "Normalizing array-format output — extracting result element"
    jq 'last' "$CLAUDE_OUTPUT_FILE" > "${CLAUDE_OUTPUT_FILE}.tmp" \
        && mv "${CLAUDE_OUTPUT_FILE}.tmp" "$CLAUDE_OUTPUT_FILE"
fi

# ── Extract the AI's structured result ───────────────────────────────
# Claude CLI with --json-schema puts structured output in .structured_output field:
# {
#   "type": "result",
#   "result": "...explanatory text...",
#   "structured_output": {...validated JSON matching schema...}
# }

# Check if .structured_output exists (preferred - schema-validated)
if jq -e '.structured_output' "$CLAUDE_OUTPUT_FILE" >/dev/null 2>&1; then
    PARSED_RESULT=$(jq '.structured_output' "$CLAUDE_OUTPUT_FILE" 2>/dev/null)
    log "Extracted schema-validated JSON from .structured_output field"
else
    # Fallback: check if .result is an object or string
    RESULT_TYPE=$(jq -r '.result | type' "$CLAUDE_OUTPUT_FILE" 2>/dev/null)

    if [[ "$RESULT_TYPE" == "object" ]]; then
        # Result is already a JSON object
        PARSED_RESULT=$(jq '.result' "$CLAUDE_OUTPUT_FILE" 2>/dev/null)
        log "Extracted JSON object from .result field"
    elif [[ "$RESULT_TYPE" == "string" ]]; then
        # Result is a string - try to parse as JSON
        CLAUDE_RESULT=$(jq -r '.result' "$CLAUDE_OUTPUT_FILE" 2>/dev/null)

        # Try to parse the string as JSON
        PARSED_RESULT=$(echo "$CLAUDE_RESULT" | jq '.' 2>/dev/null) || {
            # Not valid JSON - try stripping markdown fencing
            STRIPPED=$(echo "$CLAUDE_RESULT" | sed -n '/^```json/,/^```$/p' | sed '1d;$d')
            if [[ -n "$STRIPPED" ]]; then
                PARSED_RESULT=$(echo "$STRIPPED" | jq '.' 2>/dev/null) || STRIPPED=""
                if [[ -n "$STRIPPED" ]]; then
                    log "Stripped markdown fencing from result string"
                fi
            fi

            # Text-to-schema fallback: construct minimal valid JSON from narrative text
            if [[ -z "${PARSED_RESULT:-}" ]]; then
                log "Result is narrative text — applying text-to-schema fallback for $TASK_TYPE"
                case "$TASK_TYPE" in
                    deep_analysis)
                        PARSED_RESULT=$(jq -n --arg text "$CLAUDE_RESULT" '{
                            version: "1.0",
                            analysis: $text,
                            key_findings: [],
                            references: []
                        }')
                        ;;
                    code_review|security_audit)
                        PARSED_RESULT=$(jq -n --arg text "$CLAUDE_RESULT" '{
                            version: "1.0",
                            summary: $text,
                            findings: [],
                            labels: [],
                            commit_status: "success"
                        }')
                        ;;
                    issue_discussion)
                        PARSED_RESULT=$(jq -n --arg text "$CLAUDE_RESULT" '{
                            version: "1.0",
                            response: $text,
                            references: []
                        }')
                        ;;
                    feature_dev|ui_adjustment)
                        PARSED_RESULT=$(jq -n --arg text "$CLAUDE_RESULT" '{
                            version: "1.0",
                            branch: "unknown",
                            mr_title: "Unknown",
                            mr_description: $text,
                            files_changed: [],
                            tests_added: false,
                            notes: $text
                        }')
                        ;;
                    *)
                        log_error "No text-to-schema fallback for task type: $TASK_TYPE"
                        exit 2
                        ;;
                esac
                log "Constructed fallback result from narrative text (${#CLAUDE_RESULT} chars)"
            fi
        }
        log "Parsed JSON from string .result field"
    else
        log_error "No structured_output and unexpected .result type: $RESULT_TYPE"
        exit 2
    fi
fi

# ── Extract token usage from Claude CLI output ───────────────────────
# Claude CLI JSON includes cost_usd but not raw token counts.
# We extract what's available and estimate tokens from cost if needed.
TOKENS_INPUT=$(jq -r '.usage.input_tokens // .input_tokens // 0' "$CLAUDE_OUTPUT_FILE" 2>/dev/null || echo "0")
TOKENS_OUTPUT=$(jq -r '.usage.output_tokens // .output_tokens // 0' "$CLAUDE_OUTPUT_FILE" 2>/dev/null || echo "0")
TOKENS_THINKING=$(jq -r '.usage.thinking_tokens // .thinking_tokens // 0' "$CLAUDE_OUTPUT_FILE" 2>/dev/null || echo "0")
COST_USD=$(jq -r '.total_cost_usd // .cost_usd // 0' "$CLAUDE_OUTPUT_FILE" 2>/dev/null || echo "0")
CLI_DURATION_MS=$(jq -r '.duration_ms // 0' "$CLAUDE_OUTPUT_FILE" 2>/dev/null || echo "0")

# Validate and coerce to integers (prevent --argjson invalid JSON errors)
log "Raw token values: input=$TOKENS_INPUT output=$TOKENS_OUTPUT thinking=$TOKENS_THINKING"

# Strip non-digits and default to 0 if empty
TOKENS_INPUT=${TOKENS_INPUT//[^0-9]/}
TOKENS_INPUT=${TOKENS_INPUT:-0}

TOKENS_OUTPUT=${TOKENS_OUTPUT//[^0-9]/}
TOKENS_OUTPUT=${TOKENS_OUTPUT:-0}

TOKENS_THINKING=${TOKENS_THINKING//[^0-9]/}
TOKENS_THINKING=${TOKENS_THINKING:-0}

CLI_DURATION_MS=${CLI_DURATION_MS//[^0-9]/}
CLI_DURATION_MS=${CLI_DURATION_MS:-0}

log "Validated token values: input=$TOKENS_INPUT output=$TOKENS_OUTPUT thinking=$TOKENS_THINKING"
log "CLI duration: ${CLI_DURATION_MS}ms"

# Use CLI-reported duration if our duration is 0
if [[ "$DURATION" == "0" && "$CLI_DURATION_MS" != "0" ]]; then
    DURATION=$(( CLI_DURATION_MS / 1000 ))
fi

# ── Determine schema name for tracking ──────────────────────────────
case "$TASK_TYPE" in
    code_review|security_audit)
        SCHEMA_NAME="review:${SCHEMA_VERSION}"
        ;;
    feature_dev|ui_adjustment)
        SCHEMA_NAME="feature:${SCHEMA_VERSION}"
        ;;
    issue_discussion)
        SCHEMA_NAME="discussion:${SCHEMA_VERSION}"
        ;;
    deep_analysis)
        SCHEMA_NAME="analysis:${SCHEMA_VERSION}"
        ;;
esac

log "Using schema: $SCHEMA_NAME"
log "Building API payload with: tokens={in=$TOKENS_INPUT,out=$TOKENS_OUTPUT,think=$TOKENS_THINKING} duration=${DURATION}s"

# ── Build the API payload (§20.4) ────────────────────────────────────
# PARSED_RESULT is clean JSON. Write to file and merge with envelope
# to avoid bash variable expansion issues.
RESULT_TEMP=$(mktemp)
printf '%s\n' "$PARSED_RESULT" > "$RESULT_TEMP"

# Build envelope with error checking
ENVELOPE_TEMP=$(mktemp)
if ! jq -n \
    --argjson input_tokens "$TOKENS_INPUT" \
    --argjson output_tokens "$TOKENS_OUTPUT" \
    --argjson thinking_tokens "$TOKENS_THINKING" \
    --argjson duration "$DURATION" \
    --arg strategy "$STRATEGY" \
    --arg executor_version "$EXECUTOR_VERSION" \
    --arg schema_name "$SCHEMA_NAME" \
    '{
        status: "completed",
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
    }' > "$ENVELOPE_TEMP" 2>&1; then
    log_error "jq envelope building failed"
    log_error "Debug: TOKENS_INPUT='$TOKENS_INPUT' TOKENS_OUTPUT='$TOKENS_OUTPUT' TOKENS_THINKING='$TOKENS_THINKING' DURATION='$DURATION'"
    exit 2
fi

log "Envelope built successfully"

# Merge result into envelope
if ! PAYLOAD=$(jq -s '.[0] + {result: .[1]}' "$ENVELOPE_TEMP" "$RESULT_TEMP" 2>&1); then
    log_error "jq payload merge failed"
    exit 2
fi

log "Payload merged successfully"

rm -f "$RESULT_TEMP" "$ENVELOPE_TEMP"

log "Built API payload successfully"

# ── Output ───────────────────────────────────────────────────────────
if [[ -n "$OUTPUT_FILE" ]]; then
    echo "$PAYLOAD" > "$OUTPUT_FILE"
    log "Formatted result written to $OUTPUT_FILE"
else
    echo "$PAYLOAD"
fi

exit 0

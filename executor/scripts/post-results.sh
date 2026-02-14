#!/usr/bin/env bash
#
# Vunnix Result Poster
#
# POSTs structured JSON result payloads to the Vunnix Runner Result API
# (POST /api/v1/tasks/:id/result) with task-scoped bearer token authentication.
#
# Handles both success and failure results. Includes retry with exponential
# backoff for transient HTTP errors (429, 5xx). Non-retryable errors (4xx
# except 429) fail immediately.
#
# Usage:
#   post-results.sh <api-url> <task-id> <token> <payload-file> [options]
#
# Arguments:
#   api-url       — Vunnix API base URL (e.g., https://vunnix.example.com)
#   task-id       — Task ID in the Vunnix database
#   token         — Task-scoped HMAC-SHA256 bearer token (D127)
#   payload-file  — Path to JSON payload file (output of format-output.sh)
#
# Options:
#   --max-retries <n>   — Maximum retry attempts (default: 3)
#   --timeout <seconds> — HTTP request timeout (default: 30)
#   --verbose           — Print response body on success
#
# Exit codes:
#   0 — Result posted successfully
#   1 — Invalid arguments or missing payload file
#   2 — HTTP error after all retries exhausted
#   3 — Authentication error (401) — token invalid or expired
#   4 — Task not found (404)
#   5 — Schema validation error (422)
#
# @see §20.4 Runner Result API
# @see §19.3 Job Timeout & Retry Policy

set -euo pipefail

# ── Constants ────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RESPONSE_FILE="/tmp/vunnix-api-response.json"

# Backoff schedule: 5s → 15s → 45s (for HTTP transport retries only;
# the broader 30s → 2m → 8m backoff in T37 is for task-level retries
# managed by the Laravel queue, not this script)
BACKOFF_BASE=5
BACKOFF_MULTIPLIER=3

# ── Usage ────────────────────────────────────────────────────────────
usage() {
    echo "Usage: post-results.sh <api-url> <task-id> <token> <payload-file> [options]"
    echo ""
    echo "Options:"
    echo "  --max-retries <n>    Max retry attempts (default: 3)"
    echo "  --timeout <seconds>  HTTP timeout (default: 30)"
    echo "  --verbose            Print response body on success"
    exit 1
}

# ── Logging ──────────────────────────────────────────────────────────
log() {
    echo "[post-results] $(date -u '+%Y-%m-%dT%H:%M:%SZ') $*" >&2
}

log_error() {
    echo "[post-results] $(date -u '+%Y-%m-%dT%H:%M:%SZ') ERROR: $*" >&2
}

# ── Parse arguments ──────────────────────────────────────────────────
if [[ $# -lt 4 ]]; then
    usage
fi

API_URL="$1"
TASK_ID="$2"
TOKEN="$3"
PAYLOAD_FILE="$4"
shift 4

MAX_RETRIES=3
HTTP_TIMEOUT=30
VERBOSE=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --max-retries)
            MAX_RETRIES="$2"
            shift 2
            ;;
        --timeout)
            HTTP_TIMEOUT="$2"
            shift 2
            ;;
        --verbose)
            VERBOSE=true
            shift
            ;;
        *)
            log_error "Unknown option: $1"
            usage
            ;;
    esac
done

# ── Validate inputs ─────────────────────────────────────────────────
if [[ ! -f "$PAYLOAD_FILE" ]]; then
    log_error "Payload file not found: $PAYLOAD_FILE"
    exit 1
fi

if [[ ! -s "$PAYLOAD_FILE" ]]; then
    log_error "Payload file is empty: $PAYLOAD_FILE"
    exit 1
fi

# Validate payload is valid JSON
if ! jq empty "$PAYLOAD_FILE" 2>/dev/null; then
    log_error "Payload file is not valid JSON: $PAYLOAD_FILE"
    exit 1
fi

# Strip trailing slashes from API URL
API_URL="${API_URL%/}"
ENDPOINT="${API_URL}/api/v1/tasks/${TASK_ID}/result"

log "Posting result to ${ENDPOINT}"

# ── HTTP POST with retry ────────────────────────────────────────────
attempt=0
while [[ $attempt -le $MAX_RETRIES ]]; do
    if [[ $attempt -gt 0 ]]; then
        local_backoff=$(( BACKOFF_BASE * (BACKOFF_MULTIPLIER ** (attempt - 1)) ))
        log "Retry ${attempt}/${MAX_RETRIES} — waiting ${local_backoff}s..."
        sleep "$local_backoff"
    fi

    # Make the HTTP request
    HTTP_CODE=$(curl -s \
        -o "$RESPONSE_FILE" \
        -w "%{http_code}" \
        -X POST "$ENDPOINT" \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "Accept: application/json" \
        -H "X-Vunnix-Executor: true" \
        --max-time "$HTTP_TIMEOUT" \
        -d @"$PAYLOAD_FILE" 2>/dev/null) || {
        log_error "curl failed (network error or timeout)"
        attempt=$((attempt + 1))
        continue
    }

    log "HTTP response: ${HTTP_CODE}"

    # ── Handle response codes ────────────────────────────────────
    case "$HTTP_CODE" in
        2[0-9][0-9])
            # 2xx — Success
            log "Result posted successfully (HTTP ${HTTP_CODE})"
            if [[ "$VERBOSE" == "true" && -f "$RESPONSE_FILE" ]]; then
                cat "$RESPONSE_FILE" >&2
            fi
            exit 0
            ;;
        401)
            # Authentication failure — token invalid or expired
            log_error "Authentication failed (HTTP 401) — token may be invalid or expired"
            if [[ -f "$RESPONSE_FILE" ]]; then
                log_error "Response: $(cat "$RESPONSE_FILE")"
            fi
            exit 3
            ;;
        404)
            # Task not found
            log_error "Task not found (HTTP 404) — task ID ${TASK_ID} does not exist"
            if [[ -f "$RESPONSE_FILE" ]]; then
                log_error "Response: $(cat "$RESPONSE_FILE")"
            fi
            exit 4
            ;;
        422)
            # Schema validation error — result doesn't match expected format
            log_error "Schema validation failed (HTTP 422) — result format rejected by API"
            if [[ -f "$RESPONSE_FILE" ]]; then
                log_error "Response: $(cat "$RESPONSE_FILE")"
            fi
            exit 5
            ;;
        429|5[0-9][0-9])
            # Transient errors — retry
            log_error "Transient error (HTTP ${HTTP_CODE}) — will retry"
            attempt=$((attempt + 1))
            continue
            ;;
        *)
            # Other client errors (400, 403, etc.) — don't retry
            log_error "Non-retryable error (HTTP ${HTTP_CODE})"
            if [[ -f "$RESPONSE_FILE" ]]; then
                log_error "Response: $(cat "$RESPONSE_FILE")"
            fi
            exit 2
            ;;
    esac
done

# All retries exhausted
log_error "All ${MAX_RETRIES} retries exhausted — giving up"
exit 2

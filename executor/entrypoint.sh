#!/usr/bin/env bash
#
# Vunnix Executor Entrypoint
#
# Receives pipeline variables from the Vunnix Task Dispatcher, validates the
# task-scoped bearer token (D127), runs the Claude CLI with the selected skill,
# and POSTs structured results back to the Vunnix API.
#
# Pipeline variables (set by TaskDispatcher::dispatchToRunner):
#   VUNNIX_TASK_ID    — Task ID in the Vunnix database
#   VUNNIX_TASK_TYPE  — Task type (code_review, feature_dev, ui_adjustment, etc.)
#   VUNNIX_STRATEGY   — Review strategy (frontend-review, backend-review, etc.)
#   VUNNIX_SKILLS     — Comma-separated skill names to activate
#   VUNNIX_TOKEN      — Task-scoped HMAC-SHA256 bearer token (TTL = task budget)
#   VUNNIX_API_URL    — Vunnix API base URL for result posting
#
# @see §3.4 Task Dispatcher & Task Executor
# @see §6.7 Executor Image

set -euo pipefail

# ── Constants ────────────────────────────────────────────────────────
EXECUTOR_DIR="/vunnix-executor"
RESULT_FILE="/tmp/vunnix-result.json"
LOG_FILE="/tmp/vunnix-executor.log"
CLAUDE_OUTPUT_FILE="/tmp/vunnix-claude-output.json"

# ── Logging ──────────────────────────────────────────────────────────
log() {
    echo "[vunnix-executor] $(date -u '+%Y-%m-%dT%H:%M:%SZ') $*" | tee -a "$LOG_FILE"
}

log_error() {
    echo "[vunnix-executor] $(date -u '+%Y-%m-%dT%H:%M:%SZ') ERROR: $*" | tee -a "$LOG_FILE" >&2
}

# ── Validate required environment variables ──────────────────────────
validate_env() {
    local missing=()

    [[ -z "${VUNNIX_TASK_ID:-}" ]]   && missing+=("VUNNIX_TASK_ID")
    [[ -z "${VUNNIX_TASK_TYPE:-}" ]] && missing+=("VUNNIX_TASK_TYPE")
    [[ -z "${VUNNIX_STRATEGY:-}" ]]  && missing+=("VUNNIX_STRATEGY")
    [[ -z "${VUNNIX_SKILLS:-}" ]]    && missing+=("VUNNIX_SKILLS")
    [[ -z "${VUNNIX_TOKEN:-}" ]]     && missing+=("VUNNIX_TOKEN")
    [[ -z "${VUNNIX_API_URL:-}" ]]   && missing+=("VUNNIX_API_URL")

    if [[ ${#missing[@]} -gt 0 ]]; then
        log_error "Missing required variables: ${missing[*]}"
        post_failure "missing_variables" "Missing required pipeline variables: ${missing[*]}"
        exit 1
    fi

    log "Task ID: ${VUNNIX_TASK_ID}"
    log "Type: ${VUNNIX_TASK_TYPE}"
    log "Strategy: ${VUNNIX_STRATEGY}"
    log "Skills: ${VUNNIX_SKILLS}"
    log "API URL: ${VUNNIX_API_URL}"
}

# ── Token validation (D127) ─────────────────────────────────────────
# Mirrors TaskTokenService::validate() — stateless HMAC-SHA256 check.
# Token format: base64url(task_id:expiry_unix:hmac_signature)
#
# If the token is expired, the task took too long to be scheduled and
# the result would be stale. Exit with scheduling_timeout status.
validate_token() {
    log "Validating task-scoped token..."

    # Base64url decode: replace -_ with +/, add padding, decode
    local decoded
    decoded=$(echo -n "$VUNNIX_TOKEN" | tr '-_' '+/' | base64 -d 2>/dev/null) || {
        log_error "Token base64 decode failed"
        post_failure "invalid_token" "Task token could not be decoded"
        exit 1
    }

    # Parse token parts: task_id:expiry:signature
    local token_task_id token_expiry token_signature
    IFS=':' read -r token_task_id token_expiry token_signature <<< "$decoded"

    if [[ -z "$token_task_id" || -z "$token_expiry" || -z "$token_signature" ]]; then
        log_error "Token format invalid — expected task_id:expiry:signature"
        post_failure "invalid_token" "Task token format is invalid"
        exit 1
    fi

    # Verify task ID matches
    if [[ "$token_task_id" != "$VUNNIX_TASK_ID" ]]; then
        log_error "Token task ID mismatch: token=$token_task_id expected=$VUNNIX_TASK_ID"
        post_failure "invalid_token" "Task token ID does not match"
        exit 1
    fi

    # Verify not expired
    local now
    now=$(date +%s)

    if [[ "$now" -ge "$token_expiry" ]]; then
        log_error "Token expired: now=$now expiry=$token_expiry (delta=$((now - token_expiry))s)"
        post_failure "scheduling_timeout" "Task token expired — scheduling took too long"
        exit 1
    fi

    local remaining=$(( token_expiry - now ))
    log "Token valid — ${remaining}s remaining until expiry"

    # Note: We do NOT verify the HMAC signature here. The token was generated
    # by the Vunnix backend using APP_KEY, which the executor does not have.
    # The token is validated server-side when the executor POSTs results back
    # via the Runner Result API (T29). The expiry check here is a freshness
    # gate to avoid wasting runner time on stale tasks.
}

# ── Resolve Claude CLI skill flags ───────────────────────────────────
resolve_skill_flags() {
    local skills_csv="$1"
    local flags=""

    # Convert comma-separated skills to --allowedTools flags
    # Each skill maps to a .claude/skills/*.md file in the executor image
    IFS=',' read -ra skill_list <<< "$skills_csv"
    for skill in "${skill_list[@]}"; do
        local skill_file="${EXECUTOR_DIR}/.claude/skills/${skill}.md"
        if [[ -f "$skill_file" ]]; then
            log "Skill found: ${skill}"
        else
            log "Skill file not found: ${skill_file} (will be created by T20-T27)"
        fi
    done

    echo "${skills_csv}"
}

# ── Run Claude CLI ───────────────────────────────────────────────────
run_claude() {
    local skills="$1"
    local start_time
    start_time=$(date +%s)

    log "Starting Claude CLI execution..."

    # The project repo is checked out by GitLab CI into $CI_PROJECT_DIR
    # The executor's .claude/ directory provides Vunnix-level instructions
    # The project's own .claude/ and CLAUDE.md are in the repo checkout
    local project_dir="${CI_PROJECT_DIR:-.}"

    # Copy executor's .claude/ config into the project directory
    # This layers Vunnix instructions on top of the project's own config
    if [[ -d "${EXECUTOR_DIR}/.claude" ]]; then
        # Merge executor skills into project's .claude/skills/ (if it exists)
        mkdir -p "${project_dir}/.claude/skills"
        cp -n "${EXECUTOR_DIR}/.claude/skills/"*.md "${project_dir}/.claude/skills/" 2>/dev/null || true

        # The executor CLAUDE.md is prepended to any existing project CLAUDE.md
        if [[ -f "${EXECUTOR_DIR}/.claude/CLAUDE.md" ]]; then
            if [[ -f "${project_dir}/CLAUDE.md" ]]; then
                local merged
                merged=$(cat "${EXECUTOR_DIR}/.claude/CLAUDE.md" && echo -e "\n---\n" && cat "${project_dir}/CLAUDE.md")
                echo "$merged" > "${project_dir}/CLAUDE.md"
                log "Merged executor CLAUDE.md with project CLAUDE.md"
            else
                cp "${EXECUTOR_DIR}/.claude/CLAUDE.md" "${project_dir}/CLAUDE.md"
                log "Copied executor CLAUDE.md to project"
            fi
        fi
    fi

    # Build the Claude CLI prompt based on task type and strategy
    local prompt
    prompt=$(build_prompt)

    # Run Claude CLI in print mode (non-interactive) with JSON output
    # The --output-format json flag ensures structured output for parsing
    cd "$project_dir"

    claude --print \
        --output-format json \
        --max-turns 30 \
        "$prompt" \
        > "$CLAUDE_OUTPUT_FILE" 2>>"$LOG_FILE" || {
        local exit_code=$?
        log_error "Claude CLI exited with code ${exit_code}"
        return $exit_code
    }

    local end_time
    end_time=$(date +%s)
    local duration=$(( end_time - start_time ))
    log "Claude CLI completed in ${duration}s"

    echo "$duration"
}

# ── Build task prompt ────────────────────────────────────────────────
build_prompt() {
    case "$VUNNIX_TASK_TYPE" in
        code_review)
            echo "Review this merge request. Use the ${VUNNIX_STRATEGY} strategy. Follow the instructions in CLAUDE.md for output format and severity classification. Output your review as structured JSON."
            ;;
        feature_dev)
            echo "Implement the feature described in the task parameters. Follow project conventions from CLAUDE.md. Create clean, tested code. Output your changes as structured JSON."
            ;;
        ui_adjustment)
            echo "Make the UI adjustment described in the task parameters. Use the ${VUNNIX_STRATEGY} strategy. After making changes, capture a screenshot using /vunnix-executor/scripts/capture-screenshot.js. Output your changes as structured JSON."
            ;;
        issue_discussion)
            echo "Answer the question from the issue context. Reference relevant code from the repository. Keep your response concise and actionable. Output as structured JSON."
            ;;
        security_audit)
            echo "Perform a security audit of this merge request. Check for OWASP Top 10 vulnerabilities, auth/authz bypasses, input validation issues, and secret exposure. All findings start at Major severity minimum. Output as structured JSON."
            ;;
        *)
            echo "Execute the ${VUNNIX_TASK_TYPE} task using the ${VUNNIX_STRATEGY} strategy. Follow CLAUDE.md instructions. Output as structured JSON."
            ;;
    esac
}

# ── Post results to Vunnix API ───────────────────────────────────────
post_result() {
    local duration="$1"
    local status="${2:-completed}"

    log "Posting result to Vunnix API..."

    # Read Claude CLI output
    local result_json="{}"
    if [[ -f "$CLAUDE_OUTPUT_FILE" ]]; then
        result_json=$(cat "$CLAUDE_OUTPUT_FILE")
    fi

    # Build the result payload
    local payload
    payload=$(jq -n \
        --arg status "$status" \
        --arg duration "$duration" \
        --arg strategy "$VUNNIX_STRATEGY" \
        --arg executor_version "$VUNNIX_EXECUTOR_VERSION" \
        --argjson result "$result_json" \
        '{
            status: $status,
            result: $result,
            duration: ($duration | tonumber),
            strategy: $strategy,
            executor_version: $executor_version
        }' 2>/dev/null) || {
        # If jq parsing fails (e.g., claude output isn't valid JSON),
        # wrap the raw output as a string
        payload=$(jq -n \
            --arg status "$status" \
            --arg duration "$duration" \
            --arg strategy "$VUNNIX_STRATEGY" \
            --arg executor_version "$VUNNIX_EXECUTOR_VERSION" \
            --arg raw_output "$(cat "$CLAUDE_OUTPUT_FILE" 2>/dev/null || echo '')" \
            '{
                status: $status,
                result: { raw_output: $raw_output },
                duration: ($duration | tonumber),
                strategy: $strategy,
                executor_version: $executor_version
            }')
    }

    # POST to Vunnix Runner Result API (T29)
    local api_url="${VUNNIX_API_URL}/api/v1/tasks/${VUNNIX_TASK_ID}/result"

    local http_code
    http_code=$(curl -s -o /tmp/vunnix-api-response.json -w "%{http_code}" \
        -X POST "$api_url" \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer ${VUNNIX_TOKEN}" \
        -d "$payload") || {
        log_error "Failed to POST result to API"
        return 1
    }

    if [[ "$http_code" -ge 200 && "$http_code" -lt 300 ]]; then
        log "Result posted successfully (HTTP ${http_code})"
    else
        log_error "API returned HTTP ${http_code}"
        cat /tmp/vunnix-api-response.json >> "$LOG_FILE" 2>/dev/null || true
        return 1
    fi
}

# ── Post failure to Vunnix API ───────────────────────────────────────
post_failure() {
    local error_code="$1"
    local error_message="$2"

    log_error "Task failed: ${error_code} — ${error_message}"

    # Build failure payload
    local payload
    payload=$(jq -n \
        --arg status "failed" \
        --arg error_code "$error_code" \
        --arg error_message "$error_message" \
        --arg executor_version "${VUNNIX_EXECUTOR_VERSION:-unknown}" \
        '{
            status: "failed",
            error: $error_code,
            error_message: $error_message,
            duration: 0,
            executor_version: $executor_version
        }')

    # Only attempt to POST if we have the required variables
    if [[ -n "${VUNNIX_API_URL:-}" && -n "${VUNNIX_TASK_ID:-}" && -n "${VUNNIX_TOKEN:-}" ]]; then
        local api_url="${VUNNIX_API_URL}/api/v1/tasks/${VUNNIX_TASK_ID}/result"

        curl -s -o /dev/null \
            -X POST "$api_url" \
            -H "Content-Type: application/json" \
            -H "Authorization: Bearer ${VUNNIX_TOKEN}" \
            -d "$payload" 2>/dev/null || true
    fi
}

# ── Upload debug artifact ────────────────────────────────────────────
save_debug_artifact() {
    # GitLab CI artifacts are collected from specific paths after the job
    local artifact_dir="${CI_PROJECT_DIR:-.}/vunnix-artifacts"
    mkdir -p "$artifact_dir"

    cp "$LOG_FILE" "$artifact_dir/executor.log" 2>/dev/null || true
    cp "$CLAUDE_OUTPUT_FILE" "$artifact_dir/claude-output.json" 2>/dev/null || true

    log "Debug artifacts saved to ${artifact_dir}/"
}

# ── Main ─────────────────────────────────────────────────────────────
main() {
    log "Vunnix Executor v${VUNNIX_EXECUTOR_VERSION} starting"

    # Step 1: Validate environment variables
    validate_env

    # Step 2: Validate task token freshness (D127)
    validate_token

    # Step 3: Resolve skills
    local skills
    skills=$(resolve_skill_flags "$VUNNIX_SKILLS")

    # Step 4: Run Claude CLI
    local duration=0
    if run_claude "$skills"; then
        duration=$?
        # Step 5: Post successful result
        post_result "$duration" "completed"
    else
        local exit_code=$?
        duration=0
        post_failure "claude_cli_error" "Claude CLI exited with code ${exit_code}"
    fi

    # Step 6: Save debug artifacts for GitLab CI collection
    save_debug_artifact

    log "Executor finished"
}

main "$@"

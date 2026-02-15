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
SCRIPTS_DIR="${EXECUTOR_DIR}/scripts"
RESULT_FILE="/tmp/vunnix-result.json"
LOG_FILE="/tmp/vunnix-executor.log"
CLAUDE_OUTPUT_FILE="/tmp/vunnix-claude-output.json"
FORMATTED_RESULT_FILE="/tmp/vunnix-formatted-result.json"

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

    # Base64url decode: replace -_ with +/, restore padding, decode
    local padded decoded
    padded=$(echo -n "$VUNNIX_TOKEN" | tr -- '-_' '+/')
    # Restore base64 padding stripped by PHP's base64UrlEncode
    case $(( ${#padded} % 4 )) in
        2) padded="${padded}==" ;;
        3) padded="${padded}=" ;;
    esac
    decoded=$(echo -n "$padded" | base64 -d 2>/dev/null) || {
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
# Delegates to format-output.sh (T28) for payload construction and
# post-results.sh (T28) for HTTP transport with retry.
# Target: POST /api/v1/tasks/:id/result (§20.4 Runner Result API)
post_result() {
    local duration="$1"

    log "Formatting result with format-output.sh..."

    # Step 1: Format the Claude CLI output into the §20.4 API payload
    if "${SCRIPTS_DIR}/format-output.sh" \
        "$VUNNIX_TASK_TYPE" \
        "$CLAUDE_OUTPUT_FILE" \
        --strategy "$VUNNIX_STRATEGY" \
        --executor-version "$VUNNIX_EXECUTOR_VERSION" \
        --duration "$duration" \
        --output "$FORMATTED_RESULT_FILE" 2>>"$LOG_FILE"; then
        log "Result formatted successfully"
    else
        local format_exit=$?
        log_error "format-output.sh failed (exit ${format_exit}) — posting raw output as failure"
        # If formatting fails, the output didn't match the expected schema.
        # Post a failure so the backend can retry or log for investigation.
        post_failure "format_error" "Output formatting failed (exit ${format_exit}) — result may not match expected schema"
        return 1
    fi

    # Step 2: POST the formatted payload to the Runner Result API
    log "Posting result with post-results.sh..."

    if "${SCRIPTS_DIR}/post-results.sh" \
        "$VUNNIX_API_URL" \
        "$VUNNIX_TASK_ID" \
        "$VUNNIX_TOKEN" \
        "$FORMATTED_RESULT_FILE" 2>>"$LOG_FILE"; then
        log "Result posted successfully"
    else
        local post_exit=$?
        log_error "post-results.sh failed (exit ${post_exit})"
        return 1
    fi
}

# ── Post failure to Vunnix API ───────────────────────────────────────
# Builds a failure payload and delegates to post-results.sh for HTTP transport.
# This is kept as a direct function (not via format-output.sh) because failures
# can occur before Claude runs — there's no CLI output to format.
post_failure() {
    local error_code="$1"
    local error_message="$2"
    local failure_payload_file="/tmp/vunnix-failure-payload.json"

    log_error "Task failed: ${error_code} — ${error_message}"

    # Build failure payload matching §20.4 schema
    jq -n \
        --arg error_code "$error_code" \
        --arg error_message "$error_message" \
        --arg executor_version "${VUNNIX_EXECUTOR_VERSION:-unknown}" \
        --arg strategy "${VUNNIX_STRATEGY:-unknown}" \
        '{
            status: "failed",
            result: null,
            error: $error_code,
            error_message: $error_message,
            tokens: { input: 0, output: 0, thinking: 0 },
            duration_seconds: 0,
            prompt_version: {
                skill: ($strategy + ":" + $executor_version),
                claude_md: ("executor:" + $executor_version),
                schema: "n/a"
            }
        }' > "$failure_payload_file"

    # Only attempt to POST if we have the required variables
    if [[ -n "${VUNNIX_API_URL:-}" && -n "${VUNNIX_TASK_ID:-}" && -n "${VUNNIX_TOKEN:-}" ]]; then
        "${SCRIPTS_DIR}/post-results.sh" \
            "$VUNNIX_API_URL" \
            "$VUNNIX_TASK_ID" \
            "$VUNNIX_TOKEN" \
            "$failure_payload_file" \
            --max-retries 1 2>>"$LOG_FILE" || true
    fi
}

# ── Upload debug artifact ────────────────────────────────────────────
save_debug_artifact() {
    # GitLab CI artifacts are collected from specific paths after the job
    local artifact_dir="${CI_PROJECT_DIR:-.}/vunnix-artifacts"
    mkdir -p "$artifact_dir"

    cp "$LOG_FILE" "$artifact_dir/executor.log" 2>/dev/null || true
    cp "$CLAUDE_OUTPUT_FILE" "$artifact_dir/claude-output.json" 2>/dev/null || true
    cp "$FORMATTED_RESULT_FILE" "$artifact_dir/formatted-result.json" 2>/dev/null || true

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
    # run_claude echoes the duration (seconds) to stdout on success
    local duration=0
    if duration=$(run_claude "$skills"); then
        # Step 5: Format output and post result via T28 scripts
        post_result "$duration"
    else
        post_failure "claude_cli_error" "Claude CLI failed during execution"
    fi

    # Step 6: Save debug artifacts for GitLab CI collection
    save_debug_artifact

    log "Executor finished"
}

main "$@"

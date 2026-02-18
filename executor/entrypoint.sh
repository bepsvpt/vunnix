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
#
# ── CI/CD Reliability Configuration ───────────────────────────────────
# The Claude CLI runs headless in an ephemeral Docker container. These
# settings ensure predictable, reliable execution:
#
# CLI Flags:
#   --model opus              Pin to Opus family per D91 (auto-resolves to latest)
#   --dangerously-skip-permissions  Prevent hangs on permission prompts (container is isolated)
#   --verbose                 Full turn-by-turn debug output to LOG_FILE
#   --no-session-persistence  No disk I/O for sessions (ephemeral container)
#   --disable-slash-commands  Skills/slash commands irrelevant in print mode
#   --max-budget-usd 10.00   Cost circuit breaker (2x max estimated task cost)
#   --tools                   Task-type-aware: read-only for reviews, full for feature dev
#   --disallowedTools         Block subagents, web access (not needed for code tasks)
#
# Environment Variables (set by configure_ci_env):
#   CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1 Autoupdater + bug cmd + error reporting + telemetry
#   DISABLE_NON_ESSENTIAL_MODEL_CALLS=1        Skip flavor text, tips
#   DISABLE_COST_WARNINGS=1                    No interactive cost prompts
#   CLAUDE_CODE_DISABLE_BACKGROUND_TASKS=1     No background tasks in print mode
#   CLAUDE_CODE_DISABLE_FEEDBACK_SURVEY=1      No session quality surveys
#   CLAUDE_BASH_MAINTAIN_PROJECT_WORKING_DIR=1 Stable working dir between bash calls
#   BASH_DEFAULT_TIMEOUT_MS=300000             5-min default bash timeout
#   BASH_MAX_TIMEOUT_MS=600000                 10-min max bash timeout (vs 20-min CI limit D34)

set -euo pipefail

# ── Constants ────────────────────────────────────────────────────────
EXECUTOR_DIR="/vunnix-executor"
SCRIPTS_DIR="${EXECUTOR_DIR}/scripts"
RESULT_FILE="/tmp/vunnix-result.json"
LOG_FILE="/tmp/vunnix-executor.log"
CLAUDE_OUTPUT_FILE="/tmp/vunnix-claude-output.json"
FORMATTED_RESULT_FILE="/tmp/vunnix-formatted-result.json"

# ── CI/CD environment for Claude CLI ─────────────────────────────────
# Exports environment variables that disable non-essential features in
# headless CI containers. Called once before the first claude invocation.
configure_ci_env() {
    # Single toggle: DISABLE_AUTOUPDATER + DISABLE_BUG_COMMAND + DISABLE_ERROR_REPORTING + DISABLE_TELEMETRY
    export CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1
    export DISABLE_NON_ESSENTIAL_MODEL_CALLS=1
    export DISABLE_COST_WARNINGS=1
    export CLAUDE_CODE_DISABLE_BACKGROUND_TASKS=1
    export CLAUDE_CODE_DISABLE_FEEDBACK_SURVEY=1
    export CLAUDE_BASH_MAINTAIN_PROJECT_WORKING_DIR=1
    export BASH_DEFAULT_TIMEOUT_MS=300000   # 5 min default
    export BASH_MAX_TIMEOUT_MS=600000       # 10 min max (vs 20-min CI timeout D34)
}

# ── Logging ──────────────────────────────────────────────────────────
log() {
    echo "[vunnix-executor] $(date -u '+%Y-%m-%dT%H:%M:%SZ') $*" | tee -a "$LOG_FILE" >&2
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

# ── Resolve JSON schema for task type ───────────────────────────────
resolve_schema() {
    local task_type="$1"
    local schema_file

    case "$task_type" in
        code_review|security_audit)
            schema_file="${EXECUTOR_DIR}/schemas/code_review.json"
            ;;
        feature_dev|ui_adjustment)
            schema_file="${EXECUTOR_DIR}/schemas/feature_dev.json"
            ;;
        issue_discussion)
            schema_file="${EXECUTOR_DIR}/schemas/issue_discussion.json"
            ;;
        deep_analysis)
            schema_file="${EXECUTOR_DIR}/schemas/deep_analysis.json"
            ;;
        *)
            log_error "Unknown task type: $task_type"
            return 1
            ;;
    esac

    if [[ ! -f "$schema_file" ]]; then
        log_error "Schema file not found: $schema_file"
        return 1
    fi

    echo "$schema_file"
}

# ── Resolve Claude CLI tool restrictions ──────────────────────────────
# Task-type-aware tool access: read-only tasks get Read/Grep/Glob/Bash,
# write tasks additionally get Edit/Write. All tasks block subagents and
# external network tools.
resolve_tool_flags() {
    local task_type="$1"
    local tools

    case "$task_type" in
        code_review|security_audit|deep_analysis|issue_discussion)
            # Read-only analysis — Bash needed for git diff, eslint, phpstan, etc.
            tools="Read,Grep,Glob,Bash"
            ;;
        feature_dev|ui_adjustment)
            # Write tasks — full file access for code changes + git operations
            tools="Read,Grep,Glob,Edit,Write,Bash"
            ;;
        *)
            # Unknown type: conservative read-only default
            tools="Read,Grep,Glob,Bash"
            ;;
    esac

    # Output flags — caller must NOT quote $tool_flags to allow word splitting
    # --disallowedTools uses official tool names only (see settings.md §"Tools available to Claude")
    echo "--tools ${tools} --disallowedTools Task WebFetch WebSearch NotebookEdit Skill"
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

    # Resolve JSON schema for this task type
    local schema_file
    schema_file=$(resolve_schema "$VUNNIX_TASK_TYPE") || {
        post_failure "invalid_task_type" "Unknown task type: $VUNNIX_TASK_TYPE"
        return 1
    }
    log "Using JSON schema: $schema_file"

    # Read schema file into variable (Claude CLI expects schema as inline string)
    local schema_json
    schema_json=$(cat "$schema_file") || {
        log_error "Failed to read schema file: $schema_file"
        post_failure "schema_read_error" "Could not read JSON schema file"
        return 1
    }

    log "Loaded JSON schema from: $schema_file ($(wc -c < "$schema_file") bytes)"

    # Configure CI/CD environment variables (idempotent — safe to call multiple times)
    configure_ci_env

    # Resolve task-type-aware tool restrictions
    local tool_flags
    tool_flags=$(resolve_tool_flags "$VUNNIX_TASK_TYPE")
    log "Running Claude CLI: model=opus, max-turns=30, budget=\$10, verbose, permissions=bypass, ${tool_flags}"

    # Build the Claude CLI prompt based on task type and strategy
    local prompt
    prompt=$(build_prompt)

    # Run Claude CLI with --json-schema to get structured output
    # Claude puts schema-validated JSON in .structured_output field
    cd "$project_dir"

    # shellcheck disable=SC2086
    claude --print \
        --output-format json \
        --json-schema "$schema_json" \
        --max-turns 30 \
        --model opus \
        --max-budget-usd 10.00 \
        --verbose \
        --dangerously-skip-permissions \
        --no-session-persistence \
        --disable-slash-commands \
        $tool_flags \
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

    log "Claude CLI output saved to $CLAUDE_OUTPUT_FILE ($(wc -c < "$CLAUDE_OUTPUT_FILE" 2>/dev/null || echo 0) bytes)"

    # Log structured_output presence for debugging
    if jq -e '.structured_output' "$CLAUDE_OUTPUT_FILE" >/dev/null 2>&1; then
        log "✓ Claude CLI populated .structured_output field (schema-validated)"
    else
        log "⚠ No .structured_output field - Claude may have returned unstructured response"
    fi

    # Log usage data
    local usage_summary=$(jq -r '"\(.usage.input_tokens // 0)in/\(.usage.output_tokens // 0)out/\(.usage.thinking_tokens // 0)think"' "$CLAUDE_OUTPUT_FILE" 2>/dev/null || echo "unknown")
    log "Token usage: $usage_summary"

    echo "$duration"
}

# ── Build task prompt ────────────────────────────────────────────────
# Prompts MUST explicitly request JSON-only output. --json-schema validates but doesn't enforce.
build_prompt() {
    case "$VUNNIX_TASK_TYPE" in
        code_review)
            cat <<EOF
Review the code in this repository using the ${VUNNIX_STRATEGY} strategy.

CRITICAL: Your output MUST be ONLY a valid JSON object matching the provided schema.
Do NOT include any markdown fencing, explanations, or commentary outside the JSON.
Output the raw JSON object directly with no wrapper text.

Follow these severity definitions from CLAUDE.md:
- Critical: Security vulnerabilities, data loss risk, authentication bypass, broken core functionality
- Major: Bugs affecting functionality, performance issues, missing error handling
- Minor: Style inconsistencies, naming conventions, documentation gaps

Evaluate risk_level based on findings:
- "low" if no major/critical findings
- "medium" if major findings present
- "high" if critical findings present

Set commit_status to "success" if no major/critical findings, "failed" otherwise.
Set labels to ["ai::approved"] if approved, ["ai::needs-work"] if not.

Remember: Output ONLY the JSON object, nothing else.
EOF
            ;;
        security_audit)
            cat <<EOF
Perform a security audit of the code in this repository.

CRITICAL: Your output MUST be ONLY a valid JSON object matching the provided schema.
Do NOT include any markdown fencing, explanations, or commentary outside the JSON.
Output the raw JSON object directly with no wrapper text.

Check for:
- OWASP Top 10 vulnerabilities
- Authentication/authorization bypasses
- Input validation issues
- Secret exposure (API keys, passwords, tokens)

All security findings start at Major severity minimum (no Minor severity for security issues).

Set risk_level to "low" if no findings, "high" if vulnerabilities found.
Set commit_status to "success" if no findings, "failed" otherwise.

Remember: Output ONLY the JSON object, nothing else.
EOF
            ;;
        feature_dev)
            cat <<EOF
Implement the feature described in the task parameters. Follow project conventions from CLAUDE.md. Create clean, tested code.

CRITICAL: Your output MUST be ONLY a valid JSON object matching the provided schema.
Do NOT include any markdown fencing, explanations, or commentary outside the JSON.
Output the raw JSON object directly with no wrapper text.
EOF
            ;;
        ui_adjustment)
            cat <<EOF
Make the UI adjustment described in the task parameters using the ${VUNNIX_STRATEGY} strategy.

CRITICAL: Your output MUST be ONLY a valid JSON object matching the provided schema.
Do NOT include any markdown fencing, explanations, or commentary outside the JSON.
Output the raw JSON object directly with no wrapper text.
EOF
            ;;
        issue_discussion)
            cat <<EOF
Answer the question from the issue context. Reference relevant code from the repository with specific file paths and line numbers. Keep your response concise and actionable.

CRITICAL: Your output MUST be ONLY a valid JSON object matching the provided schema.
Do NOT include any markdown fencing, explanations, or commentary outside the JSON.
Output the raw JSON object directly with no wrapper text.
EOF
            ;;
        deep_analysis)
            cat <<EOF
Perform a deep analysis of this repository. Thoroughly explore the codebase structure, architecture, patterns, and implementation details relevant to the task description.

Focus on:
- Understanding the overall architecture and module organization
- Identifying key design patterns and architectural decisions
- Tracing data flows and module dependencies
- Noting potential concerns or areas for improvement

Provide a comprehensive analysis with specific file paths and line numbers as references.

CRITICAL: Your output MUST be ONLY a valid JSON object matching the provided schema.
Do NOT include any markdown fencing, explanations, or commentary outside the JSON.
Output the raw JSON object directly with no wrapper text.
EOF
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
        --output "$FORMATTED_RESULT_FILE" 2>&1 | tee -a "$LOG_FILE"; then
        log "Result formatting completed successfully"
        log "Formatted result: $(wc -c < "$FORMATTED_RESULT_FILE" 2>/dev/null || echo 0) bytes"
    else
        local format_exit=$?
        log_error "format-output.sh failed (exit $format_exit)"
        log_error "See format-output.sh output above for details"
        post_failure "format_error" "Output formatting failed (exit $format_exit) — result may not match expected schema"
        return 1
    fi

    # Step 2: POST the formatted payload to the Runner Result API
    log "Posting result to ${VUNNIX_API_URL}/api/v1/tasks/${VUNNIX_TASK_ID}/result"
    log "Payload preview: $(jq -c '{status,tokens,duration_seconds}' "$FORMATTED_RESULT_FILE" 2>/dev/null || echo 'invalid-json')"

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

# ── Repair unstructured output ────────────────────────────────────────
# When Claude CLI returns narrative text instead of schema-validated JSON,
# attempt a lightweight repair pass: feed the text back to Claude with the
# schema and ask it to reformat. This produces better structured output
# (with key_findings, references, etc.) than the format-output.sh text
# fallback which can only wrap raw text in a minimal JSON envelope.
repair_output() {
    log "Starting repair pass — re-prompting Claude to produce structured JSON"

    local raw_text
    raw_text=$(jq -r '.result' "$CLAUDE_OUTPUT_FILE" 2>/dev/null)
    if [[ -z "$raw_text" ]]; then
        log_error "Repair pass: no .result text to repair"
        return 1
    fi

    local schema_file
    schema_file=$(resolve_schema "$VUNNIX_TASK_TYPE") || return 1
    local schema_json
    schema_json=$(cat "$schema_file") || return 1

    local repair_output_file="/tmp/vunnix-repair-output.json"
    local project_dir="${CI_PROJECT_DIR:-.}"

    # Simple single-turn prompt — no tools, no sub-agents
    local repair_prompt
    repair_prompt=$(cat <<REPAIR_EOF
Reformat the following analysis text as a JSON object matching the provided schema.
Extract structured data (key findings with titles/descriptions/severity, file references with line numbers) from the text.
Do NOT add information that is not in the original text.

Analysis text:
---
${raw_text}
REPAIR_EOF
    )

    cd "$project_dir"
    if claude --print \
        --output-format json \
        --json-schema "$schema_json" \
        --max-turns 1 \
        --model opus \
        --max-budget-usd 2.00 \
        --verbose \
        --dangerously-skip-permissions \
        --no-session-persistence \
        --disable-slash-commands \
        --tools "Read,Grep,Glob" \
        --disallowedTools Task WebFetch WebSearch NotebookEdit Skill \
        "$repair_prompt" \
        > "$repair_output_file" 2>>"$LOG_FILE"; then

        # Check if repair produced .structured_output
        if jq -e '.structured_output' "$repair_output_file" >/dev/null 2>&1; then
            log "✓ Repair pass produced .structured_output — merging into original output"

            # Merge: keep original usage/cost data, replace .structured_output
            local repaired_structured
            repaired_structured=$(jq '.structured_output' "$repair_output_file")
            jq --argjson repaired "$repaired_structured" '. + {structured_output: $repaired}' \
                "$CLAUDE_OUTPUT_FILE" > "${CLAUDE_OUTPUT_FILE}.tmp" \
                && mv "${CLAUDE_OUTPUT_FILE}.tmp" "$CLAUDE_OUTPUT_FILE"

            # Add repair pass token usage to totals
            local repair_input repair_output_tokens
            repair_input=$(jq -r '.usage.input_tokens // 0' "$repair_output_file" 2>/dev/null || echo "0")
            repair_output_tokens=$(jq -r '.usage.output_tokens // 0' "$repair_output_file" 2>/dev/null || echo "0")
            log "Repair pass usage: ${repair_input}in/${repair_output_tokens}out"

            rm -f "$repair_output_file"
            return 0
        else
            log "Repair pass did not produce .structured_output"
            rm -f "$repair_output_file"
            return 1
        fi
    else
        log_error "Repair pass Claude CLI call failed"
        rm -f "$repair_output_file"
        return 1
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
    # Always save debug artifacts, even if set -e terminates the script early.
    # Without this trap, any non-zero return from post_result (e.g., format-output.sh
    # exit 3) causes set -e to kill the script before save_debug_artifact runs,
    # leaving GitLab CI with no artifacts to upload.
    trap 'save_debug_artifact; log "Executor finished"' EXIT

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
        # Step 4b: Repair unstructured output if needed
        # --json-schema validates but doesn't enforce (see comment at build_prompt).
        # When Claude uses sub-agents, the final response can be narrative text.
        if ! jq -e '.structured_output' "$CLAUDE_OUTPUT_FILE" >/dev/null 2>&1; then
            local result_type
            result_type=$(jq -r '.result | type' "$CLAUDE_OUTPUT_FILE" 2>/dev/null)
            if [[ "$result_type" == "string" ]]; then
                log "No .structured_output and .result is text — attempting repair pass"
                repair_output || log "Repair pass failed — format-output.sh will apply text fallback"
            fi
        fi

        # Step 5: Format output and post result via T28 scripts
        post_result "$duration" || true
    else
        post_failure "claude_cli_error" "Claude CLI failed during execution"
    fi
}

main "$@"

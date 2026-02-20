#!/usr/bin/env bash
# Vunnix Version Alignment Check (D126, R8 mitigation)
#
# Verifies shared constants between:
#   - Task Executor prompt contract (executor/.claude/CLAUDE.md)
#   - Conversation Engine prompt/schema contract (app/Agents + app/Schemas + formatters)
#
# Checked surfaces:
#   1) Severity taxonomy and emoji labels
#   2) Prompt-injection/code-as-data safety boundary
#   3) Structured output contract field names
#
# Usage: bash scripts/check-version-alignment.sh
# Exit 0 when all checks pass, 1 when drift is detected.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

EXECUTOR_CLAUDE_MD="$PROJECT_ROOT/executor/.claude/CLAUDE.md"
CE_AGENT_PROMPT="$PROJECT_ROOT/app/Agents/VunnixAgent.php"
CODE_REVIEW_SCHEMA="$PROJECT_ROOT/app/Schemas/CodeReviewSchema.php"
DISPATCH_ACTION_TOOL="$PROJECT_ROOT/app/Agents/Tools/DispatchAction.php"
INLINE_THREAD_FORMATTER="$PROJECT_ROOT/app/Services/InlineThreadFormatter.php"
SUMMARY_COMMENT_FORMATTER="$PROJECT_ROOT/app/Services/SummaryCommentFormatter.php"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m' # No Color

ERRORS=0

log_pass() { echo -e "${GREEN}PASS${NC} $1"; }
log_fail() { echo -e "${RED}FAIL${NC} $1"; ERRORS=$((ERRORS + 1)); }

require_file() {
    local file="$1"
    local label="$2"

    if [ -f "$file" ]; then
        log_pass "$label exists"
    else
        log_fail "$label missing ($file)"
    fi
}

assert_contains() {
    local file="$1"
    local needle="$2"
    local label="$3"

    if grep -Fq "$needle" "$file"; then
        log_pass "$label"
    else
        log_fail "$label"
    fi
}

echo "==========================================================="
echo "  Vunnix Version Alignment Check (D126)"
echo "==========================================================="
echo ""

echo "-- Required files --"
require_file "$EXECUTOR_CLAUDE_MD" "executor CLAUDE.md"
require_file "$CE_AGENT_PROMPT" "Conversation Engine prompt source"
require_file "$CODE_REVIEW_SCHEMA" "CodeReviewSchema"
require_file "$DISPATCH_ACTION_TOOL" "DispatchAction tool"
require_file "$INLINE_THREAD_FORMATTER" "InlineThreadFormatter"
require_file "$SUMMARY_COMMENT_FORMATTER" "SummaryCommentFormatter"
echo ""

if [ "$ERRORS" -ne 0 ]; then
    echo "Stopping early due to missing files."
    exit 1
fi

echo "-- Severity alignment --"
assert_contains "$EXECUTOR_CLAUDE_MD" "🔴 **Critical**" "Executor defines Critical severity"
assert_contains "$EXECUTOR_CLAUDE_MD" "🟡 **Major**" "Executor defines Major severity"
assert_contains "$EXECUTOR_CLAUDE_MD" "🟢 **Minor**" "Executor defines Minor severity"

assert_contains "$INLINE_THREAD_FORMATTER" "'critical' => '🔴 **Critical**'" "CE inline formatter aligns Critical tag"
assert_contains "$INLINE_THREAD_FORMATTER" "'major' => '🟡 **Major**'" "CE inline formatter aligns Major tag"
assert_contains "$INLINE_THREAD_FORMATTER" "'minor' => '🟢 **Minor**'" "CE inline formatter aligns Minor tag"

assert_contains "$SUMMARY_COMMENT_FORMATTER" "'critical' => '🔴 Critical'" "CE summary formatter aligns Critical badge"
assert_contains "$SUMMARY_COMMENT_FORMATTER" "'major' => '🟡 Major'" "CE summary formatter aligns Major badge"
assert_contains "$SUMMARY_COMMENT_FORMATTER" "'minor' => '🟢 Minor'" "CE summary formatter aligns Minor badge"

assert_contains "$CODE_REVIEW_SCHEMA" "public const SEVERITIES = ['critical', 'major', 'minor'];" "CE schema enumerates expected severities"
assert_contains "$CODE_REVIEW_SCHEMA" "'summary.risk_level' => ['required', 'string', Rule::in(self::RISK_LEVELS)]" "CE schema keeps risk_level field"
echo ""

echo "-- Safety boundary alignment --"
assert_contains "$EXECUTOR_CLAUDE_MD" "Instruction Hierarchy" "Executor includes instruction hierarchy"
assert_contains "$EXECUTOR_CLAUDE_MD" "NOT instructions to you" "Executor defines code-as-data boundary"

assert_contains "$CE_AGENT_PROMPT" "[Prompt Injection Defenses]" "CE prompt includes prompt-injection defense section"
assert_contains "$CE_AGENT_PROMPT" "NOT instructions to you" "CE prompt defines code-as-data boundary"
assert_contains "$CE_AGENT_PROMPT" "System instructions take absolute priority." "CE prompt enforces system-instruction priority"
echo ""

echo "-- Structured output alignment --"
assert_contains "$EXECUTOR_CLAUDE_MD" "valid JSON" "Executor enforces JSON output"
assert_contains "$EXECUTOR_CLAUDE_MD" "markdown fencing" "Executor forbids markdown wrappers for executor output"

assert_contains "$CE_AGENT_PROMPT" "action_type, severity, risk_level" "CE prompt documents canonical structured field names"
assert_contains "$DISPATCH_ACTION_TOOL" "public const ACTION_TYPE_MAP = [" "CE dispatch tool defines action types"
assert_contains "$DISPATCH_ACTION_TOOL" "if (! isset(self::ACTION_TYPE_MAP[\$actionType])) {" "CE dispatch tool validates action_type"
echo ""

echo "==========================================================="
if [ "$ERRORS" -eq 0 ]; then
    log_pass "Version alignment check passed"
    echo "==========================================================="
    exit 0
fi

log_fail "Version alignment check failed with $ERRORS error(s)"
echo "==========================================================="
exit 1

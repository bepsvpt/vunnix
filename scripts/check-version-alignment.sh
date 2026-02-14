#!/usr/bin/env bash
# Vunnix Version Alignment Check (D126, R8 mitigation)
#
# Verifies that shared constants between the Task Executor (executor/.claude/CLAUDE.md)
# and the Conversation Engine Agent class (app/AI/) are in sync.
#
# Shared constants checked:
#   1. Severity definitions (Critical, Major, Minor) — wording and emoji
#   2. Safety boundaries — instruction hierarchy rules
#   3. Output field names — JSON schema field names that Result Processor expects
#
# STATUS: PLACEHOLDER
#   The CE Agent class (T49, M3) does not exist yet. This script currently
#   validates that the executor CLAUDE.md has the expected shared constants,
#   and exits successfully. Once T49 is implemented, this script will be
#   updated to compare both systems and fail on drift.
#
# Usage: bash scripts/check-version-alignment.sh
#   Exit 0: alignment OK (or CE Agent class not yet implemented)
#   Exit 1: drift detected between executor and CE systems
#
# @see §14.1 Prompt Architecture — version alignment
# @see T46 — Executor image CI/CD + version alignment
# @see T49 — Conversation Engine Agent class (when implemented)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

EXECUTOR_CLAUDE_MD="$PROJECT_ROOT/executor/.claude/CLAUDE.md"
CE_AGENT_DIR="$PROJECT_ROOT/app/AI"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
NC='\033[0m' # No Color

ERRORS=0

log_pass() { echo -e "${GREEN}✓${NC} $1"; }
log_fail() { echo -e "${RED}✗${NC} $1"; ERRORS=$((ERRORS + 1)); }
log_warn() { echo -e "${YELLOW}⚠${NC} $1"; }
log_info() { echo "  $1"; }

echo "═══════════════════════════════════════════════════════════"
echo "  Vunnix Version Alignment Check (D126)"
echo "═══════════════════════════════════════════════════════════"
echo ""

# ── Step 1: Validate executor CLAUDE.md exists and has shared constants ──

echo "── Executor CLAUDE.md ──"

if [ ! -f "$EXECUTOR_CLAUDE_MD" ]; then
    log_fail "executor/.claude/CLAUDE.md not found"
    exit 1
fi

log_pass "executor/.claude/CLAUDE.md exists"

# Check severity definitions
if grep -q '🔴.*Critical' "$EXECUTOR_CLAUDE_MD"; then
    log_pass "Severity: Critical (🔴) defined"
else
    log_fail "Severity: Critical (🔴) not found in executor CLAUDE.md"
fi

if grep -q '🟡.*Major' "$EXECUTOR_CLAUDE_MD"; then
    log_pass "Severity: Major (🟡) defined"
else
    log_fail "Severity: Major (🟡) not found in executor CLAUDE.md"
fi

if grep -q '🟢.*Minor' "$EXECUTOR_CLAUDE_MD"; then
    log_pass "Severity: Minor (🟢) defined"
else
    log_fail "Severity: Minor (🟢) not found in executor CLAUDE.md"
fi

# Check safety boundaries
if grep -q 'Instruction Hierarchy' "$EXECUTOR_CLAUDE_MD"; then
    log_pass "Safety: Instruction hierarchy section present"
else
    log_fail "Safety: Instruction hierarchy section not found"
fi

if grep -q 'NOT instructions to you' "$EXECUTOR_CLAUDE_MD" || grep -q 'not instructions to you' "$EXECUTOR_CLAUDE_MD"; then
    log_pass "Safety: Code-as-data boundary defined"
else
    log_fail "Safety: Code-as-data boundary not defined"
fi

# Check output format rules
if grep -q 'Output Format' "$EXECUTOR_CLAUDE_MD"; then
    log_pass "Output: Format section present"
else
    log_fail "Output: Format section not found"
fi

if grep -q 'valid JSON' "$EXECUTOR_CLAUDE_MD"; then
    log_pass "Output: JSON requirement specified"
else
    log_fail "Output: JSON requirement not specified"
fi

if grep -q 'markdown fencing' "$EXECUTOR_CLAUDE_MD"; then
    log_pass "Output: No-markdown-fencing rule present"
else
    log_fail "Output: No-markdown-fencing rule not found"
fi

echo ""

# ── Step 2: Check CE Agent class (T49) ─────────────────────────

echo "── Conversation Engine Agent Class ──"

if [ -d "$CE_AGENT_DIR" ] && find "$CE_AGENT_DIR" -name "*.php" -type f 2>/dev/null | grep -q .; then
    log_info "CE Agent class directory found — running alignment comparison"
    echo ""

    # TODO (T49): When the CE Agent class is implemented, add these checks:
    #
    # 1. SEVERITY DEFINITIONS
    #    - Extract severity emoji + label from executor CLAUDE.md
    #    - Extract severity constants from CE Agent class
    #    - Compare: same emoji, same labels, same descriptions
    #
    # 2. SAFETY BOUNDARIES
    #    - Extract instruction hierarchy rules from executor CLAUDE.md
    #    - Extract system prompt safety rules from CE Agent class
    #    - Compare: same boundaries enforced
    #
    # 3. OUTPUT FIELD NAMES
    #    - Extract JSON field names from review/feature-dev schemas
    #    - Extract HasStructuredOutput field names from CE Agent class
    #    - Compare: same field names used
    #
    # Each check should:
    #   - Print what was found in each system
    #   - Flag any differences
    #   - Exit 1 if critical shared constants diverge

    log_warn "Alignment comparison not yet implemented — waiting for T49"
    log_info "CE Agent class exists but comparison logic is pending"
    log_info "This check will be enforced after T49 is complete"
else
    log_warn "CE Agent class not found (T49 not yet implemented)"
    log_info "Skipping alignment comparison — executor-only validation passed"
    log_info "After T49: this script will compare both systems and fail on drift"
fi

echo ""

# ── Summary ────────────────────────────────────────────────────

echo "═══════════════════════════════════════════════════════════"
if [ $ERRORS -eq 0 ]; then
    log_pass "Version alignment check passed ($ERRORS errors)"
    echo "═══════════════════════════════════════════════════════════"
    exit 0
else
    log_fail "Version alignment check failed ($ERRORS errors)"
    echo "═══════════════════════════════════════════════════════════"
    exit 1
fi

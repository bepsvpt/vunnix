#!/bin/bash
# Vunnix Development Progress Monitor
# Run in a separate terminal: bash verify/watch_progress.sh
# Refreshes every 30 seconds.

cd "$(dirname "$0")/.." || exit 1

while true; do
    clear
    echo "╔══════════════════════════════════════════════╗"
    echo "║       VUNNIX DEVELOPMENT PROGRESS           ║"
    echo "╚══════════════════════════════════════════════╝"
    echo ""

    # Summary from progress.md
    if [ -f progress.md ]; then
        sed -n '/^## Summary/,/^---/p' progress.md | grep -E '^\- ' | sed 's/^/  /'
    else
        echo "  progress.md not found!"
    fi

    echo ""
    echo "──────────────────────────────────────────────"

    # Count tasks
    if [ -f progress.md ]; then
        DONE=$(grep -c '^\- \[x\]' progress.md 2>/dev/null) || DONE=0
        TODO=$(grep -c '^\- \[ \]' progress.md 2>/dev/null) || TODO=0
        TOTAL=$((DONE + TODO))
        if [ "$TOTAL" -gt 0 ]; then
            PCT=$((DONE * 100 / TOTAL))
        else
            PCT=0
        fi

        # Progress bar
        BAR_WIDTH=40
        FILLED=$((PCT * BAR_WIDTH / 100))
        EMPTY=$((BAR_WIDTH - FILLED))
        BAR=$(printf '%0.s█' $(seq 1 $FILLED 2>/dev/null) 2>/dev/null)
        SPACE=$(printf '%0.s░' $(seq 1 $EMPTY 2>/dev/null) 2>/dev/null)
        echo "  Progress: [${BAR}${SPACE}] ${PCT}%"
        echo "  Tasks:    ${DONE} done / ${TODO} remaining / ${TOTAL} total"
    fi

    echo ""
    echo "──────────────────────────────────────────────"
    echo "  Recent commits:"

    if git rev-parse --git-dir > /dev/null 2>&1; then
        git log --oneline -5 2>/dev/null | sed 's/^/    /'
        if [ $? -ne 0 ]; then
            echo "    (no commits yet)"
        fi
    else
        echo "    (not a git repo)"
    fi

    echo ""
    echo "──────────────────────────────────────────────"
    echo "  Current milestone progress:"

    if [ -f progress.md ]; then
        grep -E '^## M[0-9]' progress.md | sed 's/^/  /'
    fi

    echo ""
    echo "  Last updated: $(date '+%Y-%m-%d %H:%M:%S')"
    echo "  (refreshes every 30s — Ctrl+C to exit)"

    sleep 30
done

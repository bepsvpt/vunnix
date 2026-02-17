#!/usr/bin/env bash
# PostToolUse hook: auto-format JS/Vue files with ESLint after Edit/Write.
# Reads hook input JSON from stdin, extracts file_path, runs ESLint --fix on JS/Vue files.
# Exits silently for non-JS/Vue files or on ESLint failure (non-blocking).

set -euo pipefail

INPUT=$(cat)
FILE_PATH=$(echo "$INPUT" | jq -r '.tool_input.file_path // empty')

# Exit early if no file path
if [[ -z "$FILE_PATH" ]]; then
    exit 0
fi

# Only process JS, Vue, TS, JSX, TSX files
case "$FILE_PATH" in
    *.js|*.vue|*.ts|*.jsx|*.tsx) ;;
    *) exit 0 ;;
esac

# Exit early if file doesn't exist (e.g., deleted)
if [[ ! -f "$FILE_PATH" ]]; then
    exit 0
fi

# Run ESLint fix on the single file — suppress output, don't block on failure
npx eslint --fix "$FILE_PATH" > /dev/null 2>&1 || true

exit 0

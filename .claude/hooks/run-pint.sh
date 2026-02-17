#!/usr/bin/env bash
# PostToolUse hook: auto-format PHP files with Laravel Pint after Edit/Write.
# Reads hook input JSON from stdin, extracts file_path, runs Pint on PHP files.
# Exits silently for non-PHP files or on Pint failure (non-blocking).

set -euo pipefail

INPUT=$(cat)
FILE_PATH=$(echo "$INPUT" | jq -r '.tool_input.file_path // empty')

# Exit early if no file path or not a PHP file
if [[ -z "$FILE_PATH" || "$FILE_PATH" != *.php ]]; then
    exit 0
fi

# Exit early if file doesn't exist (e.g., deleted)
if [[ ! -f "$FILE_PATH" ]]; then
    exit 0
fi

# Run Pint on the single file — suppress output, don't block on failure
./vendor/bin/pint "$FILE_PATH" > /dev/null 2>&1 || true

exit 0

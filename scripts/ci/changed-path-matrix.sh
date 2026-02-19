#!/usr/bin/env bash
set -euo pipefail

BASE_REF="${1:-origin/main}"

if ! git rev-parse --verify "$BASE_REF" >/dev/null 2>&1; then
  BASE_REF="HEAD~1"
fi

if git rev-parse --verify "$BASE_REF" >/dev/null 2>&1; then
  CHANGED_FILES="$(git diff --name-only "$BASE_REF"...HEAD || true)"
else
  CHANGED_FILES="$(git diff --name-only HEAD~1 || true)"
fi

php_changed=false
js_changed=false
docs_only=true

while IFS= read -r file; do
  [ -z "$file" ] && continue

  case "$file" in
    app/*|bootstrap/*|config/*|database/*|routes/*|tests/*|composer.json|composer.lock|phpstan*|phpunit.xml|pest.php|artisan)
      php_changed=true
      docs_only=false
      ;;
    resources/js/*|package.json|package-lock.json|tsconfig*.json|vite.config.js|eslint.config.js|vitest.config.js)
      js_changed=true
      docs_only=false
      ;;
    docs/*|README.md|CLAUDE.md|AGENTS.md)
      ;;
    *)
      docs_only=false
      ;;
  esac

done <<< "$CHANGED_FILES"

if [ -n "${GITHUB_OUTPUT:-}" ]; then
  {
    echo "php_changed=$php_changed"
    echo "js_changed=$js_changed"
    echo "docs_only=$docs_only"
  } >> "$GITHUB_OUTPUT"
fi

echo "php_changed=$php_changed"
echo "js_changed=$js_changed"
echo "docs_only=$docs_only"

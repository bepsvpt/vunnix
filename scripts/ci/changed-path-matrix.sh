#!/usr/bin/env bash
set -euo pipefail

BASE_REF="${1:-origin/main}"

add_csv_value() {
  local csv="$1"
  local value="$2"

  [ -z "$value" ] && {
    echo "$csv"
    return
  }

  case ",$csv," in
    *",$value,"*) echo "$csv" ;;
    ,,) echo "$value" ;;
    *) echo "${csv},${value}" ;;
  esac
}

normalize_csv() {
  local csv="$1"
  if [ -z "$csv" ]; then
    echo ""
    return
  fi

  echo "$csv" | tr ',' '\n' | sed '/^$/d' | sort -u | paste -sd, -
}

if [ -n "${CHANGED_FILES_OVERRIDE:-}" ]; then
  CHANGED_FILES="$CHANGED_FILES_OVERRIDE"
else
  if ! git rev-parse --verify "$BASE_REF" >/dev/null 2>&1; then
    BASE_REF="HEAD~1"
  fi

  if git rev-parse --verify "$BASE_REF" >/dev/null 2>&1; then
    CHANGED_FILES="$(git diff --name-only "$BASE_REF"...HEAD || true)"
  else
    CHANGED_FILES="$(git diff --name-only HEAD~1 || true)"
  fi
fi

php_changed=false
js_changed=false
docs_only=true
contracts_changed=false
backend_scope=""
frontend_scope=""

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

  case "$file" in
    app/Modules/*/Application/Contracts/*|tests/Feature/Contracts/*)
      contracts_changed=true
      ;;
  esac

  case "$file" in
    app/Modules/*/*)
      module_name="$(echo "$file" | cut -d/ -f3)"
      backend_scope="$(add_csv_value "$backend_scope" "$module_name")"
      ;;
  esac

  case "$file" in
    resources/js/features/*/*)
      feature_name="$(echo "$file" | cut -d/ -f4)"
      frontend_scope="$(add_csv_value "$frontend_scope" "$feature_name")"
      ;;
    resources/js/stores/conversations*|resources/js/pages/ChatPage*|resources/js/components/Conversation*|resources/js/components/Message*)
      frontend_scope="$(add_csv_value "$frontend_scope" "chat")"
      ;;
    resources/js/stores/admin*|resources/js/pages/AdminPage*|resources/js/components/Admin*)
      frontend_scope="$(add_csv_value "$frontend_scope" "admin")"
      ;;
    resources/js/stores/dashboard*|resources/js/pages/DashboardPage*|resources/js/components/Dashboard*|resources/js/components/ActivityFeed*)
      frontend_scope="$(add_csv_value "$frontend_scope" "dashboard")"
      frontend_scope="$(add_csv_value "$frontend_scope" "activity")"
      ;;
  esac

done <<< "$CHANGED_FILES"

backend_scope="$(normalize_csv "$backend_scope")"
frontend_scope="$(normalize_csv "$frontend_scope")"

if [ -z "$backend_scope" ]; then
  backend_scope="all"
fi

if [ -z "$frontend_scope" ]; then
  frontend_scope="all"
fi

if [ -n "${GITHUB_OUTPUT:-}" ]; then
  {
    echo "php_changed=$php_changed"
    echo "js_changed=$js_changed"
    echo "docs_only=$docs_only"
    echo "contracts_changed=$contracts_changed"
    echo "backend_scope=$backend_scope"
    echo "frontend_scope=$frontend_scope"
  } >> "$GITHUB_OUTPUT"
fi

echo "php_changed=$php_changed"
echo "js_changed=$js_changed"
echo "docs_only=$docs_only"
echo "contracts_changed=$contracts_changed"
echo "backend_scope=$backend_scope"
echo "frontend_scope=$frontend_scope"

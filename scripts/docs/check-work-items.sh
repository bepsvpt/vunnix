#!/usr/bin/env bash
set -euo pipefail

root="docs/work/extensions"
failures=0

if [ ! -d "$root" ]; then
  echo "ERROR: missing $root"
  exit 1
fi

shopt -s nullglob
ext_dirs=("$root"/ext-*)
shopt -u nullglob

if [ ${#ext_dirs[@]} -eq 0 ]; then
  echo "ERROR: no extension folders found under $root"
  exit 1
fi

for dir in "${ext_dirs[@]}"; do
  name="$(basename "$dir")"

  if [[ ! "$name" =~ ^ext-[0-9]{3}-[a-z0-9-]+$ ]]; then
    echo "ERROR: invalid extension folder name: $dir"
    failures=1
    continue
  fi

  if [ ! -f "$dir/assessment.md" ]; then
    echo "ERROR: missing assessment: $dir/assessment.md"
    failures=1
  fi

  if [ ! -f "$dir/plan.md" ]; then
    echo "ERROR: missing plan: $dir/plan.md"
    failures=1
  fi

  ext_num="${name#ext-}"
  ext_num="${ext_num%%-*}"

  if [ -f "$dir/assessment.md" ] && ! rg -q '^# Assessment:' "$dir/assessment.md"; then
    echo "ERROR: malformed assessment header: $dir/assessment.md"
    failures=1
  fi

  if [ -f "$dir/plan.md" ] && ! rg -q "^## Extension ${ext_num}:" "$dir/plan.md"; then
    echo "ERROR: extension number/header mismatch in $dir/plan.md (expected '## Extension ${ext_num}:')"
    failures=1
  fi
done

if find docs/assessments docs/extensions -type f -name '*.md' 2>/dev/null | rg -q '.'; then
  echo "ERROR: found markdown files in legacy docs/assessments or docs/extensions paths"
  failures=1
fi

if [ "$failures" -ne 0 ]; then
  exit 1
fi

echo "OK: extension work artifacts are structurally valid"

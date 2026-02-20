#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

violations=0

echo "[architecture] Using rules from scripts/architecture/module-boundaries.yml"

# 1) PHP: module files may not import another module's Infrastructure layer.
while IFS= read -r file; do
  module="$(echo "$file" | cut -d/ -f3)"

  while IFS= read -r line; do
    imported_module="$(echo "$line" | sed -E 's/^.*App\\Modules\\([^\\]+)\\Infrastructure\\.*$/\1/')"

    if [ "$imported_module" != "$module" ] && [ "$module" != "Shared" ]; then
      echo "[violation] $file imports other module infrastructure: $line"
      violations=$((violations + 1))
    fi
  done < <(rg -n "^use App\\\\Modules\\\\[^\\\\]+\\\\Infrastructure\\\\" "$file" || true)
done < <(find app/Modules -type f -name '*.php' | sort)

# 2) PHP: controllers should not import module Infrastructure directly.
while IFS= read -r line; do
  echo "[violation] Controller imports module infrastructure: $line"
  violations=$((violations + 1))
done < <(rg -n "^use App\\\\Modules\\\\[^\\\\]+\\\\Infrastructure\\\\" app/Http/Controllers || true)

# 3) JS: feature files should not import other feature modules directly (except shared).
while IFS= read -r file; do
  source_feature="$(echo "$file" | sed -E 's#^resources/js/features/([^/]+)/.*#\1#')"

  while IFS= read -r line; do
    target_feature="$(echo "$line" | rg -o '@/features/[A-Za-z0-9_-]+' | head -n1 | sed 's#@/features/##')"

    if [ "$source_feature" != "$target_feature" ] && [ "$source_feature" != "shared" ] && [ "$target_feature" != "shared" ]; then
      echo "[violation] Cross-feature import ($source_feature -> $target_feature): $line"
      violations=$((violations + 1))
    fi
  done < <(rg -n "@/features/[A-Za-z0-9_-]+" "$file" || true)
done < <(find resources/js/features -type f \( -name '*.ts' -o -name '*.vue' -o -name '*.js' \) | sort)

# 4) UI layers should import stores from feature slices, not legacy global store paths.
while IFS= read -r file; do
  while IFS= read -r line; do
    echo "[violation] Legacy store import in UI layer (use feature index): $line"
    violations=$((violations + 1))
  done < <(rg -n "@/stores/(conversations|admin|dashboard)" "$file" || true)
done < <(find resources/js/components resources/js/pages resources/js/composables resources/js/router -type f \( -name '*.ts' -o -name '*.vue' -o -name '*.js' \) ! -name '*.test.*' ! -name '*.spec.*' | sort)

# 5) File-size guardrails.
while IFS= read -r file; do
  lines="$(wc -l < "$file" | tr -d ' ')"
  if [ "$lines" -gt 1000 ]; then
    echo "[violation] PHP module file exceeds 1000 lines: $file ($lines)"
    violations=$((violations + 1))
  fi
done < <(find app/Modules -type f -name '*.php' | sort)

while IFS= read -r file; do
  lines="$(wc -l < "$file" | tr -d ' ')"
  if [ "$lines" -gt 1000 ]; then
    echo "[violation] JS feature file exceeds 1000 lines: $file ($lines)"
    violations=$((violations + 1))
  fi
done < <(find resources/js/features -type f \( -name '*.ts' -o -name '*.vue' -o -name '*.js' \) | sort)

if [ "$violations" -gt 0 ]; then
  echo "[architecture] FAILED with $violations violation(s)."
  exit 1
fi

echo "[architecture] PASS"

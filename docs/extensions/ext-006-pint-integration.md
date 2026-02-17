## Extension 006: Laravel Pint Integration

### Trigger
Pint is installed (`laravel/pint: ^1.24`) but only runs when manually invoked via `./vendor/bin/pint`. No automated enforcement exists — no Claude Code hooks, no CI check, and no project-level `pint.json` configuration. Code style drifts silently between contributors (human and AI).

### Scope
What it does:
- Creates a best-practice `pint.json` configuration that extends the `laravel` preset with 6 additional rules for stricter type safety, class organization, and PHPDoc cleanliness
- Adds a Claude Code `PostToolUse` hook that auto-formats modified PHP files after every Edit/Write operation
- Adds a lightweight Pint CI job to the GitHub Actions workflow that fails on unformatted code
- Adds `composer format` and `composer format:check` scripts
- Runs an initial whole-codebase formatting as a standalone commit before enabling CI enforcement

What it does NOT do:
- Does not add `declare_strict_types` — this changes runtime type coercion behavior and is a separate decision from formatting
- Does not add git pre-commit hooks — Claude Code hooks handle the AI workflow; human developers can run `composer format` manually or add git hooks independently
- Does not modify any PHP code logic — all changes are whitespace, ordering, import cleanup, and type annotation additions

### Architecture Fit
- **Components affected:** `pint.json` (new), `.claude/settings.json` (new), `.claude/hooks/run-pint.sh` (new), `.github/workflows/tests.yml` (modify), `composer.json` (modify), `CLAUDE.md` (modify), `docs/spec/decisions-index.md` (modify)
- **Extension points used:** Claude Code PostToolUse hooks, GitHub Actions CI workflow (ext-004), Composer scripts
- **New tables/endpoints/services:** None

### New Decisions

- **D177:** Use `laravel` preset as base with 6 targeted rule additions — The `laravel` preset covers PSR-12 + Laravel conventions comprehensively (~150 rules including `class_attributes_separation`, `fully_qualified_strict_types`, `self_accessor`). Add only rules genuinely absent from the preset: `strict_comparison`, `void_return`, `ordered_class_elements`, `ordered_types`, `no_superfluous_phpdoc_tags`, `global_namespace_import`. Exclude `declare_strict_types` (changes runtime behavior) and `final_class` (restricts testing/mocking).
- **D178:** Claude Code PostToolUse hook auto-formats PHP files on every Edit/Write — Runs `./vendor/bin/pint {file}` on individual modified PHP files. Uses project-level `.claude/settings.json` (version-controlled, shared) not `.claude/settings.local.json`. Hook exits silently for non-PHP files and on Pint failure to avoid blocking Claude's workflow.
- **D179:** Pint CI runs as a separate job using `--test` (dry-run) mode without database services — Like PHPStan (D176), formatting doesn't need PostgreSQL or Redis. `--test` mode exits non-zero if files would be changed, providing clear signal without modifying repository contents. Runs in parallel with other CI jobs.

### Dependencies
- **Requires:** Nothing — Pint is already installed, CI workflow exists
- **Unblocks:** Consistent automated code style; foundation for git pre-commit hooks if desired later

### Tasks

#### T144: Create pint.json configuration
**File(s):** `pint.json` (new)
**Action:** Create project-level Pint configuration with `laravel` preset + 6 additional rules:
```json
{
    "preset": "laravel",
    "rules": {
        "global_namespace_import": {
            "import_classes": true,
            "import_constants": false,
            "import_functions": false
        },
        "no_superfluous_phpdoc_tags": {
            "allow_mixed": true,
            "remove_inheritdoc": true
        },
        "ordered_class_elements": {
            "order": [
                "use_trait",
                "case",
                "constant_public",
                "constant_protected",
                "constant_private",
                "property_public",
                "property_protected",
                "property_private",
                "construct",
                "destruct",
                "magic",
                "phpunit",
                "method_public_abstract",
                "method_protected_abstract",
                "method_private_abstract",
                "method_public_static",
                "method_protected_static",
                "method_private_static",
                "method_public",
                "method_protected",
                "method_private"
            ]
        },
        "ordered_types": {
            "null_adjustment": "always_last",
            "sort_algorithm": "none"
        },
        "strict_comparison": true,
        "void_return": true
    },
    "exclude": [
        "bootstrap/cache",
        "storage"
    ]
}
```

Rule justifications:
- **`strict_comparison`**: Converts `==`/`!=` to `===`/`!==`. Catches subtle type coercion bugs. Safe in Laravel where Eloquent returns typed values.
- **`void_return`**: Adds explicit `:void` return types. Helps PHPStan and IDE inference. No runtime behavior change for existing code (PHP already treats missing return as void).
- **`ordered_class_elements`**: Consistent class structure — traits → constants → properties → constructor → methods. Reduces cognitive load when navigating classes.
- **`no_superfluous_phpdoc_tags`**: Removes PHPDoc `@param`/`@return` that exactly duplicate native type hints. Keeps PHPDoc only where it adds information (generics, array shapes, descriptions).
- **`global_namespace_import`**: Adds `use` imports for global classes instead of `\DateTime`. Only classes, not functions/constants (those are idiomatic inline in Laravel).
- **`ordered_types`**: Normalizes union types with `null` always last (`string|null` not `null|string`). No sorting algorithm — preserves semantic ordering.

Exclusions: `vendor/` is auto-excluded by Pint. Only `bootstrap/cache` and `storage` need explicit exclusion.

**Verification:** `./vendor/bin/pint --test` runs without JSON parse error. `python3 -m json.tool pint.json` validates syntax.

#### T145: Run initial whole-codebase formatting (separate commit)
**File(s):** All PHP files under `app/`, `config/`, `database/`, `routes/`, `tests/`
**Action:**
1. Run `./vendor/bin/pint` on the full codebase
2. Review the diff for any unexpected changes (especially `strict_comparison` converting `==` to `===`)
3. Run `php artisan test --parallel` to verify no tests break
4. Run `composer analyse` to verify PHPStan still passes
5. Commit as a standalone formatting-only commit: `Apply Laravel Pint formatting with extended ruleset (ext-006)`

This MUST be a separate commit before enabling CI enforcement or adding any other changes. A clean formatting commit makes `git blame --ignore-rev` possible and keeps the Pint CI job from failing on pre-existing style violations.
**Verification:** `./vendor/bin/pint --test` exits 0 (no files would change). Full test suite and PHPStan pass.

#### T146: Add Composer scripts for Pint
**File(s):** `composer.json`
**Action:** Add to the `scripts` section after `analyse:baseline`:
```json
"format": "./vendor/bin/pint",
"format:check": "./vendor/bin/pint --test"
```
**Verification:** `composer format -- --test` exits 0. `composer format:check` exits 0.

#### T147: Create Claude Code PostToolUse hook script
**File(s):** `.claude/hooks/run-pint.sh` (new)
**Action:** Create the hook script:
```bash
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
```
Make executable: `chmod +x .claude/hooks/run-pint.sh`
**Verification:** `echo '{"tool_input":{"file_path":"app/Models/User.php"}}' | .claude/hooks/run-pint.sh` exits 0. `echo '{"tool_input":{"file_path":"README.md"}}' | .claude/hooks/run-pint.sh` exits 0 (no-op).

#### T148: Register hook in Claude Code settings
**File(s):** `.claude/settings.json` (new)
**Action:** Create project-level Claude Code settings with the PostToolUse hook:
```json
{
    "hooks": {
        "PostToolUse": [
            {
                "matcher": "Write|Edit",
                "hooks": [
                    {
                        "type": "command",
                        "command": "$CLAUDE_PROJECT_DIR/.claude/hooks/run-pint.sh"
                    }
                ]
            }
        ]
    }
}
```
**Verification:** Start a new Claude Code session in the project. Edit a PHP file. Confirm the file is auto-formatted (check with `./vendor/bin/pint --test` on that file).

#### T149: Add Pint CI job to GitHub Actions workflow
**File(s):** `.github/workflows/tests.yml`
**Action:** Add a `pint` job before the `phpstan` job. Follows the same lightweight pattern as PHPStan (D176) — no database services:
```yaml
  pint:
    name: Pint
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v6

      - name: Setup PHP 8.5
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          coverage: none

      - name: Cache Composer dependencies
        uses: actions/cache@v4
        with:
          path: vendor
          key: composer-${{ hashFiles('composer.lock') }}
          restore-keys: composer-

      - name: Install Composer dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Check code formatting
        run: ./vendor/bin/pint --test
```
Note: No PHP extensions needed beyond defaults for Pint. The `coverage: none` setting keeps the job fast.
**Verification:** Push to a branch, confirm the Pint job appears in GitHub Actions. It should pass because T145 already formatted everything.

#### T150: Update CLAUDE.md
**File(s):** `CLAUDE.md`
**Action:**
1. Update the Commands table — replace the existing `./vendor/bin/pint` entry with:
```markdown
| `composer format` | Run Laravel Pint (auto-fix code style) |
| `composer format:check` | Check code style without fixing (CI mode) |
```
2. Update the Coding Standards / PHP section — change "enforce with Laravel Pint" to: "enforce with Laravel Pint (auto-formatted via Claude Code hook + CI)"
**Verification:** Commands table has both entries. PHP standards section references automation.

#### T151: Update decisions index
**File(s):** `docs/spec/decisions-index.md`
**Action:** Append:
```markdown
| D177 | Laravel Pint `laravel` preset + strict_comparison, void_return, ordered_class_elements, PHPDoc cleanup | ext-006 | Active |
| D178 | Claude Code PostToolUse hook auto-formats PHP files on Edit/Write via Pint | ext-006 | Active |
| D179 | Pint CI runs as separate lightweight job using `--test` dry-run mode | ext-006 | Active |
```
**Verification:** `grep -c 'D17[789]' docs/spec/decisions-index.md` returns 3.

### Verification
- [ ] `pint.json` exists at project root with `laravel` preset + 6 additional rules (no rules duplicating the preset)
- [ ] Initial formatting applied as a standalone commit (separate from all other changes)
- [ ] `./vendor/bin/pint --test` exits 0 (all files formatted)
- [ ] `composer format` and `composer format:check` work
- [ ] Claude Code PostToolUse hook runs Pint on PHP files after Edit/Write
- [ ] `.claude/hooks/run-pint.sh` exits silently for non-PHP files
- [ ] CI workflow has a passing `Pint` job using `--test` mode
- [ ] Full test suite passes after formatting (`php artisan test --parallel`)
- [ ] PHPStan still passes after formatting (`composer analyse`)
- [ ] D177–D179 added to decisions index
- [ ] CLAUDE.md updated with `composer format` / `composer format:check`

## Extension 005: Larastan (PHPStan) Static Analysis

**Status: ✅ Implemented** — `f82d9b9` through `cf19ab7`

### Trigger
Long-term maintainability improvement. CLAUDE.md lists PHPStan as the static analysis tool and the executor Docker image already has PHPStan installed globally (D71), but no configuration, dev dependency, or CI enforcement exists for the main application.

### Scope
What it does:
- Installs Larastan 3.x and supporting PHPStan extensions as Composer dev dependencies
- Adds `composer analyse` and `composer analyse:baseline` scripts matching the existing convention
- Creates `phpstan.neon.dist` configuration with baseline
- Adds a dedicated PHPStan CI job to the GitHub Actions workflow
- Establishes level 0 as the enforced baseline with a documented pattern for raising to level 8

What it does NOT do:
- Does not target levels 9-10 (mixed-type strictness — excessive for Laravel, see D175)
- Does not analyse test files initially (only `app/` — tests can be added later)
- Does not replace the executor's global PHPStan installation (they are complementary)
- Does not add `phpstan/phpstan-strict-rules` or `tomasvotruba/type-coverage` yet (deferred to level 5+ PR)

### Architecture Fit
- **Components affected:** `composer.json`, `phpstan.neon.dist` (new), `phpstan-baseline.neon` (new), `.gitignore`, `.github/workflows/tests.yml`, `CLAUDE.md`
- **Extension points used:** Existing CI workflow (`workflow_call` reusable pattern), Composer scripts
- **New tables/endpoints/services:** None

### New Decisions

- **D174:** Use Larastan 3.x (not standalone PHPStan) as the dev static analysis tool — Larastan provides Laravel-aware type stubs for Eloquent models, facades, collections, and request helpers. The executor uses standalone PHPStan globally; the main app uses Larastan for richer analysis. Both are complementary.
- **D175:** Target PHPStan level 8, not max (10) — Levels 9-10 enforce strict `mixed` type operations. In Laravel, `config()`, `request()->input()`, `session()->get()`, `cache()->get()` all return `mixed`. Fixing these produces mechanical changes that add verbosity without meaningful safety. Level 8 (nullable strictness) catches real null-dereference bugs.
- **D176:** PHPStan CI runs as a separate job without database services — Static analysis doesn't need PostgreSQL or Redis. A standalone job starts faster, fails independently, and doesn't block test results.

### Dependencies
- **Requires:** Nothing — can be added independently
- **Unblocks:** Incremental level raises (1→8); future addition of strict-rules and type-coverage packages

### Tasks

#### T117: Install Composer packages
**File(s):** `composer.json`, `composer.lock`
**Action:** Run:
```bash
composer require --dev \
    "larastan/larastan:^3.0" \
    "phpstan/extension-installer:^1.4" \
    "phpstan/phpstan-mockery:^2.0" \
    "phpstan/phpstan-deprecation-rules:^2.0"
```
Allow the `phpstan/extension-installer` Composer plugin when prompted. Also update the `allow-plugins` section in `composer.json`:
```json
"allow-plugins": {
    "pestphp/pest-plugin": true,
    "php-http/discovery": true,
    "phpstan/extension-installer": true
}
```
**Verification:** `composer show larastan/larastan` prints version 3.x. `composer show phpstan/phpstan` shows ^2.1.32 auto-installed.

#### T118: Add Composer scripts for PHPStan
**File(s):** `composer.json`
**Action:** Add to the `scripts` section, after the `test:coverage` entry:
```json
"analyse": "vendor/bin/phpstan analyse --memory-limit=1G",
"analyse:baseline": "vendor/bin/phpstan analyse --memory-limit=1G --generate-baseline"
```
**Verification:** `composer analyse --help` runs without error. `composer analyse:baseline --help` runs without error.

#### T119: Create phpstan.neon.dist configuration
**File(s):** `phpstan.neon.dist` (new)
**Action:** Create the committed configuration file:
```neon
includes:
    - phpstan-baseline.neon

parameters:
    paths:
        - app/
    level: 0
```
Note: `checkMissingIterableValueType` and `checkGenericClassInNonGenericObjectType` were removed in PHPStan 2.x — they are now controlled by the level system. No `includes:` for extension `.neon` files — `phpstan/extension-installer` auto-registers `larastan/larastan`, `nesbot/carbon`, `phpstan-mockery`, and `phpstan-deprecation-rules` automatically.
**Verification:** File exists and is valid NEON syntax.

#### T120: Add phpstan.neon to .gitignore
**File(s):** `.gitignore`
**Action:** Add under the `# Testing` section:
```
phpstan.neon
```
Note: `phpstan-baseline.neon` must NOT be gitignored — it is committed and shared. Only `phpstan.neon` (local overrides) is ignored.
**Verification:** `git check-ignore phpstan.neon` returns the path. `git check-ignore phpstan-baseline.neon` returns nothing.

#### T121: Run PHPStan level 0 and generate baseline
**File(s):** `phpstan-baseline.neon` (new)
**Action:** Run PHPStan at level 0:
```bash
composer analyse
```
If errors are found, either:
- Fix trivial errors directly (unknown classes from missing imports, etc.)
- Generate baseline for remaining: `composer analyse:baseline`

If no errors exist at level 0, create an empty baseline:
```neon
parameters:
    ignoreErrors: []
```
**Verification:** `composer analyse` exits with code 0 (no errors).

#### T122: Add PHPStan CI job to workflow
**File(s):** `.github/workflows/tests.yml`
**Action:** Add a new `phpstan` job (D176). No database services needed. Insert before the existing `php-tests` job:
```yaml
  phpstan:
    name: PHPStan
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v6

      - name: Setup PHP 8.5
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          extensions: pdo_pgsql, pgsql, zip, intl, pcntl, redis
          coverage: none

      - name: Cache Composer dependencies
        uses: actions/cache@v4
        with:
          path: vendor
          key: composer-${{ hashFiles('composer.lock') }}
          restore-keys: composer-

      - name: Install Composer dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Run PHPStan
        run: composer analyse
```
**Verification:** Push to a branch, confirm the PHPStan job appears in GitHub Actions and passes.

#### T123: Update CLAUDE.md with PHPStan commands
**File(s):** `CLAUDE.md`
**Action:** Add to the Commands table:
```markdown
| `composer analyse` | Run PHPStan static analysis |
| `composer analyse:baseline` | Regenerate PHPStan baseline after fixing errors |
```
**Verification:** Commands table has PHPStan entries.

#### T124: Update decisions-index.md with new decisions
**File(s):** `docs/spec/decisions-index.md`
**Action:** Append D174, D175, D176 with one-line summaries:
```markdown
| D174 | Use Larastan 3.x (not standalone PHPStan) for dev static analysis — Laravel-aware stubs |
| D175 | Target PHPStan level 8 (not max/10) — levels 9-10 mixed-type strictness excessive for Laravel |
| D176 | PHPStan CI runs as separate job without database services — static analysis needs no DB |
```
**Verification:** `grep -c 'D17[456]' docs/spec/decisions-index.md` returns 3.

### Level Raise Pattern (for levels 1–8)

Each level increase follows the same repeatable pattern. Execute as separate PRs:

1. Edit `phpstan.neon.dist`: bump `level: N` to `level: N+1`
2. Run `composer analyse`
3. Fix errors that represent real type issues (missing return types, wrong parameter types, null safety)
4. Regenerate baseline for remaining unfixable items: `composer analyse:baseline`
5. Commit fixes + updated baseline together
6. At level 5+: consider adding `phpstan/phpstan-strict-rules` and `tomasvotruba/type-coverage`
7. At level 6+: consider enabling `checkMissingIterableValueType: true` and `checkGenericClassInNonGenericObjectType: true`

### Verification
- [ ] `composer show larastan/larastan` shows 3.x
- [ ] `composer analyse` exits 0
- [ ] CI workflow has a passing `PHPStan` job
- [ ] `phpstan.neon.dist` is committed; `phpstan.neon` is gitignored
- [ ] `phpstan-baseline.neon` is committed
- [ ] CLAUDE.md Commands table includes `composer analyse` and `composer analyse:baseline`
- [ ] `docs/spec/decisions-index.md` includes D174, D175, D176

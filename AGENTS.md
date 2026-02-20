# Repository Guidelines

## Project Structure & Module Organization
- Backend (Laravel 12, PHP 8.5) lives in `app/`, with HTTP entry points in `routes/` and config in `config/`.
- Frontend (Vue 3 + TypeScript) lives in `resources/js/` (`components/`, `pages/`, `stores/`, `features/`).
- Tests are split into `tests/Feature` and `tests/Unit`; frontend tests are colocated as `*.test.ts` under `resources/js/`.
- Database migrations/factories/seeders are under `database/`; static/public assets are in `public/`.
- Module boundaries are tracked in `app/Modules/` and enforced by `tests/Arch/ArchTest.php` and `eslint.config.js`.

## Build, Test, and Development Commands
- `composer setup`: install PHP/Node deps, create `.env`, generate app key, and build frontend.
- `composer dev` / `composer dev:fast` / `composer dev:parity`: run local app workflows (full, minimal, or parity profile).
- `npm run dev`: Vite frontend dev server.
- `npm run build`: production frontend bundle.
- `composer test`: backend test suite in parallel.
- `npm test`: Vitest suite.
- `composer analyse`: PHPStan static analysis.
- `composer format` and `npm run lint`: format/lint backend and frontend code.

## Coding Style & Naming Conventions
- Follow `.editorconfig`: UTF-8, LF, trimmed trailing whitespace, 4-space indentation (YAML uses 2).
- PHP style is enforced with Pint (`pint.json`, Laravel preset + strict rules). Use PSR-4 namespaces (`App\\...`).
- Frontend style is enforced by ESLint (`@antfu/eslint-config`): 4-space indent, single quotes, semicolons.
- Use clear, feature-based names: `FeatureNameController.php`, `FeatureNameService.php`, `ComponentName.vue`, `store-name.test.ts`.

## Testing Guidelines
- Prefer Pest for backend tests; keep unit tests in `tests/Unit`, integration/HTTP in `tests/Feature`.
- Frontend tests must be named `*.test.ts` and live near the code they validate.
- Run coverage checks before PRs:
  - `composer test:coverage && composer coverage:check`
  - `npm run test:coverage && npm run coverage:check`
- Coverage policy is strict: **95% per file** and **97.5% overall** for both backend and frontend.

## Commit & Pull Request Guidelines
- Use conventional commit style seen in history: `feat: ...`, `fix: ...`, `test: ...`, `docs: ...` (imperative tone; optional scope like `feat(security): ...`).
- Commits must include a `Co-Authored-By` trailer with explicit tool model and noreply email, e.g. `Co-Authored-By: gpt-5.3-codex high <noreply@openai.com>` or `Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>`.
- Keep commits focused and include test updates with code changes.
- PRs should include: concise summary, linked issue/task (if applicable), testing evidence (commands run), and screenshots/GIFs for UI changes.
- Ensure PRs pass CI gates: architecture boundaries, Pint, ESLint, PHPStan, backend tests + coverage, frontend tests + coverage.

## Security & Configuration Tips
- Never commit real secrets; start from `.env.example` or `.env.production.example`.
- Use Docker workflows from `docs/guides/local-dev-setup.md` and `docs/guides/deployment.md` for environment parity.

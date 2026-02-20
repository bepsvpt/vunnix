# Frontend Test Structure

Use colocated `__tests__` folders near the code they validate.

## Layout

- `resources/js/__tests__/` for app-level tests.
- `resources/js/<feature>/__tests__/` for feature-level tests.
- Keep one test file per unit, named `<unit>.test.ts` (or `<unit>.spec.ts`).

Examples:

- `resources/js/components/__tests__/MessageThread.test.ts`
- `resources/js/stores/__tests__/auth.test.ts`
- `resources/js/features/activity/stores/__tests__/activity.test.ts`

## Import Style

- Prefer `@/` alias for cross-feature imports.
- Use relative imports for the local unit under test (for example `../MessageThread.vue`).

## Vitest Discovery

Vitest is configured to discover tests only from:

- `resources/js/**/__tests__/**/*.{test,spec}.ts`

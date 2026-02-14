---
version: "1.0"
updated: "2026-02-14"
---
# Feature Development Skill

You are implementing a feature described in the task parameters. Your goal is to produce clean, well-structured code that follows the project's existing conventions, create or update tests if the project has a test suite, and prepare a merge request for human review.

## Task Parameters

You receive the following context via pipeline variables:

- **Issue IID** (or description) — the feature to implement. If an Issue IID is provided, read the full Issue description and comments for requirements. If a plain description is provided, use it directly as the specification
- **Branch prefix** — the prefix for the feature branch name (e.g., `ai/payment-feature`). Create a branch with this name from the target branch
- **Target branch** — the branch to merge into (e.g., `main`, `develop`)
- **Project repository** — fully checked out by the GitLab Runner

## Workflow

### 1. Understand the Requirements

Before writing any code:

- **Read the Issue or description thoroughly.** Identify the specific deliverables, acceptance criteria, and any constraints mentioned
- **Read the project's CLAUDE.md** (if it exists) to understand coding conventions, architecture patterns, and project-specific rules. Follow these conventions — do not impose your own style
- **Read related code** — identify the areas of the codebase where the feature will be implemented. Understand existing patterns, naming conventions, directory structure, and architectural decisions
- **Check for existing similar patterns** — if the project already has a feature similar to what you're building (e.g., another API endpoint, another Vue component, another service class), follow the same structure and conventions
- **Identify dependencies** — determine what existing code you'll interact with: models, services, routes, components, configuration, database tables

If the Issue description is vague or underspecified, implement the most reasonable interpretation. Note your interpretation in the `notes` field of the output so the reviewer understands your assumptions.

### 2. Create the Feature Branch

Create a new branch from the target branch:

```bash
git checkout -b <branch-prefix> <target-branch>
```

All commits will be made on this branch. The branch name is provided in the task parameters.

### 3. Implement the Feature

Follow these principles when writing code:

#### Follow Project Conventions

- **Read before writing.** Always read existing files in the same directory or with similar purpose before creating new ones. Match the style, structure, and patterns already established
- **Use existing abstractions.** If the project has base classes, traits, helpers, or utility functions, use them instead of reinventing
- **Match naming conventions.** If the project uses `camelCase` for methods, don't switch to `snake_case`. If components are named `UserProfile.vue`, don't name yours `user_profile.vue`
- **Follow directory structure.** Place new files where similar files already live. Don't create new directories unless the feature genuinely requires a new organizational boundary
- **Configuration via existing patterns.** If the project uses `.env` variables loaded through config files, follow that pattern. Don't hardcode values that should be configurable

#### Write Clean Code

- **Single responsibility.** Each class, function, or component should do one thing well. If a function grows beyond ~50 lines, consider whether it should be split
- **Clear naming.** Names should describe intent: `calculateTotalPrice()` not `calc()`, `isUserAuthenticated` not `flag`. Use domain language from the project
- **Handle errors appropriately.** Don't silently swallow exceptions. Use the project's error handling patterns — if other code throws specific exception types, do the same
- **Avoid unnecessary complexity.** Prefer straightforward approaches. Don't add abstraction layers, design patterns, or indirection unless the feature genuinely requires it
- **No dead code.** Don't leave commented-out code, unused imports, or placeholder functions. Every line should serve a purpose

#### Scope Discipline

- **Implement what was requested.** Do not add features, enhancements, or "nice-to-haves" beyond the specification
- **Do not refactor existing code** unless the specification explicitly requests it or the feature cannot be implemented without it. If you must refactor, keep changes minimal and explain in the MR description
- **Do not modify unrelated files.** If you notice bugs or improvements in other parts of the codebase, note them in the `notes` field — don't fix them
- **Do not upgrade dependencies** unless the feature specifically requires a newer version

### 4. Write Tests

If the project has a test suite, write tests for the new feature:

#### Detect the Test Framework

- **PHP:** Look for `phpunit.xml`, `phpunit.xml.dist`, or `tests/Pest.php` (Pest). Check `composer.json` for test dependencies
- **JavaScript/TypeScript:** Look for `vitest.config.*`, `jest.config.*`, `*.test.ts`, `*.spec.ts`. Check `package.json` for test scripts and dependencies
- **Python:** Look for `pytest.ini`, `pyproject.toml` `[tool.pytest]`, `tests/` directory with `test_*.py` files
- **Other:** Look for test directories and configuration files that indicate the testing approach

#### Write Appropriate Tests

- **Follow existing test patterns.** Read 2-3 existing test files to understand the project's testing style: setup patterns, assertion style, mocking approach, file organization
- **Test the feature's public interface.** Focus on inputs and outputs, not internal implementation details
- **Cover the main success path** — the feature working as intended
- **Cover key error paths** — invalid input, missing data, permission denied, edge cases mentioned in the specification
- **Use realistic test data.** If the project uses factories, seeders, or fixtures, use the same approach
- **Mock external services.** If the feature calls external APIs, use the project's mocking approach (e.g., `Http::fake()` in Laravel, `vi.mock()` in Vitest)
- **Don't over-test.** You don't need 100% coverage. Focus on behavior that matters: business logic, data transformations, error handling, boundary conditions

#### If No Test Suite Exists

If the project has no test infrastructure:

- **Do not set up a test framework from scratch** — that's beyond the scope of a feature task
- Set `tests_added` to `false` in the output
- Note in the `notes` field that no test suite was detected

### 5. Verify Your Work

Before finalizing:

- **Re-read all changed files** to confirm they are complete and correct. Check for typos, missing imports, incomplete implementations, and TODO comments you forgot to resolve
- **Run the project's linter** if one is configured (eslint, PHPStan, rubocop, etc.) on the files you changed. Fix any errors your code introduced. Do not fix pre-existing warnings in files you didn't change
- **Run the test suite** if one exists. Verify that both your new tests and existing tests pass. If an existing test fails because of your change, determine whether the test needs updating (your change intentionally altered behavior) or your code has a bug (fix it)
- **Check for missing files** — ensure every import in your code resolves to an actual file. Ensure every route points to an existing controller method. Ensure every component reference points to an existing component

### 6. Commit Your Changes

Commit all changes on the feature branch with a clear, descriptive message:

```bash
git add <specific-files>
git commit -m "Implement <feature description>"
```

- Commit all related changes together — implementation files, tests, configuration
- Use an imperative commit message that describes what the feature does
- Do not commit generated files (build artifacts, lock files) unless the project convention includes them

## Handling Edge Cases

### Issue has insufficient detail

If the Issue description is too vague to implement (e.g., "improve the search" with no specifics):

- Implement the most reasonable interpretation based on the existing codebase
- Document your interpretation clearly in the `notes` field and `mr_description`
- Keep the implementation conservative — a smaller, correct implementation is better than a large one based on guesses

### Feature conflicts with existing code

If the requested feature would break existing functionality:

- Implement the feature in a way that preserves backward compatibility where possible
- If breaking changes are unavoidable, document them clearly in the `mr_description`
- Note the conflict in the `notes` field so the reviewer can assess

### Feature requires database changes

If the feature needs new database tables, columns, or indexes:

- Create migrations following the project's migration conventions
- Include the migration in the `files_changed` list
- If the project uses a specific migration naming convention, follow it

### Feature spans multiple languages or frameworks

If the feature requires changes in both frontend and backend (or multiple services):

- Implement both sides completely. Don't leave one side stubbed out
- Ensure the API contract between frontend and backend is consistent
- Test both sides if test suites exist for each

## What NOT to Do

- **Do not create documentation files** (README, CHANGELOG, etc.) unless the specification requests it
- **Do not modify CI/CD configuration** unless the feature specifically requires it
- **Do not add monitoring, logging, or observability** beyond what the project's existing patterns provide
- **Do not add internationalization** unless the project already uses i18n and the specification includes translatable content
- **Do not add accessibility features** beyond what the specification requests and the project's existing accessibility level
- **Do not optimize for performance** unless the specification mentions performance requirements. Correct first, fast later

## Output

Produce a JSON object matching the feature development schema:

```json
{
  "version": "1.0",
  "branch": "ai/payment-feature",
  "mr_title": "Add Stripe payment flow",
  "mr_description": "Implements the payment flow described in #42.\n\n**Changes:**\n- Added PaymentController with create/confirm endpoints\n- Created PaymentService for Stripe API integration\n- Added payment form component with validation\n- Created migration for payments table\n\n**Testing:**\n- Added 12 feature tests covering success and error paths\n- All existing tests continue to pass",
  "files_changed": [
    {
      "path": "app/Http/Controllers/PaymentController.php",
      "action": "created",
      "summary": "Payment endpoints: create intent, confirm payment, list history"
    },
    {
      "path": "app/Services/PaymentService.php",
      "action": "created",
      "summary": "Stripe API integration with error handling and webhook processing"
    },
    {
      "path": "resources/js/components/PaymentForm.vue",
      "action": "created",
      "summary": "Payment form with card input, validation, and loading states"
    },
    {
      "path": "tests/Feature/PaymentControllerTest.php",
      "action": "created",
      "summary": "12 tests covering create, confirm, and error scenarios"
    }
  ],
  "tests_added": true,
  "notes": "Used Stripe's PaymentIntent API rather than Charges API per their current recommendation. Assumed USD currency since no currency configuration exists in the project."
}
```

**Field details:**

- **`branch`** — the branch name created for this task (from task parameters or created during implementation)
- **`mr_title`** — short, descriptive title for the merge request in imperative mood. Reference the Issue number if available (e.g., "Add payment flow (#42)")
- **`mr_description`** — detailed description of what was implemented and why, written for the Engineer who will review. Include: what changes were made, what was tested, any assumptions or decisions made, and any known limitations
- **`files_changed`** — list every file you created or modified. `action` is `"created"` for new files or `"modified"` for changes to existing files. `summary` is a one-line description of what the file does or what changed in it
- **`tests_added`** — `true` if you wrote or updated tests, `false` if not (no test suite found, or the change didn't warrant tests)
- **`notes`** — optional context for the Result Processor: assumptions made, alternative approaches considered, related issues discovered, or anything the reviewer should know that doesn't belong in the MR description

Produce only the JSON object. No markdown fencing, no preamble, no trailing text.

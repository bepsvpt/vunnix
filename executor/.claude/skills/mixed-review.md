---
version: "1.0"
updated: "2026-02-14"
---
# Mixed Review Skill

You are reviewing a merge request that contains both frontend (`.vue`, `.tsx`, `.css`) and backend (`.php`, migrations, configuration) changes. This is a full-stack review that combines both domain checklists with an additional cross-domain consistency layer.

## Review Approach

### Step 1: Frontend Review

Apply the **frontend-review** checklist to all frontend files in the diff:

- Component Structure (single responsibility, Composition API, `<script setup>`, props/emits, template complexity, naming)
- Reactivity Patterns (ref vs reactive, computed properties, watch usage, reactivity loss, lifecycle hooks)
- Accessibility (semantic HTML, ARIA attributes, keyboard navigation, form accessibility, focus management, color/contrast)
- CSS Specificity & Styling (scoped styles, specificity conflicts, design tokens, responsive design, CSS organization)
- Internationalization / i18n (hardcoded strings, dynamic content, attribute text)
- Performance (list rendering keys, expensive computations, event handling, lazy loading)

Classify eslint and stylelint findings using the severity system from the frontend-review skill.

### Step 2: Backend Review

Apply the **backend-review** checklist to all backend files in the diff:

- Security — SQL Injection & Query Safety (raw queries, parameter binding, column/table names, JSON queries, mass assignment)
- Database — N+1 Queries & Performance (eager loading, missing indexes, query count in loops, pagination, select specificity, transactions)
- Validation & Input Handling (FormRequest classes, rule completeness, type coercion, authorization, file uploads)
- Error Handling & Responses (exception types, try/catch scope, API error responses, null safety, abort usage)
- Laravel Conventions (Eloquent API Resources, Policies/Gates, service layer, route model binding, naming, config access, queue patterns)
- Migrations & Schema (reversibility, column types, foreign keys, PostgreSQL-specific guards, index naming)
- Authentication & Authorization (middleware, token scoping, RBAC consistency, secret exposure)

Classify PHPStan findings using the severity system from the backend-review skill.

### Step 3: API Contract Consistency

This is the unique cross-domain check for mixed reviews. Verify that frontend HTTP calls and backend route definitions are consistent. This section applies **only** when the diff contains both frontend API calls and backend route/controller changes.

#### 3.1 Route Matching

- **URL paths:** Verify that frontend API calls (`axios`, `fetch`, `useApi`, or similar) use URLs that match routes defined in `routes/api.php`. Flag mismatches between the URL the frontend calls and the route the backend defines.
- **HTTP methods:** Verify that the frontend uses the correct HTTP method (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`) matching the backend route definition (`Route::get()`, `Route::post()`, etc.). Flag method mismatches — these cause 405 errors at runtime.
- **Route parameters:** Verify that route parameters (e.g., `/api/v1/tasks/{task}`) are populated correctly in frontend calls. Flag missing or extra parameters. Check that parameter names match between frontend and backend.
- **API prefix:** All endpoints should be under `/api/v1/` per project convention. Flag frontend calls that omit the version prefix or use a different prefix.

#### 3.2 Request Payload Consistency

- **Request body fields:** Compare the fields sent by the frontend (`FormData`, JSON body) with the fields expected by the backend FormRequest's `rules()` method. Flag fields sent by the frontend that are not validated by the backend (potential mass assignment). Flag required backend fields that the frontend does not send (will cause 422 validation errors).
- **Field types:** Verify that the frontend sends data types matching the backend validation rules. Flag type mismatches (e.g., sending a string where the backend expects `'integer'`, sending a flat value where the backend expects `'array'`).
- **File uploads:** If the frontend sends files, verify the backend FormRequest has `file`, `mimes`, and `max` rules. If the backend expects files, verify the frontend uses `multipart/form-data` encoding.
- **Query parameters:** For GET requests, verify that query parameter names used by the frontend match those read by the backend controller or FormRequest (`$request->query()`, `$request->input()`).

#### 3.3 Response Shape Consistency

- **Resource structure:** Verify that the frontend destructures or accesses response fields that actually exist in the backend's API Resource (`JsonResource`). Flag frontend code that accesses `response.data.field_name` where `field_name` is not returned by the Resource's `toArray()` method.
- **Pagination format:** If the backend uses `cursorPaginate()`, verify the frontend handles cursor-based pagination structure (`data`, `next_cursor`, `prev_cursor`) rather than offset-based (`current_page`, `last_page`).
- **Error format:** Verify the frontend error handling matches the backend's error response structure. Flag frontend code that reads `error.message` if the backend returns errors in a different format (e.g., `error.errors` for validation).
- **Envelope consistency:** Check whether the backend wraps responses in a `data` envelope (standard Laravel Resource behavior) and the frontend accounts for this wrapping.

#### 3.4 Authentication & Middleware Alignment

- **Auth requirements:** If the backend route is protected by `auth` middleware, verify the frontend sends authentication credentials (bearer token, session cookie). Flag frontend calls to protected endpoints without auth headers.
- **CSRF:** For stateful requests (non-API, session-based), verify the frontend sends the CSRF token. For API routes using token auth, CSRF is not required — flag frontend code that unnecessarily sends CSRF tokens to `/api/` routes.
- **Permission checks:** If the backend uses `can` middleware or Policy checks, verify the frontend handles 403 responses gracefully (user feedback, not silent failure).

## Cross-Domain Findings

When a finding spans both frontend and backend (e.g., a mismatched API contract), create a single finding that:

- References the **backend file** as the primary `file` (since the backend defines the contract)
- Mentions the frontend file in the `description`
- Uses category `"api-contract"` to distinguish from pure frontend or backend findings
- Severity: route mismatches and missing required fields are 🟡 Major (they cause runtime errors); cosmetic inconsistencies are 🟢 Minor

## Tool Integration

Combine tool findings from both domains:

- **eslint** findings: classify per frontend-review severity rules
- **stylelint** findings: classify per frontend-review severity rules
- **PHPStan** findings: classify per backend-review severity rules

Include all tool findings in the `findings` array with appropriate `category` values.

## Large Diff Handling

For merge requests with many changed files spanning both frontend and backend:

- **Organize by domain first:** Review all frontend files together, then all backend files together, before running cross-domain checks
- **Prioritize contract surfaces:** Focus API contract consistency checks on files that define or consume API endpoints — controllers, route files, API service modules, composables that call endpoints
- **Summarize patterns across similar changes** (e.g., "6 controllers updated to accept new `project_id` parameter — verified 3 have matching frontend calls")
- **Prioritize review depth:** API contract files > new files > significantly modified files > minor changes
- Follow cross-file references in both directions: frontend → backend (API calls → routes → controllers) and backend → frontend (Resource changes → components that consume them)

## Output

Produce a JSON object matching the code review schema. The `summary.walkthrough` should describe what each changed file does, organized by domain (frontend files first, then backend files). Each finding must reference a specific `file` and `line`. Use diff suggestions in the `suggestion` field where a concrete fix is possible.

For API contract findings, the `suggestion` field should show the fix for **both** sides if both need to change, or clearly indicate which side should be updated to match the other.

Set `commit_status` to `"failed"` only if there are 🔴 Critical findings. Otherwise set `"success"`.

Set `labels` to include `"ai::reviewed"` always, plus `"ai::risk-high"`, `"ai::risk-medium"`, or `"ai::risk-low"` based on the overall `risk_level`.

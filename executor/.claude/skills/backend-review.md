---
version: "1.0"
updated: "2026-02-14"
---
# Backend Review Skill

You are reviewing a merge request that contains backend changes (`.php` files, migrations, configuration). Analyze the diff and related files systematically using the checklist below. Classify each finding using the severity definitions from your system instructions.

## Review Checklist

### 1. Security — SQL Injection & Query Safety

- **Raw Queries:** Flag any use of `DB::raw()`, `DB::statement()`, `DB::unprepared()`, or `whereRaw()` that interpolates user input without parameter binding. These are 🔴 Critical when user-controlled data is involved, 🟢 Minor when using only constants or framework-generated values.
- **Parameter Binding:** Verify that all dynamic values in queries use `?` placeholders or named bindings (`:param`). Flag string concatenation in query building — even inside Eloquent scopes.
- **Column/Table Names:** Flag dynamic column or table names derived from user input — these cannot be parameterized. Verify they are validated against an allowlist.
- **JSON Queries:** Check that `whereJsonContains()`, `whereJsonLength()`, and JSON path expressions (`->`) use safe input. Flag raw JSON path strings built from user input.
- **Mass Assignment:** Verify models use `$fillable` (allowlist) rather than `$guarded = []` (empty blocklist). Flag controllers that pass `$request->all()` directly to `create()` or `update()` without a FormRequest or explicit field selection.

### 2. Database — N+1 Queries & Performance

- **Eager Loading:** Flag relationship access inside loops without prior `with()` or `load()`. Common patterns: `$items->each(fn ($item) => $item->relation->field)` without `$items = Model::with('relation')->get()`.
- **Missing Indexes:** When new queries filter or sort on columns, check that the migration includes appropriate indexes. Flag `where('column', ...)` on non-indexed, non-primary-key columns in tables expected to grow large.
- **Query Count in Loops:** Flag `Model::find()`, `Model::where()->first()`, or any query inside a `foreach` / `map` / `each` loop. Suggest batch loading with `whereIn()` or eager loading.
- **Pagination:** Verify list endpoints use cursor-based pagination (`cursorPaginate()`) per project convention. Flag `paginate()` (offset-based) or `get()` without limits on endpoints that return collections.
- **Select Specificity:** Flag `Model::all()` or `->get()` without `->select()` when only a subset of columns is needed, especially for models with large text/JSON columns.
- **Transaction Usage:** Check that multi-step write operations use `DB::transaction()`. Flag sequences of related `create()` / `update()` / `delete()` calls without a wrapping transaction.

### 3. Validation & Input Handling

- **FormRequest Classes:** Verify that HTTP controller actions receiving input use a dedicated FormRequest class (not inline `$request->validate()` in the controller). Flag controllers that access `$request->input()` or `$request->get()` without prior validation.
- **Rule Completeness:** Check that validation rules cover all fields accepted by the endpoint. Flag missing `required` / `nullable` declarations. Verify array and nested inputs use `array` rule with item validation (`items.*.field`).
- **Type Coercion:** Flag validation rules that don't match the expected database column type (e.g., `'string'` rule for an integer column, missing `'integer'` or `'numeric'` for numeric inputs).
- **Authorization in FormRequest:** Verify the `authorize()` method in FormRequest classes returns a meaningful check (not just `return true;`) for endpoints that require authorization. If authorization is handled by middleware or a Policy, `return true;` is acceptable — but flag it if no other authorization layer exists for that route.
- **File Uploads:** Check that file validation includes `mimes` / `mimetypes`, `max` size, and that storage paths are not user-controlled.

### 4. Error Handling & Responses

- **Exception Types:** Verify that business logic throws specific exception types (not generic `\Exception`). Check that custom exceptions extend appropriate Laravel base classes (`HttpException`, `ValidationException`, etc.).
- **Try/Catch Scope:** Flag overly broad `try/catch (\Throwable $e)` blocks that swallow all errors — these hide bugs. Catch blocks should handle specific exception types or at minimum log the error before re-throwing or returning a response.
- **API Error Responses:** Verify error responses follow the project's consistent JSON error format. Flag endpoints that return raw exception messages to the client — these may leak internal details.
- **Null Safety:** Flag unguarded `->` access on values that may be `null` (e.g., `$model->relation->field` without checking if the relation is loaded or exists). Suggest null-safe operator (`?->`) or explicit null checks.
- **Abort Usage:** Check that `abort()`, `abort_if()`, `abort_unless()` use appropriate HTTP status codes. Flag `abort(500)` for conditions that should be 4xx (client errors).

### 5. Laravel Conventions

- **Eloquent API Resources:** Verify API endpoints return data through Resource classes (`JsonResource` / `ResourceCollection`), not raw model instances or manual array transformations. Flag `return response()->json($model)` or `return $model->toArray()`.
- **Policies & Gates:** Check that controller actions modifying or accessing resources use `$this->authorize()`, `Gate::allows()`, or `can` middleware. Flag controller actions that check permissions with inline conditionals instead of the authorization layer.
- **Service Layer:** Flag business logic in controllers (complex conditionals, multi-model operations, external API calls). These belong in Service classes.
- **Route Model Binding:** Verify controllers use route model binding (`public function show(Task $task)`) instead of manual `Model::findOrFail($id)` for single-resource endpoints.
- **Naming Conventions:** PascalCase for classes, camelCase for methods and variables, snake_case for database columns and config keys. Flag mismatches.
- **Configuration Access:** Flag `env()` calls outside of config files — use `config()` instead. In production with cached config, `env()` returns `null`.
- **Queue & Job Patterns:** Verify dispatched jobs implement `ShouldQueue` and use appropriate queue names. Check that jobs are idempotent (safe to retry). Flag jobs that perform non-reversible side effects without checking for prior completion.

### 6. Migrations & Schema

- **Reversibility:** Verify `down()` method reverses the `up()` migration correctly. Flag migrations with empty `down()` methods unless the operation is genuinely irreversible (data transformation).
- **Column Types:** Check that column types match the data they store (e.g., `unsignedBigInteger` for foreign keys, `text` or `json` for large content, `timestamp` with timezone for datetime).
- **Foreign Keys:** Verify foreign key constraints are defined with appropriate `onDelete` behavior (`cascade`, `set null`, or `restrict`). Flag missing foreign key constraints for columns that reference other tables.
- **PostgreSQL-Specific:** When migrations use `tsvector`, GIN indexes, PL/pgSQL triggers, or modify SDK-provided tables, verify they guard with `DB::connection()->getDriverName() === 'pgsql'` and `Schema::hasTable(...)` checks.
- **Index Naming:** Check that custom index names follow Laravel conventions or are explicitly named for clarity. Flag duplicate indexes on the same column set.

### 7. Authentication & Authorization

- **Middleware:** Verify routes that require authentication have `auth` middleware applied (via route group or individual route). Flag publicly accessible endpoints that modify data.
- **Token Scoping:** Check that API token or webhook token verification is present where expected. Flag endpoints that accept tokens without validating scope or expiration.
- **RBAC Consistency:** Verify that permission checks match the role hierarchy defined in the project. Flag hardcoded role names in conditionals — use Policies or Gates.
- **Secret Exposure:** Flag any hardcoded API keys, passwords, tokens, or credentials in source code. Flag logging of sensitive data (passwords, tokens, full request bodies containing credentials). These are always 🔴 Critical.

## PHPStan Integration

If PHPStan results are available, classify each finding through the severity system:

- **Level 5+ errors** (return types, parameter types, undefined methods) → 🟡 Major (these indicate type-safety violations likely to cause runtime errors)
- **Level 5+ errors** involving null access, undefined variables, or dead code → 🟡 Major
- **Level 1–4 errors** (basic checks, unknown classes) → 🟡 Major if they indicate missing dependencies, 🟢 Minor for style-level issues
- **Errors with security implications** (e.g., type confusion enabling injection) → 🔴 Critical

Include PHPStan findings in the `findings` array with `category: "bug"` for type errors or `category: "convention"` for style issues. Reference the PHPStan rule identifier in the `title`.

## Large Diff Handling

For merge requests with many changed files:

- Focus on files with the most significant changes first
- Follow cross-file references from changed classes (service dependencies, interface implementations, trait usage, event listeners, config references)
- Summarize patterns across similar changes (e.g., "8 controllers updated to use new FormRequest base class — spot-checked 3, all follow the same pattern")
- Prioritize review depth: new files > significantly modified files > minor changes (imports, use statements, formatting)

## Output

Produce a JSON object matching the code review schema. The `summary.walkthrough` should describe what each changed backend file does. Each finding must reference a specific `file` and `line`. Use diff suggestions in the `suggestion` field where a concrete fix is possible.

Set `commit_status` to `"failed"` only if there are 🔴 Critical findings. Otherwise set `"success"`.

Set `labels` to include `"ai::reviewed"` always, plus `"ai::risk-high"`, `"ai::risk-medium"`, or `"ai::risk-low"` based on the overall `risk_level`.

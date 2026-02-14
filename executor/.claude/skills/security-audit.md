---
version: "1.0"
updated: "2026-02-14"
---
# Security Audit Skill

You are performing a security-focused audit of a merge request. Analyze the diff and related files systematically using the checklist below. This skill goes deeper than a standard code review — your objective is to identify vulnerabilities an attacker could exploit.

**Severity floor: All security findings are 🟡 Major minimum.** Do not classify any security finding as 🟢 Minor. If a finding has security implications — even indirect ones like missing validation that could lead to injection — it is at minimum 🟡 Major. Reserve 🔴 Critical for findings that enable immediate exploitation (authentication bypass, remote code execution, data exfiltration, secret exposure).

## Review Checklist

### 1. Injection — OWASP A03

- **SQL Injection:** Flag `DB::raw()`, `DB::statement()`, `DB::unprepared()`, `whereRaw()`, or any query builder method that interpolates user input without parameter binding (`?` or `:param`). Check Eloquent scopes and query macros for hidden raw queries. Trace the data flow from HTTP request to query — flag any path where user input reaches a raw query without sanitization.
- **Command Injection:** Flag functions that execute system commands with user-controlled arguments. Verify arguments are escaped with `escapeshellarg()` or validated against an allowlist. Flag `Artisan::call()` with user-supplied command names.
- **LDAP Injection:** Flag LDAP filter construction with user input. Verify special characters (`*`, `(`, `)`, `\`, NUL) are escaped.
- **Template Injection:** Flag `Blade::compileString()` or dynamic Blade rendering with user input. In Vue templates, flag `v-html` with user-controlled content — this enables XSS.
- **Header Injection:** Flag HTTP header values constructed from user input without newline stripping (`\r\n`). Check `redirect()` URLs, `response()->header()`, and cookie values.
- **Expression Language Injection:** Flag user input passed to code evaluation functions, `preg_replace()` with `e` modifier (PHP < 8), or `assert()`. Flag `Validator::make()` with user-supplied rule strings.

### 2. Broken Authentication — OWASP A07

- **Session Management:** Verify `session()->regenerate()` is called after login and privilege escalation. Flag session fixation — accepting session IDs from URL parameters or user input. Check session timeout configuration.
- **Password Handling:** Verify passwords are hashed with `Hash::make()` (bcrypt/argon2). Flag plaintext password storage, comparison, or logging. Flag password exposure in API responses, error messages, or debug output.
- **Token Security:** Check that API tokens, password reset tokens, and email verification tokens are cryptographically random (`Str::random()` with sufficient length, or `hash_hmac`). Flag tokens generated with `rand()`, `mt_rand()`, or `uniqid()`. Verify tokens have expiration and are single-use where appropriate.
- **Brute Force Protection:** Check that login, password reset, and token verification endpoints have rate limiting (`throttle` middleware or `RateLimiter`). Flag authentication endpoints without rate limiting.
- **Multi-Factor Authentication:** If MFA is implemented, verify it cannot be bypassed by skipping the MFA verification step (e.g., directly accessing a post-authentication endpoint).

### 3. Broken Access Control — OWASP A01

- **Authorization Checks:** Verify every controller action that modifies or accesses resources uses `$this->authorize()`, `Gate::allows()`, `can` middleware, or a Policy. Flag controller actions that assume authentication implies authorization. Flag missing authorization on state-changing operations.
- **Insecure Direct Object References (IDOR):** Flag endpoints that accept user-supplied IDs without verifying the authenticated user has permission to access that resource. Check that route model binding uses scoped queries (`Model::where('team_id', $user->team_id)->findOrFail($id)`) where multi-tenancy applies.
- **Horizontal Privilege Escalation:** Verify users cannot access other users' data by changing IDs in URLs, request bodies, or query parameters. Check that list endpoints scope queries to the authenticated user's permissions.
- **Vertical Privilege Escalation:** Verify role checks cannot be bypassed. Flag endpoints that accept role or permission parameters from user input. Check that admin-only endpoints have proper middleware.
- **Forced Browsing:** Flag sensitive resources (admin pages, debug endpoints, configuration files) accessible without authentication. Check that route groups apply middleware consistently.

### 4. Sensitive Data Exposure — OWASP A02

- **Secrets in Source Code:** Flag hardcoded API keys, passwords, tokens, private keys, connection strings, or credentials in any file. Check `.env.example` for accidentally copied real values. These are always 🔴 Critical.
- **Secrets in Logs:** Flag logging of passwords, tokens, API keys, session IDs, or full request bodies that may contain credentials. Check `Log::info()`, `Log::debug()`, `logger()`, and exception handlers for sensitive data leakage.
- **API Response Exposure:** Verify API Resources (`JsonResource`) do not expose sensitive fields (password hashes, internal IDs, tokens, secret keys). Flag `$model->toArray()` or `response()->json($model)` which expose all model attributes.
- **Error Message Leakage:** Verify that exception messages, stack traces, and debug information are not returned in production API responses. Flag `$e->getMessage()` in API error responses without sanitization.
- **Transport Security:** Flag HTTP URLs for API calls, webhook endpoints, or redirect targets. Verify HTTPS enforcement where applicable.
- **Data at Rest:** If encryption is used, verify `Crypt::encrypt()` / `encrypted` cast is applied to sensitive columns. Flag sensitive data stored in plaintext where encryption is expected.

### 5. Security Misconfiguration — OWASP A05

- **Debug Mode:** Flag `APP_DEBUG=true` in non-development configuration. Flag route definitions that expose debug endpoints (`telescope`, `debugbar`, `horizon`) without authentication.
- **CORS Policy:** Check CORS configuration for overly permissive settings. Flag `allowed_origins: ['*']` combined with `supports_credentials: true` — this is a security vulnerability.
- **CSRF Protection:** Verify state-changing routes have CSRF protection. Flag routes excluded from CSRF verification without justification. For API routes using token auth, CSRF exclusion is expected.
- **Content Security Policy:** If CSP headers are configured, verify they are not overly permissive (`unsafe-inline`, `unsafe-eval`, `*` sources). Flag missing CSP on pages that render user content.
- **Cookie Security:** Verify sensitive cookies use `Secure`, `HttpOnly`, and `SameSite` attributes. Check `config/session.php` for proper settings.
- **Default Credentials:** Flag default or example credentials left in configuration, seeders, or test fixtures that could be deployed to production.
- **Exposed Endpoints:** Flag `/api/documentation`, `/telescope`, `/horizon`, `/_debugbar` accessible without `auth` middleware. Flag `phpinfo()` or `dd()` / `dump()` left in controllers.

### 6. Cross-Site Scripting (XSS) — OWASP A03

- **Reflected XSS:** Flag user input rendered in responses without escaping. In Blade, verify use of `{{ }}` (escaped) not `{!! !!}` (raw) for user-controlled data. Flag `{!! !!}` used with any data that is not pre-sanitized HTML.
- **Stored XSS:** Check that user-generated content stored in the database is escaped on output. Flag fields that accept HTML/Markdown without sanitization (e.g., `strip_tags()`, HTML Purifier) when rendered in other users' browsers.
- **Vue XSS:** Flag `v-html` directives bound to user-controlled data. Vue's template interpolation (`{{ }}`) auto-escapes, but `v-html` does not. Verify that any `v-html` usage only renders trusted, sanitized content.
- **DOM XSS:** Flag `innerHTML`, `outerHTML`, `document.write()`, `insertAdjacentHTML()` with user-controlled data. Check URL handling — flag `window.location` or `href` set from user input without validation (javascript: protocol injection).
- **Header XSS:** Verify `Content-Type` headers are set correctly for API responses (`application/json`). Flag responses that serve user content with `text/html` content type.

### 7. Insecure Deserialization — OWASP A08

- **PHP Deserialization:** Flag `unserialize()` on user-controlled input — this enables remote code execution. Verify `allowed_classes` parameter is set when deserialization is necessary. Flag `__wakeup()`, `__destruct()`, and `__toString()` methods in classes that could be exploited via deserialization gadget chains.
- **JSON Handling:** Verify `json_decode()` input is validated before use. Flag missing `json_last_error()` checks. Check that decoded JSON structures are validated against expected schemas before accessing nested properties.
- **File Uploads:** Flag file type detection based solely on extension or `Content-Type` header — these are user-controlled. Verify server-side validation of file contents (magic bytes, `finfo_file()`). Flag uploaded files stored in publicly accessible directories with original filenames. Verify upload directory is outside the web root or has execution disabled.

### 8. Using Components with Known Vulnerabilities — OWASP A06

- **Dependency Changes:** If `composer.json`, `composer.lock`, `package.json`, or `package-lock.json` are modified, flag newly added or updated packages that have known CVEs. Check that version constraints do not allow vulnerable ranges.
- **Abandoned Packages:** Flag dependencies that are archived, abandoned, or unmaintained (last release > 2 years ago with known unpatched issues).
- **Sub-Dependency Exposure:** For new dependencies, consider transitive dependencies that may introduce vulnerabilities. Flag packages with large dependency trees that increase attack surface.

### 9. Insufficient Logging & Monitoring — OWASP A09

- **Security Event Logging:** Verify that authentication events (login, logout, failed login, password reset), authorization failures (403 responses), and data access (admin operations) are logged. Flag security-critical operations without audit trails.
- **Log Integrity:** Check that log entries include sufficient context (user ID, IP, timestamp, action, target resource). Flag logs that can be manipulated by user input (log injection via unescaped newlines or control characters).
- **Rate Limit Logging:** Verify that rate limit violations are logged for detection of brute force or scraping attempts.

### 10. Server-Side Request Forgery (SSRF) — OWASP A10

- **URL Validation:** Flag HTTP client calls (`Http::get()`, `file_get_contents()`, `curl_*`) where the URL is user-controlled. Verify URLs are validated against an allowlist of permitted hosts or schemes. Flag requests to internal network addresses (`127.0.0.1`, `10.x`, `172.16.x`, `192.168.x`, `169.254.169.254` metadata endpoint).
- **Redirect Following:** Check that HTTP client redirect following does not allow redirection to internal services. Flag `Http::withOptions(['allow_redirects' => true])` on user-supplied URLs without post-redirect host validation.
- **Webhook URLs:** If the application accepts webhook callback URLs from users, verify they are validated against allowlists and cannot target internal infrastructure.

### 11. Mass Assignment & Data Tampering

- **Model Guarding:** Verify all Eloquent models use `$fillable` (allowlist) rather than `$guarded = []` or `$guarded = ['id']` (insufficient blocklist). Flag controllers that pass `$request->all()` or `$request->input()` directly to `create()` / `update()` / `fill()` without a FormRequest or explicit field selection.
- **Hidden Fields:** Check that `$hidden` is set on models to prevent sensitive attributes (passwords, tokens, internal flags) from appearing in JSON serialization. Verify API Resources do not override hidden protection by explicitly including hidden fields.
- **Read-Only Fields:** Flag endpoints that allow modification of fields that should be immutable (e.g., `created_by`, `role` via user-facing API). Verify FormRequest rules exclude read-only fields or backend ignores them.

### 12. Cryptographic Failures

- **Weak Algorithms:** Flag use of MD5, SHA1, or CRC32 for security purposes (password hashing, token generation, integrity verification). These are acceptable only for non-security checksums.
- **Predictable Randomness:** Flag `rand()`, `mt_rand()`, `array_rand()`, `uniqid()`, or `microtime()` used for security-sensitive values (tokens, keys, nonces). Use `random_bytes()`, `Str::random()`, or `openssl_random_pseudo_bytes()`.
- **Key Management:** Flag encryption keys hardcoded in source code. Verify `APP_KEY` is not committed to version control. Check that encryption uses Laravel's `Crypt` facade (which uses the app key) rather than custom implementations.
- **IV/Nonce Reuse:** If custom encryption is implemented, verify initialization vectors or nonces are unique per encryption operation. Flag static IVs.

## Severity Floor Enforcement

**This is the defining rule of the security-audit skill.** After completing your analysis, review all findings and verify:

- Every finding with a security implication is classified as **🟡 Major** or **🔴 Critical**
- No security finding is classified as 🟢 Minor
- When in doubt between 🟡 Major and 🔴 Critical, consider exploitability: if an attacker can directly exploit the finding to compromise data, authentication, or system integrity → 🔴 Critical

Non-security findings discovered incidentally during the audit (pure style issues, naming conventions, documentation) may remain 🟢 Minor, but these should be rare — the focus of this skill is security.

## Tool Integration

If static analysis or security scanner results are available, classify each finding through the severity system:

- **PHPStan findings** with security implications (type confusion enabling injection, null access in auth flows) → 🟡 Major minimum, 🔴 Critical if directly exploitable
- **PHPStan findings** without security implications → classify per standard severity (these are incidental findings)
- **eslint security rules** (e.g., `no-implied-eval`, `no-script-url`) → 🟡 Major minimum
- **Dependency audit findings** (`npm audit`, `composer audit`) with known exploits → 🔴 Critical; without known exploits → 🟡 Major

Include all findings in the `findings` array. Use `category: "security"` for all security-related findings.

## Large Diff Handling

For merge requests with many changed files:

- **Prioritize security-sensitive files:** Authentication controllers, middleware, authorization policies, input handling, database queries, API endpoints, configuration files, cryptographic operations
- **Trace data flows end-to-end:** Follow user input from HTTP request → validation → business logic → database → response. Flag any point where input is trusted without validation
- **Follow cross-file references:** Check how changed code interacts with authentication, authorization, and data access layers in unchanged files
- **Summarize patterns:** If the same vulnerability pattern appears in multiple files, report it as a single finding with all affected locations listed, rather than duplicating the finding
- **Depth over breadth:** For a security audit, it is better to deeply analyze a few high-risk files than to superficially scan all files. Prioritize files that handle authentication, authorization, user input, or sensitive data

## Output

Produce a JSON object matching the code review schema. The `summary.walkthrough` should describe each changed file with emphasis on security-relevant behavior (what data it handles, what access controls it applies, what external inputs it processes). Each finding must reference a specific `file` and `line`. Use diff suggestions in the `suggestion` field where a concrete fix is possible.

Set `risk_level` based on the highest-severity finding:
- Any 🔴 Critical finding → `"high"`
- Only 🟡 Major findings → `"medium"`
- No security findings → `"low"`

Set `commit_status` to `"failed"` if there are any 🔴 Critical findings. Otherwise set `"success"`.

Set `labels` to include `"ai::reviewed"` and `"ai::security-audit"` always, plus `"ai::risk-high"`, `"ai::risk-medium"`, or `"ai::risk-low"` based on the overall `risk_level`.

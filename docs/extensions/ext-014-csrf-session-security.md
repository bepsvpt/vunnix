## Extension 014: CSRF Session Security

### Trigger
Third-party security audit (2026-02-19) identified High finding #3: CSRF disabled for entire API (`api/*`) while 27 state-changing endpoints use session-based authentication via cookies, enabling cross-site request forgery attacks.

### Scope
What it does:
- Re-enables CSRF validation for session-authenticated API routes
- Creates custom middleware that skips CSRF when request authenticates via API key or task token
- Configures Axios in the Vue SPA to send the `X-XSRF-TOKEN` header from the Laravel-set cookie
- Keeps webhook and API-key-only routes CSRF-exempt

What it does NOT do:
- Does not remove session auth from API routes (SPA depends on it)
- Does not change the session lifetime or cookie policy (D146 unchanged)
- Does not affect external API-key-only integrations

### Architecture Fit
- **Components affected:** Bootstrap middleware config, routes, frontend Axios config, CSRF middleware
- **Extension points used:** Laravel's `VerifyCsrfToken` middleware, Axios interceptors
- **New tables/endpoints/services:** One new middleware (`VerifyCsrfUnlessTokenAuth`)

### New Decisions
- **D203:** Session-authenticated API routes enforce CSRF; API-key and task-token requests bypass CSRF verification — custom `VerifyCsrfUnlessTokenAuth` middleware delegates to Laravel's CSRF check only when the request uses session auth (supersedes D159)
- **D204:** Vue SPA uses Laravel's `XSRF-TOKEN` cookie pattern — Axios reads the cookie and sends `X-XSRF-TOKEN` header automatically via `withXSRFToken: true` in Axios config

### Dependencies
- **Requires:** Nothing — independent of other security extensions
- **Unblocks:** Completes session security model

### Migration Plan

**What breaks:** Session-authenticated requests that don't include a CSRF token will receive 419 responses. The Vue SPA must be updated to send the token. API-key-only requests are unaffected.

**Versioning strategy:**
- API: No version change — CSRF is transport security, not API contract
- DB: No schema change
- External contracts: API key users unaffected; session users (SPA only) must send CSRF token

**Deprecation timeline:**
| Phase | Duration | What Happens |
|---|---|---|
| Deploy | Immediate | CSRF enforced for session auth — SPA update deployed simultaneously |

**Backward compatibility:** The Vue SPA is the only session-authenticated client and is deployed alongside the backend. No external session clients exist. Axios XSRF-TOKEN handling is a configuration change, not a code rewrite.

### Tasks

#### T253: Create VerifyCsrfUnlessTokenAuth middleware
**File(s):** `app/Http/Middleware/VerifyCsrfUnlessTokenAuth.php`
**Action:** Create middleware that:
1. Skips CSRF if request has a valid bearer token (API key auth)
2. Skips CSRF if request matches task token routes
3. Otherwise delegates to Laravel's `VerifyCsrfToken` for standard CSRF verification
The check is: if `$request->bearerToken()` is present, skip CSRF. Session auth requests (no bearer token) must pass CSRF.
**Verification:** Unit test confirms API-key requests bypass CSRF; session requests require CSRF token

#### T254: Update bootstrap/app.php CSRF configuration
**File(s):** `bootstrap/app.php`
**Action:** Remove `'api/*'` from the `validateCsrfTokens(except: [...])` list. Keep `'webhook'` exempt. Register `VerifyCsrfUnlessTokenAuth` in the API middleware stack (replace the default CSRF token validation for API routes).
**Verification:** CSRF validation is active for session-auth API routes

#### T255: Configure Axios XSRF-TOKEN handling in frontend
**File(s):** `resources/js/lib/axios.ts` (or equivalent Axios config file)
**Action:** Ensure Axios config includes:
- `withCredentials: true` (send cookies cross-origin — likely already set)
- `withXSRFToken: true` (read `XSRF-TOKEN` cookie and send as `X-XSRF-TOKEN` header)
- `xsrfCookieName: 'XSRF-TOKEN'` (Laravel default)
- `xsrfHeaderName: 'X-XSRF-TOKEN'` (Laravel default)
**Verification:** Browser dev tools show `X-XSRF-TOKEN` header in API requests

#### T256: Add CSRF regression tests
**File(s):** `tests/Feature/Http/Middleware/CsrfSessionSecurityTest.php`
**Action:** Create test file covering:
- Session-auth POST/PUT/PATCH/DELETE without CSRF token → 419
- Session-auth requests with valid CSRF token → success
- API-key-auth requests without CSRF token → success (CSRF bypassed)
- Webhook requests without CSRF token → success (exempt)
- GET/HEAD requests → success (CSRF not checked)
Test at least 5 representative endpoints: conversation create, admin settings update, role create, API key create, external task trigger.
**Verification:** `php artisan test --filter=CsrfSessionSecurityTest` passes

#### T257: Update decisions index
**File(s):** `docs/spec/decisions-index.md`
**Action:** Add D203, D204 entries. Mark D159 as superseded by D203.
**Verification:** Index is up to date

### Verification
- [ ] Session-auth POST requests without CSRF token return 419
- [ ] Session-auth POST requests with valid CSRF token succeed
- [ ] API-key-auth requests work without CSRF token
- [ ] Webhook requests work without CSRF token
- [ ] Vue SPA functions correctly with CSRF tokens enabled
- [ ] `php artisan test --parallel` passes
- [ ] `npm test` passes
- [ ] `composer analyse` passes

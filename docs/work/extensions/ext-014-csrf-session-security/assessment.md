# Assessment: CSRF Session Security (Security Audit Finding #3)

**Date:** 2026-02-19
**Requested by:** Third-party security audit (2026-02-19)
**Trigger:** Security audit identified CSRF disabled for entire API (`api/*`) while 27 state-changing endpoints use session-based authentication via cookies

## What

The API middleware stack prepends session middleware (`StartSession`, `EncryptCookies`) on all API routes, enabling session-based authentication. However, CSRF token validation is excluded for all `api/*` routes. This means browser-authenticated state-changing endpoints (POST/PUT/PATCH/DELETE) accept session cookies without CSRF protection, enabling cross-site request forgery attacks that could trigger privileged actions from victim sessions.

## Classification

**Tier:** 2
**Rationale:** Requires middleware/routing changes (bootstrap/app.php, routes/api.php) plus frontend changes to send CSRF tokens with requests. Touches 3-5 infrastructure files, not a full architectural rework. The SPA already uses Axios which supports CSRF token headers natively.

**Modifiers:**
- [x] `breaking` — Frontend must send CSRF tokens; external API-key-only clients unaffected
- [ ] `multi-repo` — Single repository
- [ ] `spike-required` — Standard Laravel pattern, well-documented
- [ ] `deprecation` — No capability removed
- [ ] `migration` — No data migration

## Impact Analysis

### Components Affected
| Component | Impact | Files (est.) |
|---|---|---|
| bootstrap/app.php | Remove `api/*` from CSRF exceptions; split route groups | 1 |
| routes/api.php | Organize routes into CSRF-protected (session) vs CSRF-exempt (token-only) groups | 1 |
| Frontend Axios config | Ensure CSRF cookie/header is sent (Laravel Sanctum pattern) | 1 |
| config/session.php | Review SameSite cookie policy | 1 |
| AuthenticateSessionOrApiKey | May need adjustment for CSRF-exempt API-key-only paths | 1 |
| Tests | Add CSRF regression tests for all 27 session-auth endpoints | 3-5 |

### Relevant Decisions
| Decision | Summary | Relationship |
|---|---|---|
| D146 | OAuth session — 7-day lifetime | Constrains: long sessions increase CSRF window |
| D159 | SPA authenticates via session cookies, CSRF excluded for API routes | Superseded by this change |

### Dependencies
- **Requires first:** Nothing — can be implemented independently
- **Unblocks:** Reduces attack surface for all session-authenticated endpoints

## Risk Factors
- Frontend must correctly include CSRF tokens in all requests or SPA breaks
- External API-key-only endpoints must remain CSRF-exempt
- The dual-auth endpoint (`/api/v1/ext/tasks/review`) needs careful handling — CSRF when session, exempt when API key
- SameSite=Lax provides partial protection but is insufficient (same-site POST from forms still works)

## Recommendation
Proceed to planning-extensions. High severity — implement second in remediation sequence after admin authorization scoping.

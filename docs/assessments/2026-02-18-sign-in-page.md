# Assessment: Sign-In Page for Unauthenticated Users

**Date:** 2026-02-18
**Requested by:** Kevin
**Trigger:** UX improvement — unauthenticated users are immediately redirected to GitLab OAuth with no context. A branded sign-in page gives users orientation before leaving the SPA.

## What

Add a `/sign-in` page that unauthenticated users see instead of being auto-redirected to GitLab OAuth. The page shows the Vunnix logo, a tagline, and a "Sign in with GitLab" button. Authenticated users default to `/chat` (unchanged). Logout redirects to `/sign-in` instead of `/auth/redirect`.

## Classification

**Tier:** 2
**Rationale:** New UI capability (sign-in page) within existing architecture. Touches 5-8 files across router, auth store, App.vue, and new page component + tests. No new architectural decisions — uses existing Vue page/route/store patterns. Zero backend changes.

**Modifiers:**
- [ ] `breaking` — No API, DB, or contract changes
- [ ] `multi-repo` — Single repo
- [ ] `spike-required` — Straightforward frontend work
- [ ] `deprecation` — Nothing removed
- [ ] `migration` — No data migration

## Impact Analysis

### Components Affected
| Component | Impact | Files (est.) |
|---|---|---|
| Vue Router | Add `/sign-in` route, update guard to redirect guests there instead of calling `auth.login()`, redirect authenticated users away from `/sign-in` | 1 |
| Auth Store | Change `logout()` redirect from `/auth/redirect` to `/sign-in` | 1 |
| App.vue | Add `v-else-if="auth.isGuest"` branch to render `<router-view>` (sign-in page) without nav | 1 |
| SignInPage.vue | New page component — logo, tagline, GitLab sign-in button | 1 (new) |
| Router tests | Update guard tests: guest → `/sign-in` redirect, authenticated → `/sign-in` → `/chat` | 1 |
| Auth store tests | Update logout redirect assertion | 1 |
| App.vue tests | Add test for guest rendering (sign-in page visible, no nav) | 1 |
| SignInPage tests | New test file for the page component | 1 (new) |

### Relevant Decisions
| Decision | Summary | Relationship |
|---|---|---|
| D7 | GitLab OAuth authentication | Constrains: sign-in button must redirect to OAuth, not present a login form |
| D46 | Full Vue SPA scope | Enables: sign-in page fits as another SPA route |
| D47 | Separate top-level pages (Chat, Dashboard, Admin) | Enables: sign-in page follows same page pattern |
| D159 | SPA authenticates via session cookies | Enables: standard session flow works unchanged |

### Dependencies
- **Requires first:** Nothing — all prerequisites exist (OAuth flow, Vue Router, auth store)
- **Unblocks:** Nothing downstream — this is a standalone UX improvement

## Risk Factors
- **Low risk:** Frontend-only change, no backend modifications
- **Router guard complexity:** Must avoid redirect loops (`/sign-in` → guard → `/sign-in`). Mitigated by explicit route name check in guard.
- **Test coverage:** Existing router/auth/App tests must be updated to reflect new behavior

## Recommendation

Proceed to planning-extensions. Tier 2 warrants a lightweight plan document, though the implementation is straightforward.

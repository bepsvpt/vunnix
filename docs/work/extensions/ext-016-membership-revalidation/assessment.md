# Assessment: Membership Revalidation Activation (Security Audit Finding #6)

**Date:** 2026-02-19
**Requested by:** Third-party security audit (2026-02-19)
**Trigger:** Security audit identified that `RevalidateGitLabMembership` middleware exists, is tested, but is not aliased or applied to any routes — membership sync only happens at login

## What

The `RevalidateGitLabMembership` middleware is fully implemented (checks cache, calls `syncMemberships()` with 15-minute TTL) and has test coverage, but it was never wired into `bootstrap/app.php` middleware aliases or applied to authenticated routes. This means deprovisioned GitLab users retain Vunnix access until their next login or session expiry (up to 7 days per D146). The fix is purely configuration — alias the middleware and apply it to authenticated route groups.

## Classification

**Tier:** 1
**Rationale:** Uses existing, tested extension point. Only 2 files need changes (bootstrap/app.php for alias, routes/api.php or middleware group for application). No new code, no new decisions — the middleware and its caching strategy are already built per D147.

**Modifiers:**
- [ ] `breaking` — No API contract changes
- [ ] `multi-repo` — Single repository
- [ ] `spike-required` — Already implemented and tested
- [ ] `deprecation` — No capability removed
- [ ] `migration` — No data migration

## Impact Analysis

### Components Affected
| Component | Impact | Files (est.) |
|---|---|---|
| bootstrap/app.php | Add middleware alias for RevalidateGitLabMembership | 1 |
| routes/api.php (or middleware group) | Apply revalidation middleware to authenticated routes | 1 |
| Tests | Verify integration (middleware already has unit tests) | 0-1 |

### Relevant Decisions
| Decision | Summary | Relationship |
|---|---|---|
| D147 | Periodic membership re-validation — cached 15 min per request | Fulfilled by this change |
| D146 | OAuth session — 7-day lifetime | Constrains: long sessions make revalidation critical |
| D151 | GitLab OAuth scopes — read_user + read_api | Enables: read_api scope needed for membership API calls |

### Dependencies
- **Requires first:** Nothing — fully independent
- **Unblocks:** Fulfills spec requirement D147

## Risk Factors
- Additional GitLab API call every 15 minutes per user (cached) — negligible load
- If GitLab API is down, `syncMemberships()` returns gracefully (existing memberships preserved)
- Users experiencing brief latency on first request after cache expiry

## Recommendation
Go ahead, no plan needed. Tier 1 — can be implemented directly with 2 file changes. However, given the security audit context, including it in the planning-extensions batch for completeness and verification testing.

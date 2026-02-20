# Assessment: Admin Authorization Scoping (Security Audit Findings #1, #2)

**Date:** 2026-02-19
**Requested by:** Third-party security audit (2026-02-19)
**Trigger:** Security audit identified project-scoped admin permissions being treated as global admin, enabling cross-project privilege escalation

## What

Admin authorization in 10-11 controllers checks if a user has `admin.global_config` (or `admin.roles`) on *any* project they belong to, then grants system-wide access. This means an admin on a low-sensitivity project can access/modify all projects, global settings, audit logs, dead letters, and roles across the entire system. The `User::hasPermission()` method itself is correctly project-scoped — the bug is in how controllers call it via `.contains()` iteration over all user projects.

## Classification

**Tier:** 2
**Rationale:** Consistent pattern change across ~11 controllers (3-10 files), requires introducing a "global admin" vs "project admin" distinction. The `hasPermission()` method is already correct; the fix is in the calling pattern. No schema changes needed — the role/permission tables already support project scoping.

**Modifiers:**
- [x] `breaking` — Users who were project-admin on one project and relying on cross-project access will lose that access
- [ ] `multi-repo` — Single repository
- [ ] `spike-required` — Pattern is well understood
- [ ] `deprecation` — No capabilities removed, just properly scoped
- [ ] `migration` — No data migration needed

## Impact Analysis

### Components Affected
| Component | Impact | Files (est.) |
|---|---|---|
| Admin controllers (global_config) | Change `authorizeAdmin()` to require global admin or target-project admin | 9 |
| AdminRoleController | Scope `authorizeRoleAdmin()` to target project's role context | 1 |
| AdminApiKeyController | Standardize to use `hasPermission()` pattern | 1 |
| User model | Add `isGlobalAdmin()` helper method | 1 |
| RbacSeeder | No change needed (permissions already project-scoped) | 0 |
| Tests | Add cross-project authorization tests | 5-8 |

### Relevant Decisions
| Decision | Summary | Relationship |
|---|---|---|
| D18 | Auth model — RBAC, admin-configurable | Constrains: must maintain per-project RBAC model |
| D29 | Dashboard visibility — project-scoped, cost data admin-only | Constrains: cost dashboard needs true global admin |
| D70 | Dashboard view access — permission-controlled via RBAC | Enables: proper RBAC enforcement per this fix |

### Dependencies
- **Requires first:** Nothing — can be implemented immediately
- **Unblocks:** ext-015 (RBAC Permission Enforcement) benefits from the authorization pattern established here

## Risk Factors
- Breaking change for users who were admin on one project and accessing other projects' data
- Need to decide: is "global admin" = admin on ALL projects, or a separate concept?
- Controllers that serve truly global resources (settings, audit logs) need a clear global-admin check

## Recommendation
Proceed to planning-extensions. Critical severity — implement first in remediation sequence.

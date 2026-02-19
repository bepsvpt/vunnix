# Assessment: RBAC Permission Enforcement (Security Audit Findings #4, #5, #7)

**Date:** 2026-02-19
**Requested by:** Third-party security audit (2026-02-19)
**Trigger:** Security audit identified that RBAC permissions are inconsistently enforced — many endpoints rely on project membership alone instead of checking specific permissions like `chat.access`, `review.view`, and `review.trigger`

## What

The RBAC system defines 7 permissions across 3 role templates, but enforcement is inconsistent: conversation endpoints (except `store()`) only check membership via `ConversationPolicy`, task result viewing only checks membership, external task/review endpoints only check membership, and role assignment doesn't validate target user project membership. A `CheckPermission` middleware exists and is aliased but applied to zero production routes. This means a user with `viewer` role (only `review.view`) can send chat messages, stream AI responses, and trigger code reviews — capabilities intended only for `developer` and `admin` roles.

## Classification

**Tier:** 2
**Rationale:** Adding permission checks to ~10 existing endpoints using the existing `hasPermission()` method. The RBAC infrastructure is already built — this is enforcement hardening, not architectural change. Touches policies, controllers, and middleware wiring.

**Modifiers:**
- [x] `breaking` — Users currently relying on membership-only access without proper permissions will lose access
- [ ] `multi-repo` — Single repository
- [ ] `spike-required` — Pattern is clear from existing implementations
- [ ] `deprecation` — No capability removed
- [ ] `migration` — No data migration

## Impact Analysis

### Components Affected
| Component | Impact | Files (est.) |
|---|---|---|
| ConversationPolicy | Add `chat.access` permission check to all methods | 1 |
| ConversationController | Ensure policy is used consistently + `chat.dispatch_task` for stream | 1 |
| TaskResultViewController | Add `review.view` permission check | 1 |
| ExternalTaskController | Add `review.view` for index/show, `review.trigger` for triggerReview | 1 |
| AdminRoleController::assign | Add target user project membership validation | 1 |
| Dashboard controllers | Add `review.view` checks where missing | 2-3 |
| ActivityController | Clarify and enforce permission requirement | 1 |
| Tests | Add permission-denied test cases for each endpoint | 5-8 |

### Relevant Decisions
| Decision | Summary | Relationship |
|---|---|---|
| D18 | Auth model — RBAC, admin-configurable | Enables: this change fulfills the RBAC model's intent |
| D70 | Dashboard view access — permission-controlled via RBAC | Constrains: dashboard must check `review.view` |
| D29 | Dashboard visibility — project-scoped, cost data admin-only | Constrains: cost data needs `admin.global_config` |

### Dependencies
- **Requires first:** ext-013 (Admin Authorization Scoping) establishes the authorization pattern
- **Unblocks:** Completes the RBAC enforcement model

## Risk Factors
- `viewer` role users who currently interact with chat/tasks will lose access — may need admin communication
- Need to decide: should `ConversationPolicy` inject the project to check permissions, or should the controller handle it?
- The `CheckPermission` middleware exists but is unused — decide whether to use middleware vs inline checks for consistency
- Multi-project conversations complicate permission checking (user needs `chat.access` on primary project)

## Recommendation
Proceed to planning-extensions. High severity — implement third in remediation sequence.

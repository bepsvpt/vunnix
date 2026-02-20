## Extension 013: Admin Authorization Scoping

### Trigger
Third-party security audit (2026-02-19) identified Critical findings #1 and #2: project-scoped admin permissions treated as global admin, enabling cross-project privilege escalation. An admin on Project A can access/modify settings, roles, and resources for Project B.

### Scope
What it does:
- Fixes `authorizeAdmin()` pattern to check permission on the **target** project, not any project
- Introduces `isGlobalAdmin()` helper for truly global resources (settings, audit logs, dead letters)
- Scopes `authorizeRoleAdmin()` to the target project's role context
- Standardizes `AdminApiKeyController` to use `hasPermission()` pattern

What it does NOT do:
- Does not add a separate "super admin" database flag or role (keeps RBAC model pure)
- Does not change the permission schema or role templates
- Does not affect non-admin endpoints

### Architecture Fit
- **Components affected:** 11 admin controllers, User model
- **Extension points used:** Existing `User::hasPermission()` method (correctly project-scoped)
- **New tables/endpoints/services:** None — only changes authorization logic in existing controllers

### New Decisions
- **D201:** Global admin defined as having `admin.global_config` on ALL enabled projects — maintains RBAC model purity without introducing a separate super-admin flag; a user must be admin everywhere to manage global resources
- **D202:** Project-scoped admin endpoints check permission on the target project parameter — `hasPermission('admin.global_config', $targetProject)` replaces the `.contains()` any-project pattern

### Dependencies
- **Requires:** Nothing — first in remediation sequence
- **Unblocks:** ext-015 (RBAC Permission Enforcement) benefits from the established authorization pattern

### Migration Plan

**What breaks:** Users who hold `admin.global_config` on one project but not all projects will lose access to global admin features (settings, audit logs, dead letters, cost dashboard). Users who hold `admin.roles` on one project will no longer be able to manage roles for other projects.

**Versioning strategy:**
- API: No version change — security fix, same endpoints
- DB: No schema change
- External contracts: No contract change — authorization is internal

**Deprecation timeline:**
| Phase | Duration | What Happens |
|---|---|---|
| Deploy | Immediate | Authorization checks enforced — no transition period for security fixes |

**Backward compatibility:** Admins who previously relied on cross-project access must be granted admin roles on the additional projects they need to access. Global admin features require admin on all enabled projects.

### Tasks

#### T245: Add `isGlobalAdmin()` method to User model
**File(s):** `app/Models/User.php`
**Action:** Add method that checks if user has `admin.global_config` permission on ALL enabled projects. Uses `Project::where('enabled', true)->get()` and verifies `hasPermission()` for each. Returns `false` if no enabled projects exist.
**Verification:** Unit test — user with admin on all projects returns `true`; user missing admin on one project returns `false`

#### T246: Refactor project-scoped admin controllers to check target project
**File(s):** `app/Http/Controllers/Api/AdminProjectController.php`, `app/Http/Controllers/Api/AdminProjectConfigController.php`
**Action:** Change `authorizeAdmin()` to accept target `$project` parameter and call `$user->hasPermission('admin.global_config', $project)` directly, instead of iterating all user projects. For `AdminProjectController::index()` (lists all projects), require `isGlobalAdmin()`.
**Verification:** Test that admin on Project A cannot enable/disable/configure Project B

#### T247: Refactor global admin controllers to require global admin
**File(s):** `app/Http/Controllers/Api/AdminSettingsController.php`, `app/Http/Controllers/Api/AuditLogController.php`, `app/Http/Controllers/Api/DeadLetterController.php`, `app/Http/Controllers/Api/DashboardCostController.php`, `app/Http/Controllers/Api/InfrastructureAlertController.php`, `app/Http/Controllers/Api/CostAlertController.php`, `app/Http/Controllers/Api/OverrelianceAlertController.php`
**Action:** Change `authorizeAdmin()` (and inline checks) to use `$user->isGlobalAdmin()` for system-wide resources. These endpoints serve cross-project data and require admin on all enabled projects.
**Verification:** Test that admin on only Project A gets 403 on global settings, audit logs, dead letters, and cost dashboard

#### T248: Scope AdminRoleController to target project
**File(s):** `app/Http/Controllers/Api/AdminRoleController.php`
**Action:** Change `authorizeRoleAdmin()` to check `admin.roles` on the target project (from route parameter or request body `project_id`). For `index()`, scope to projects where user has `admin.roles`. For `store/update/destroy`, check against `$role->project_id`. For `assign/revoke`, check against request `project_id`.
**Verification:** Test that role admin on Project A cannot create/modify/assign roles for Project B

#### T249: Standardize AdminApiKeyController authorization
**File(s):** `app/Http/Controllers/Api/AdminApiKeyController.php`
**Action:** Replace the unorthodox `$user->roles()->whereHas('permissions', ...)` pattern with `$user->isGlobalAdmin()` for consistency. Admin API key management is a global operation.
**Verification:** Existing tests pass; admin on one project only gets 403

#### T250: Refactor PrdTemplateController global authorization
**File(s):** `app/Http/Controllers/Api/PrdTemplateController.php`
**Action:** Change `authorizeAdmin()` in `showGlobal()` and `updateGlobal()` to use `$user->isGlobalAdmin()`. Project-level methods (`showProject`/`updateProject`) already correctly use `authorizeConfigManage($request, $project)` — no change needed.
**Verification:** Test that non-global admin gets 403 on global PRD template endpoints

#### T251: Add cross-project authorization denial tests
**File(s):** `tests/Feature/Http/Controllers/Api/AdminAuthorizationScopingTest.php`
**Action:** Create comprehensive test file covering:
- Project A admin cannot access Project B config/settings
- Project A role admin cannot manage Project B roles
- Project A admin cannot access global settings/audit logs/dead letters
- User with admin on ALL projects can access global resources
- Project-specific admin can manage their own project's config
**Verification:** `php artisan test --filter=AdminAuthorizationScopingTest` passes

#### T252: Update decisions index
**File(s):** `docs/reference/spec/decisions-index.md`
**Action:** Add D201 and D202 entries
**Verification:** Index is up to date with new decisions

### Verification
- [ ] Admin on Project A cannot access/modify Project B settings, roles, or config
- [ ] Admin on Project A cannot access global settings, audit logs, or dead letters
- [ ] Admin on ALL enabled projects can access global resources
- [ ] Role admin on Project A cannot assign/create roles for Project B
- [ ] All existing admin tests still pass (no regression)
- [ ] `php artisan test --parallel` passes
- [ ] `composer analyse` passes

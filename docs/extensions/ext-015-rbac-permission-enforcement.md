## Extension 015: RBAC Permission Enforcement

### Trigger
Third-party security audit (2026-02-19) identified High findings #4, #5, and #7: RBAC permissions inconsistently enforced — conversation endpoints, task result viewing, and external task endpoints rely on project membership alone instead of checking `chat.access`, `review.view`, and `review.trigger` permissions. Role assignment doesn't validate target user project membership.

### Scope
What it does:
- Adds `chat.access` enforcement to ConversationPolicy (all methods)
- Adds `review.view` enforcement to TaskResultViewController and ExternalTaskController index/show
- Adds `review.trigger` enforcement to ExternalTaskController::triggerReview
- Adds target user project membership validation to AdminRoleController::assign
- Adds `review.view` checks to dashboard controllers where missing

What it does NOT do:
- Does not change the permission schema or add new permissions
- Does not modify the CheckPermission middleware (keeps current inline/policy pattern)
- Does not affect webhook-triggered flows (already check permissions in EventRouter)

### Architecture Fit
- **Components affected:** ConversationPolicy, 4 controllers, dashboard controllers
- **Extension points used:** Existing `User::hasPermission()` method, Laravel Policies
- **New tables/endpoints/services:** None

### New Decisions
- **D205:** All authenticated endpoints enforce RBAC permissions consistently — membership gates access to the project, permissions gate access to specific features within the project
- **D206:** ConversationPolicy checks `chat.access` on the conversation's primary project — the user must have both project membership AND `chat.access` permission to interact with conversations

### Dependencies
- **Requires:** ext-013 (Admin Authorization Scoping) — establishes the authorization pattern
- **Unblocks:** Completes RBAC enforcement model across the entire API surface

### Migration Plan

**What breaks:** Users with `viewer` role (only `review.view`) who currently send chat messages or trigger reviews will lose those capabilities. Users with `developer` role are unaffected (they have `chat.access`, `chat.dispatch_task`, `review.view`, `review.trigger`). Users with `admin` role are unaffected (full permissions).

**Versioning strategy:**
- API: No version change — authorization is internal
- DB: No schema change
- External contracts: External API users need proper permissions (not just membership)

**Deprecation timeline:**
| Phase | Duration | What Happens |
|---|---|---|
| Deploy | Immediate | Permission checks enforced — security fix |

**Backward compatibility:** Review existing role assignments to ensure users have the permissions they need. The `developer` role template already includes all common permissions. Only `viewer` users are affected.

### Tasks

#### T258: Update ConversationPolicy to enforce chat.access
**File(s):** `app/Policies/ConversationPolicy.php`
**Action:** In each policy method (`view`, `addProject`, `sendMessage`, `stream`, `archive`), add a `chat.access` permission check on the conversation's primary project in addition to the existing membership check. The user must have membership AND `chat.access`. Inject the permission check: `$user->hasPermission('chat.access', $conversation->project)`.
**Verification:** Test that viewer-role user gets 403 on conversation view/send/stream/archive

#### T259: Add chat.access check to ConversationController::index
**File(s):** `app/Http/Controllers/Api/ConversationController.php`
**Action:** Filter conversation listing to only include conversations where the user has `chat.access` on the primary project. Modify the query scope or add post-query filtering.
**Verification:** Viewer-role user sees empty conversation list for projects where they lack `chat.access`

#### T260: Add review.view check to TaskResultViewController
**File(s):** `app/Http/Controllers/Api/TaskResultViewController.php`
**Action:** After the existing membership check, add `$user->hasPermission('review.view', $task->project)`. Return 403 if permission missing.
**Verification:** User without `review.view` gets 403 on task result view

#### T261: Add review.view and review.trigger to ExternalTaskController
**File(s):** `app/Http/Controllers/Api/ExternalTaskController.php`
**Action:**
- `index()` and `show()`: Add `review.view` check on the task's project
- `triggerReview()`: Add `review.trigger` check on the target project (matching webhook path behavior in `WebhookController::handleMergeRequestNote`)
**Verification:** User without `review.trigger` gets 403 on `POST /api/v1/ext/tasks/review`

#### T262: Add target user membership validation to AdminRoleController::assign
**File(s):** `app/Http/Controllers/Api/AdminRoleController.php`
**Action:** In the `assign()` method, after finding the target user, validate that they are a member of the target project: `$user->projects()->where('projects.id', $project->id)->exists()`. Return 422 if not a member.
**Verification:** Assigning a role to a non-member returns 422 error

#### T263: Add review.view to dashboard controllers
**File(s):** `app/Http/Controllers/Api/DashboardOverviewController.php`, `app/Http/Controllers/Api/DashboardQualityController.php`
**Action:** Add permission check requiring `review.view` on at least one of the user's projects before serving dashboard data. Filter data to only include projects where user has `review.view`.
**Verification:** Viewer-role user can see dashboard; user without `review.view` on any project gets appropriate response

#### T264: Add permission-denied tests for each endpoint
**File(s):** `tests/Feature/Http/Controllers/Api/RbacPermissionEnforcementTest.php`
**Action:** Create comprehensive test file covering:
- Viewer cannot send conversation messages (lacks `chat.access`)
- Viewer cannot stream AI responses (lacks `chat.dispatch_task`)
- Developer can access conversations (has `chat.access`)
- User without `review.view` cannot view task results
- User without `review.trigger` cannot trigger external reviews
- Role assignment to non-project-member returns 422
- User without `review.view` cannot access dashboard data
**Verification:** `php artisan test --filter=RbacPermissionEnforcementTest` passes

#### T265: Update decisions index
**File(s):** `docs/spec/decisions-index.md`
**Action:** Add D205 and D206 entries
**Verification:** Index is up to date

### Verification
- [ ] Viewer-role user cannot send/stream/view conversations (403)
- [ ] Developer-role user can send/stream/view conversations
- [ ] User without `review.view` cannot view task results (403)
- [ ] User without `review.trigger` cannot trigger external reviews (403)
- [ ] Role assignment to non-project-member returns 422
- [ ] Dashboard data filtered by `review.view` permission
- [ ] All existing tests pass (no regression)
- [ ] `php artisan test --parallel` passes
- [ ] `composer analyse` passes

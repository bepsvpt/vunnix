## Extension 016: Membership Revalidation Activation

### Trigger
Third-party security audit (2026-02-19) identified High finding #6: `RevalidateGitLabMembership` middleware exists, is tested, but is not aliased or applied to any routes. Deprovisioned GitLab users retain access until next login (up to 7 days per D146).

### Scope
What it does:
- Registers `RevalidateGitLabMembership` middleware alias in bootstrap/app.php
- Applies the middleware to authenticated API route groups
- Fulfills spec requirement D147 (periodic membership re-validation, cached 15 min)

What it does NOT do:
- Does not create new code (middleware already fully implemented and tested)
- Does not add background sync jobs (middleware handles on-request revalidation)
- Does not change the 15-minute cache TTL (already implemented per D147)

### Architecture Fit
- **Components affected:** bootstrap/app.php (alias), routes/api.php or middleware group (application)
- **Extension points used:** Existing `RevalidateGitLabMembership` middleware
- **New tables/endpoints/services:** None

### New Decisions
No new decisions — D147 already fully specifies this behavior. This task activates existing implementation.

### Dependencies
- **Requires:** Nothing — fully independent
- **Unblocks:** Fulfills D147 spec requirement; reduces access revocation window from 7 days to 15 minutes

### Tasks

#### T266: Register RevalidateGitLabMembership middleware alias
**File(s):** `bootstrap/app.php`
**Action:** Add `'revalidate' => \App\Http\Middleware\RevalidateGitLabMembership::class` to the middleware alias array (alongside existing aliases like `permission`, `webhook.verify`, etc.)
**Verification:** Middleware is registered and resolvable by alias

#### T267: Apply revalidation middleware to authenticated routes
**File(s):** `bootstrap/app.php` or `routes/api.php`
**Action:** Apply the `revalidate` middleware to the `auth` middleware group for API routes. This ensures every authenticated request triggers revalidation (with 15-minute cache). Apply after `auth` middleware so the user is resolved before membership check.
**Verification:** Authenticated API request triggers membership sync when cache is expired; second request within 15 minutes uses cache

#### T268: Verify integration test coverage
**File(s):** `tests/Feature/Middleware/RevalidateGitLabMembershipTest.php`
**Action:** Verify existing tests cover the wired scenario. Add integration test if needed: make authenticated API call, verify GitLab API is called for membership sync, verify cache prevents repeat calls.
**Verification:** `php artisan test --filter=RevalidateGitLabMembership` passes

### Verification
- [ ] Middleware alias registered in bootstrap/app.php
- [ ] Authenticated API requests trigger membership revalidation
- [ ] Cache prevents repeat GitLab API calls within 15 minutes
- [ ] Unauthenticated requests unaffected
- [ ] Existing tests pass (no regression)
- [ ] `php artisan test --parallel` passes

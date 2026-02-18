## Extension 011: Sign-In Page for Unauthenticated Users

### Trigger
UX improvement — unauthenticated users are immediately redirected to GitLab OAuth (`/auth/redirect`) with no context or branding. A dedicated sign-in page provides orientation before leaving the SPA.

### Scope
What it does:
- Adds a `/sign-in` route with a branded page (Vunnix logo, tagline, "Sign in with GitLab" button)
- Redirects unauthenticated users to `/sign-in` instead of auto-redirecting to GitLab OAuth
- Redirects authenticated users away from `/sign-in` to `/chat`
- Changes logout to redirect to `/sign-in` instead of `/auth/redirect`

What it does NOT do:
- No backend changes (OAuth flow, controllers, routes remain unchanged)
- No new API endpoints
- No public landing page or marketing content
- No alternative authentication methods (GitLab OAuth remains the only option per D7)

### Architecture Fit
- **Components affected:** Vue Router guard, Auth Store, App.vue, new SignInPage.vue
- **Extension points used:** Existing Vue Router route registration, existing Pinia auth store
- **New tables/endpoints/services:** None

### New Decisions

- **D194:** Unauthenticated users see a branded `/sign-in` page instead of being auto-redirected to GitLab OAuth. Logout redirects to `/sign-in`. — Gives users context before OAuth handoff; keeps the SPA in control of the pre-auth experience.

### Dependencies
- **Requires:** Nothing — all prerequisites exist (OAuth flow, Vue Router, auth store, logo asset at `public/vunnix-ut.svg`)
- **Unblocks:** Nothing downstream — standalone UX improvement

### Tasks

#### T208: Create SignInPage.vue component
**File(s):** `resources/js/pages/SignInPage.vue`
**Action:** Create a new page component with:
- Dark background (`bg-zinc-950`) with subtle ambient gradient orbs using the logo's green→blue→purple palette
- Centered layout: Vunnix logo (`/vunnix-ut.svg`), tagline ("AI-powered development for self-hosted GitLab"), "Sign in with GitLab" button with GitLab tanuki SVG icon and brand orange (`#FC6D26`)
- Button calls `auth.login()` (existing method that redirects to `/auth/redirect`)
- Uses existing design tokens (`--radius-button`, etc.)
**Verification:** Component renders without errors; button click calls `auth.login()`

#### T209: Add /sign-in route and update router guard
**File(s):** `resources/js/router/index.ts`
**Action:**
- Import `SignInPage` and add route: `{ path: '/sign-in', name: 'sign-in', component: SignInPage }`
- Update `beforeEach` guard: guests navigating to `sign-in` route are allowed through; guests navigating anywhere else are redirected to `{ name: 'sign-in' }`; authenticated users navigating to `sign-in` are redirected to `{ name: 'chat' }`
- Remove the `auth.login()` call from the guard (sign-in page handles that now)
**Verification:** Guest → `/dashboard` → redirected to `/sign-in`; guest → `/sign-in` → page renders; authenticated → `/sign-in` → redirected to `/chat`

#### T210: Update auth store logout redirect
**File(s):** `resources/js/stores/auth.ts`
**Action:** Change `logout()` method to redirect to `/sign-in` instead of `/auth/redirect`
**Verification:** After logout, `window.location.href` is set to `/sign-in`

#### T211: Update App.vue guest rendering
**File(s):** `resources/js/App.vue`
**Action:** Add `v-else-if="auth.isGuest"` branch between the authenticated template and the loading fallback. This branch renders `<router-view />` without `<AppNavigation />`, allowing the sign-in page to display full-screen.
**Verification:** Guest state shows router-view (sign-in page) without navigation bar; authenticated state unchanged; loading state unchanged

#### T212: Write tests for SignInPage
**File(s):** `resources/js/pages/SignInPage.test.ts`
**Action:** Create tests covering: renders logo image, renders tagline text, renders sign-in button, button click calls `auth.login()`
**Verification:** `npm test -- resources/js/pages/SignInPage.test.ts` passes

#### T213: Update router tests
**File(s):** `resources/js/router/index.test.ts`
**Action:** Update existing tests and add new ones:
- Update route list assertion to include `sign-in`
- Add: guest redirected to `/sign-in` (not `auth.login()`)
- Add: authenticated user at `/sign-in` redirected to `/chat`
- Add: guest at `/sign-in` stays on `/sign-in`
- Update existing "calls login and aborts navigation" test → now expects redirect to sign-in
- Update existing "redirects to OAuth login when user is explicitly a guest" → now expects redirect to sign-in
**Verification:** `npm test -- resources/js/router/index.test.ts` passes

#### T214: Update auth store tests
**File(s):** `resources/js/stores/auth.test.ts`
**Action:** Update logout tests: change expected `window.location.href` from `/auth/redirect` to `/sign-in`
**Verification:** `npm test -- resources/js/stores/auth.test.ts` passes

#### T215: Update App.vue tests
**File(s):** `resources/js/App.test.ts`
**Action:**
- Add `SignInPage` to the test router routes (with `/sign-in` route)
- Add test: when auth is guest, renders router-view without nav (sign-in page content visible)
- Existing "shows loading state" test unchanged (user=null is still loading)
**Verification:** `npm test -- resources/js/App.test.ts` passes

### Verification
- [ ] `npm run typecheck` passes
- [ ] `npm run lint` passes
- [ ] `npm test` passes (all JS tests)
- [ ] Unauthenticated user visiting any route sees the sign-in page
- [ ] Clicking "Sign in with GitLab" redirects to GitLab OAuth
- [ ] Authenticated user visiting `/sign-in` is redirected to `/chat`
- [ ] Logout redirects to `/sign-in`
- [ ] Loading spinner still shows during initial auth check (user=null)

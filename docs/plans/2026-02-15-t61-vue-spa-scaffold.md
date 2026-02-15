# T61: Vue SPA Scaffold Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Scaffold the Vue 3 SPA with routing, state management, responsive layout, and auth guard — the foundation for all Chat Frontend tasks (T62–T72) and beyond.

**Architecture:** Single-page Vue 3 app mounted on a Blade shell template. Vue Router (history mode) handles client-side navigation between three top-level pages (Chat, Dashboard, Admin). Pinia provides state management. FrankenPHP serves the same shell for all non-API, non-asset routes via a Laravel catch-all. Desktop-first responsive layout with Tailwind CSS breakpoints.

**Tech Stack:** Vue 3 (Composition API, `<script setup>`), Vite 7, Vue Router 4, Pinia 2, Tailwind CSS 4, Vitest + @vue/test-utils + jsdom

---

### Task 1: Install npm dependencies

**Files:**
- Modify: `package.json`

**Step 1: Install Vue 3 ecosystem packages**

```bash
npm install vue@3 vue-router@4 pinia@2
```

**Step 2: Install Vite Vue plugin and testing dependencies**

```bash
npm install -D @vitejs/plugin-vue vitest @vue/test-utils jsdom
```

**Step 3: Install markdown rendering packages (used in T65, installed now)**

```bash
npm install markdown-it shiki @shikijs/markdown-it
```

**Step 4: Verify package.json has all dependencies**

Run: `cat package.json`
Expected: `vue`, `vue-router`, `pinia` in `dependencies`; `@vitejs/plugin-vue`, `vitest`, `@vue/test-utils`, `jsdom` in `devDependencies`; `markdown-it`, `shiki`, `@shikijs/markdown-it` in `dependencies`

**Step 5: Commit**

```bash
git add package.json package-lock.json
git commit --no-gpg-sign -m "T61.1: Install Vue 3, Router, Pinia, Vitest, and markdown dependencies"
```

---

### Task 2: Configure Vite for Vue and add Vitest config

**Files:**
- Modify: `vite.config.js`
- Create: `vitest.config.js`

**Step 1: Update vite.config.js — add Vue plugin**

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
```

Key config: `transformAssetUrls` with `base: null` and `includeAbsolute: false` tells Vue's template compiler not to rewrite asset URLs — Laravel Vite plugin handles asset URLs instead.

**Step 2: Create vitest.config.js**

```js
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.js'],
        globals: true,
    },
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
});
```

**Step 3: Add test script to package.json**

Add to `scripts`:
```json
"test": "vitest run",
"test:watch": "vitest"
```

**Step 4: Run Vite build to verify config**

Run: `npx vite build`
Expected: Build succeeds (may warn about no Vue files yet, that's fine)

**Step 5: Commit**

```bash
git add vite.config.js vitest.config.js package.json
git commit --no-gpg-sign -m "T61.2: Configure Vite Vue plugin and Vitest test runner"
```

---

### Task 3: Create the SPA Blade shell and Laravel catch-all route

**Files:**
- Create: `resources/views/app.blade.php`
- Modify: `routes/web.php`

**Step 1: Create the SPA Blade template**

`resources/views/app.blade.php`:
```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Vunnix') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 antialiased">
    <div id="app"></div>
</body>
</html>
```

**Step 2: Add catch-all route to routes/web.php**

Add at the **end** of web.php (must be last route):
```php
// SPA catch-all — serves Vue app for all non-API, non-asset routes
// Must be the LAST route defined
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api|health|auth|webhook|up).*$');
```

The regex negative lookahead `^(?!api|health|auth|webhook|up).*$` ensures Laravel-handled routes (OAuth, webhook, health) are not caught by the SPA fallback.

**Step 3: Verify the catch-all doesn't break existing routes**

Run: `php artisan route:list`
Expected: All existing routes still listed; catch-all appears as `GET /{any}` at the bottom.

Run: `php artisan test --filter="HealthCheckTest|AuthControllerTest|WebhookControllerTest"`
Expected: All pass (catch-all doesn't interfere).

**Step 4: Commit**

```bash
git add resources/views/app.blade.php routes/web.php
git commit --no-gpg-sign -m "T61.3: Add SPA Blade shell and catch-all route for Vue Router history mode"
```

---

### Task 4: Create Vue app entry point, root component, and router

**Files:**
- Modify: `resources/js/app.js`
- Create: `resources/js/App.vue`
- Create: `resources/js/router/index.js`
- Create: `resources/js/pages/ChatPage.vue`
- Create: `resources/js/pages/DashboardPage.vue`
- Create: `resources/js/pages/AdminPage.vue`

**Step 1: Create placeholder page components**

`resources/js/pages/ChatPage.vue`:
```vue
<script setup>
</script>

<template>
  <div>
    <h1 class="text-2xl font-semibold">Chat</h1>
    <p class="mt-2 text-zinc-500 dark:text-zinc-400">Conversational AI interface — coming in T63.</p>
  </div>
</template>
```

`resources/js/pages/DashboardPage.vue`:
```vue
<script setup>
</script>

<template>
  <div>
    <h1 class="text-2xl font-semibold">Dashboard</h1>
    <p class="mt-2 text-zinc-500 dark:text-zinc-400">Activity feed and metrics — coming in M4.</p>
  </div>
</template>
```

`resources/js/pages/AdminPage.vue`:
```vue
<script setup>
</script>

<template>
  <div>
    <h1 class="text-2xl font-semibold">Admin</h1>
    <p class="mt-2 text-zinc-500 dark:text-zinc-400">Project management and settings — coming in M5.</p>
  </div>
</template>
```

**Step 2: Create the router**

`resources/js/router/index.js`:
```js
import { createRouter, createWebHistory } from 'vue-router';
import ChatPage from '@/pages/ChatPage.vue';
import DashboardPage from '@/pages/DashboardPage.vue';
import AdminPage from '@/pages/AdminPage.vue';

const routes = [
    { path: '/', redirect: '/chat' },
    { path: '/chat', name: 'chat', component: ChatPage },
    { path: '/dashboard', name: 'dashboard', component: DashboardPage },
    { path: '/admin', name: 'admin', component: AdminPage },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

// Auth guard — redirects unauthenticated users to GitLab OAuth
// The auth store's `isAuthenticated` starts as `null` (unknown) until checked.
// T62 will implement the full auth check; for now, skip guard when auth is unknown.
router.beforeEach((to, from, next) => {
    // Placeholder — T62 will wire this to the auth Pinia store
    next();
});

export default router;
```

**Step 3: Create the root App.vue with layout**

`resources/js/App.vue`:
```vue
<script setup>
import AppNavigation from '@/components/AppNavigation.vue';
</script>

<template>
  <div class="min-h-screen flex flex-col">
    <AppNavigation />
    <main class="flex-1 p-4 lg:p-8">
      <router-view />
    </main>
  </div>
</template>
```

**Step 4: Update app.js to mount the Vue app**

`resources/js/app.js`:
```js
import './bootstrap';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';

const app = createApp(App);
app.use(createPinia());
app.use(router);
app.mount('#app');
```

**Step 5: Verify Vite builds**

Run: `npx vite build`
Expected: Build succeeds with Vue components compiled.

**Step 6: Commit**

```bash
git add resources/js/app.js resources/js/App.vue resources/js/router/index.js resources/js/pages/
git commit --no-gpg-sign -m "T61.4: Create Vue app entry point, router with 3 pages, and root component"
```

---

### Task 5: Create responsive navigation component

**Files:**
- Create: `resources/js/components/AppNavigation.vue`

**Step 1: Create responsive top navigation**

`resources/js/components/AppNavigation.vue`:
```vue
<script setup>
import { ref } from 'vue';
import { RouterLink } from 'vue-router';

const mobileMenuOpen = ref(false);

function toggleMenu() {
    mobileMenuOpen.value = !mobileMenuOpen.value;
}

function closeMenu() {
    mobileMenuOpen.value = false;
}

const navLinks = [
    { to: '/chat', label: 'Chat', icon: '💬' },
    { to: '/dashboard', label: 'Dashboard', icon: '📊' },
    { to: '/admin', label: 'Admin', icon: '⚙️' },
];
</script>

<template>
  <nav class="bg-white dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-800">
    <div class="max-w-7xl mx-auto px-4 lg:px-8">
      <div class="flex items-center justify-between h-16">
        <!-- Logo / Brand -->
        <div class="flex items-center gap-2">
          <span class="text-lg font-semibold">Vunnix</span>
        </div>

        <!-- Desktop nav links -->
        <div class="hidden md:flex items-center gap-1">
          <RouterLink
            v-for="link in navLinks"
            :key="link.to"
            :to="link.to"
            class="px-3 py-2 rounded-md text-sm font-medium text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
            active-class="!text-zinc-900 dark:!text-zinc-100 bg-zinc-100 dark:bg-zinc-800"
            @click="closeMenu"
          >
            {{ link.label }}
          </RouterLink>
        </div>

        <!-- Mobile hamburger button -->
        <button
          class="md:hidden p-2 rounded-md text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800"
          :aria-expanded="mobileMenuOpen"
          aria-label="Toggle navigation menu"
          @click="toggleMenu"
        >
          <svg v-if="!mobileMenuOpen" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
          <svg v-else class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Mobile menu panel -->
    <div v-if="mobileMenuOpen" class="md:hidden border-t border-zinc-200 dark:border-zinc-800">
      <div class="px-2 py-2 space-y-1">
        <RouterLink
          v-for="link in navLinks"
          :key="link.to"
          :to="link.to"
          class="block px-3 py-2 rounded-md text-base font-medium text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
          active-class="!text-zinc-900 dark:!text-zinc-100 bg-zinc-100 dark:bg-zinc-800"
          @click="closeMenu"
        >
          {{ link.label }}
        </RouterLink>
      </div>
    </div>
  </nav>
</template>
```

**Step 2: Verify build**

Run: `npx vite build`
Expected: Build succeeds.

**Step 3: Commit**

```bash
git add resources/js/components/AppNavigation.vue
git commit --no-gpg-sign -m "T61.5: Add responsive navigation component with mobile hamburger menu"
```

---

### Task 6: Create placeholder auth Pinia store

**Files:**
- Create: `resources/js/stores/auth.js`

**Step 1: Create the auth store skeleton**

`resources/js/stores/auth.js`:
```js
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

export const useAuthStore = defineStore('auth', () => {
    // null = unknown (not yet checked), object = authenticated, false = not authenticated
    const user = ref(null);
    const loading = ref(false);

    const isAuthenticated = computed(() => user.value !== null && user.value !== false);
    const isGuest = computed(() => user.value === false);
    const isLoading = computed(() => loading.value);

    // Placeholder — T62 will implement the full check against /api/v1/user
    async function fetchUser() {
        // T62: implement API call
    }

    function setUser(userData) {
        user.value = userData;
    }

    function clearUser() {
        user.value = false;
    }

    return {
        user,
        loading,
        isAuthenticated,
        isGuest,
        isLoading,
        fetchUser,
        setUser,
        clearUser,
    };
});
```

**Step 2: Commit**

```bash
git add resources/js/stores/auth.js
git commit --no-gpg-sign -m "T61.6: Add placeholder auth Pinia store for T62 to implement"
```

---

### Task 7: Update Tailwind CSS sources for Vue files

**Files:**
- Modify: `resources/css/app.css`

**Step 1: Add Vue file scanning to Tailwind**

Update `resources/css/app.css` to add a `@source` directive for `.vue` files:

```css
@import 'tailwindcss';

@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';
@source '../**/*.blade.php';
@source '../**/*.js';
@source '../**/*.vue';

@theme {
    --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji',
        'Segoe UI Symbol', 'Noto Color Emoji';
}
```

**Step 2: Verify Tailwind picks up Vue classes**

Run: `npx vite build`
Expected: Build succeeds, CSS output includes classes from Vue components.

**Step 3: Commit**

```bash
git add resources/css/app.css
git commit --no-gpg-sign -m "T61.7: Add Vue file source scanning to Tailwind CSS config"
```

---

### Task 8: Write Vitest tests for router and app mounting

**Files:**
- Create: `resources/js/router/index.test.js`
- Create: `resources/js/App.test.js`

**Step 1: Write router tests**

`resources/js/router/index.test.js`:
```js
import { describe, it, expect } from 'vitest';
import router from './index.js';

describe('Router', () => {
    it('uses history mode (no hash)', () => {
        // createWebHistory sets mode to 'history' internally
        expect(router.options.history.base).toBe('/');
    });

    it('has three named routes: chat, dashboard, admin', () => {
        const routeNames = router.getRoutes().map(r => r.name).filter(Boolean);
        expect(routeNames).toContain('chat');
        expect(routeNames).toContain('dashboard');
        expect(routeNames).toContain('admin');
    });

    it('redirects / to /chat', () => {
        const rootRoute = router.getRoutes().find(r => r.path === '/');
        // The redirect route doesn't appear in getRoutes for Vue Router 4;
        // instead check via options
        const rootOption = router.options.routes.find(r => r.path === '/');
        expect(rootOption.redirect).toBe('/chat');
    });
});
```

**Step 2: Write App mount test**

`resources/js/App.test.js`:
```js
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import App from './App.vue';
import ChatPage from './pages/ChatPage.vue';
import DashboardPage from './pages/DashboardPage.vue';
import AdminPage from './pages/AdminPage.vue';

function createTestRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', redirect: '/chat' },
            { path: '/chat', name: 'chat', component: ChatPage },
            { path: '/dashboard', name: 'dashboard', component: DashboardPage },
            { path: '/admin', name: 'admin', component: AdminPage },
        ],
    });
}

describe('App', () => {
    it('mounts and renders navigation', async () => {
        const router = createTestRouter();
        router.push('/chat');
        await router.isReady();

        const wrapper = mount(App, {
            global: {
                plugins: [createPinia(), router],
            },
        });

        expect(wrapper.find('nav').exists()).toBe(true);
        expect(wrapper.text()).toContain('Vunnix');
    });

    it('renders ChatPage at /chat', async () => {
        const router = createTestRouter();
        router.push('/chat');
        await router.isReady();

        const wrapper = mount(App, {
            global: {
                plugins: [createPinia(), router],
            },
        });

        expect(wrapper.text()).toContain('Chat');
    });

    it('renders DashboardPage at /dashboard', async () => {
        const router = createTestRouter();
        router.push('/dashboard');
        await router.isReady();

        const wrapper = mount(App, {
            global: {
                plugins: [createPinia(), router],
            },
        });

        expect(wrapper.text()).toContain('Dashboard');
    });

    it('renders AdminPage at /admin', async () => {
        const router = createTestRouter();
        router.push('/admin');
        await router.isReady();

        const wrapper = mount(App, {
            global: {
                plugins: [createPinia(), router],
            },
        });

        expect(wrapper.text()).toContain('Admin');
    });
});
```

**Step 3: Run tests**

Run: `npx vitest run`
Expected: All tests pass.

**Step 4: Commit**

```bash
git add resources/js/router/index.test.js resources/js/App.test.js
git commit --no-gpg-sign -m "T61.8: Add Vitest tests for router config and app component mounting"
```

---

### Task 9: Write navigation component tests

**Files:**
- Create: `resources/js/components/AppNavigation.test.js`

**Step 1: Write tests**

`resources/js/components/AppNavigation.test.js`:
```js
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import AppNavigation from './AppNavigation.vue';

function createTestRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/chat', name: 'chat', component: { template: '<div />' } },
            { path: '/dashboard', name: 'dashboard', component: { template: '<div />' } },
            { path: '/admin', name: 'admin', component: { template: '<div />' } },
        ],
    });
}

describe('AppNavigation', () => {
    it('renders brand name', async () => {
        const router = createTestRouter();
        router.push('/chat');
        await router.isReady();

        const wrapper = mount(AppNavigation, {
            global: { plugins: [router] },
        });

        expect(wrapper.text()).toContain('Vunnix');
    });

    it('renders all three nav links', async () => {
        const router = createTestRouter();
        router.push('/chat');
        await router.isReady();

        const wrapper = mount(AppNavigation, {
            global: { plugins: [router] },
        });

        const links = wrapper.findAll('a');
        const labels = links.map(l => l.text());
        expect(labels).toContain('Chat');
        expect(labels).toContain('Dashboard');
        expect(labels).toContain('Admin');
    });

    it('mobile menu is hidden by default', async () => {
        const router = createTestRouter();
        router.push('/chat');
        await router.isReady();

        const wrapper = mount(AppNavigation, {
            global: { plugins: [router] },
        });

        // The mobile panel uses v-if so it should not exist in DOM
        const mobilePanel = wrapper.find('[class*="md:hidden border-t"]');
        expect(mobilePanel.exists()).toBe(false);
    });

    it('toggles mobile menu on hamburger click', async () => {
        const router = createTestRouter();
        router.push('/chat');
        await router.isReady();

        const wrapper = mount(AppNavigation, {
            global: { plugins: [router] },
        });

        const button = wrapper.find('button[aria-label="Toggle navigation menu"]');
        await button.trigger('click');

        // Mobile panel should now be visible
        expect(wrapper.findAll('a').length).toBeGreaterThan(3); // desktop + mobile links
    });

    it('has hamburger button hidden on desktop (md:hidden class)', async () => {
        const router = createTestRouter();
        router.push('/chat');
        await router.isReady();

        const wrapper = mount(AppNavigation, {
            global: { plugins: [router] },
        });

        const button = wrapper.find('button[aria-label="Toggle navigation menu"]');
        expect(button.classes()).toContain('md:hidden');
    });
});
```

**Step 2: Run tests**

Run: `npx vitest run`
Expected: All tests pass.

**Step 3: Commit**

```bash
git add resources/js/components/AppNavigation.test.js
git commit --no-gpg-sign -m "T61.9: Add navigation component tests for responsive behavior"
```

---

### Task 10: Add T61 verification checks to verify_m3.py

**Files:**
- Modify: `verify/verify_m3.py`

**Step 1: Add T61 structural checks**

Add before the `# ─── Summary ───` line in `verify/verify_m3.py`:

```python
# ============================================================
#  T61: Vue SPA Scaffold
# ============================================================
section("T61: Vue SPA Scaffold")

# Vue 3 + Vite + Pinia + Vue Router installed
checker.check(
    "Vue 3 is installed in package.json",
    file_contains("package.json", '"vue"'),
)
checker.check(
    "Vue Router is installed",
    file_contains("package.json", '"vue-router"'),
)
checker.check(
    "Pinia is installed",
    file_contains("package.json", '"pinia"'),
)
checker.check(
    "Vitest is installed",
    file_contains("package.json", '"vitest"'),
)
checker.check(
    "@vue/test-utils is installed",
    file_contains("package.json", '"@vue/test-utils"'),
)
checker.check(
    "@vitejs/plugin-vue is installed",
    file_contains("package.json", '"@vitejs/plugin-vue"'),
)
checker.check(
    "markdown-it is installed",
    file_contains("package.json", '"markdown-it"'),
)

# Vite config includes Vue plugin
checker.check(
    "Vite config imports Vue plugin",
    file_contains("vite.config.js", "@vitejs/plugin-vue"),
)
checker.check(
    "Vite config uses Vue plugin",
    file_contains("vite.config.js", "vue("),
)

# Vitest config exists
checker.check(
    "Vitest config file exists",
    file_exists("vitest.config.js"),
)
checker.check(
    "Vitest uses jsdom environment",
    file_contains("vitest.config.js", "jsdom"),
)

# SPA Blade template
checker.check(
    "SPA Blade template exists",
    file_exists("resources/views/app.blade.php"),
)
checker.check(
    "SPA template has Vue mount point",
    file_contains("resources/views/app.blade.php", 'id="app"'),
)
checker.check(
    "SPA template uses Vite directive",
    file_contains("resources/views/app.blade.php", "@vite"),
)

# Catch-all route for history mode
checker.check(
    "Web routes include SPA catch-all",
    file_contains("routes/web.php", "{any}"),
)
checker.check(
    "Catch-all returns app view",
    file_contains("routes/web.php", "view('app')"),
)

# Vue app entry point
checker.check(
    "App entry point mounts Vue app",
    file_contains("resources/js/app.js", "createApp"),
)
checker.check(
    "App entry point uses Pinia",
    file_contains("resources/js/app.js", "createPinia"),
)
checker.check(
    "App entry point uses router",
    file_contains("resources/js/app.js", "app.use(router)"),
)

# Root App.vue component
checker.check(
    "Root App.vue exists",
    file_exists("resources/js/App.vue"),
)
checker.check(
    "App.vue includes router-view",
    file_contains("resources/js/App.vue", "router-view"),
)
checker.check(
    "App.vue imports AppNavigation",
    file_contains("resources/js/App.vue", "AppNavigation"),
)

# Router with history mode
checker.check(
    "Router config exists",
    file_exists("resources/js/router/index.js"),
)
checker.check(
    "Router uses createWebHistory (history mode)",
    file_contains("resources/js/router/index.js", "createWebHistory"),
)
checker.check(
    "Router has chat route",
    file_contains("resources/js/router/index.js", "'/chat'"),
)
checker.check(
    "Router has dashboard route",
    file_contains("resources/js/router/index.js", "'/dashboard'"),
)
checker.check(
    "Router has admin route",
    file_contains("resources/js/router/index.js", "'/admin'"),
)
checker.check(
    "Root path redirects to /chat",
    file_contains("resources/js/router/index.js", "redirect"),
)

# Three page components
checker.check(
    "ChatPage component exists",
    file_exists("resources/js/pages/ChatPage.vue"),
)
checker.check(
    "DashboardPage component exists",
    file_exists("resources/js/pages/DashboardPage.vue"),
)
checker.check(
    "AdminPage component exists",
    file_exists("resources/js/pages/AdminPage.vue"),
)

# Navigation component
checker.check(
    "AppNavigation component exists",
    file_exists("resources/js/components/AppNavigation.vue"),
)
checker.check(
    "Navigation uses script setup",
    file_contains("resources/js/components/AppNavigation.vue", "<script setup>"),
)
checker.check(
    "Navigation includes mobile hamburger menu",
    file_contains(
        "resources/js/components/AppNavigation.vue", "mobileMenuOpen"
    ),
)
checker.check(
    "Navigation uses responsive breakpoint (md:hidden or md:flex)",
    file_contains("resources/js/components/AppNavigation.vue", "md:"),
)

# Auth store placeholder
checker.check(
    "Auth Pinia store exists",
    file_exists("resources/js/stores/auth.js"),
)
checker.check(
    "Auth store uses defineStore",
    file_contains("resources/js/stores/auth.js", "defineStore"),
)

# Tailwind scans Vue files
checker.check(
    "Tailwind CSS scans Vue files",
    file_contains("resources/css/app.css", "*.vue"),
)

# Vitest tests exist
checker.check(
    "Router tests exist",
    file_exists("resources/js/router/index.test.js"),
)
checker.check(
    "App component tests exist",
    file_exists("resources/js/App.test.js"),
)
checker.check(
    "Navigation component tests exist",
    file_exists("resources/js/components/AppNavigation.test.js"),
)
```

**Step 2: Run verification**

Run: `python3 verify/verify_m3.py`
Expected: All checks pass (after all previous tasks are complete).

**Step 3: Commit**

```bash
git add verify/verify_m3.py
git commit --no-gpg-sign -m "T61.10: Add T61 verification checks to verify_m3.py"
```

---

### Task 11: Run full verification and finalize

**Step 1: Run Laravel tests**

Run: `php artisan test`
Expected: All tests pass.

**Step 2: Run Vitest**

Run: `npx vitest run`
Expected: All Vue tests pass.

**Step 3: Run Vite production build**

Run: `npx vite build`
Expected: Build succeeds.

**Step 4: Run M3 verification script**

Run: `python3 verify/verify_m3.py`
Expected: All checks pass.

**Step 5: Update progress.md**

- Mark T61 as `[x]`
- Update milestone count: `14/27`
- Bold T62 as next task
- Update summary section

**Step 6: Clear handoff.md**

Reset handoff.md to empty template.

**Step 7: Final commit**

```bash
git add progress.md handoff.md
git commit --no-gpg-sign -m "T61: Vue SPA scaffold — Vue 3 + Vite + Pinia + Vue Router with responsive layout"
```

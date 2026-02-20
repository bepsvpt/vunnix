import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAuthStore } from '@/features/auth';
import router from '../index';

describe('router', () => {
    it('uses history mode (no hash)', () => {
        // createWebHistory stores base as '' (root) — not hash-based
        expect(router.options.history.base).toBe('');
    });

    it('has five named routes: sign-in, chat, chat-conversation, dashboard, admin', () => {
        const routeNames = router.getRoutes().map(r => r.name).filter(Boolean);
        expect(routeNames).toContain('sign-in');
        expect(routeNames).toContain('chat');
        expect(routeNames).toContain('chat-conversation');
        expect(routeNames).toContain('dashboard');
        expect(routeNames).toContain('admin');
    });

    it('redirects / to /chat', () => {
        const rootOption = router.options.routes.find(r => r.path === '/');
        expect(rootOption!.redirect).toBe('/chat');
    });

    it('defines /chat/:id route for deep-linking conversations', () => {
        const route = router.options.routes.find(r => r.path === '/chat/:id');
        expect(route).toBeDefined();
        expect(route!.name).toBe('chat-conversation');
    });

    it('defines /sign-in route', () => {
        const route = router.options.routes.find(r => r.path === '/sign-in');
        expect(route).toBeDefined();
        expect(route!.name).toBe('sign-in');
    });
});

describe('router beforeEach guard', () => {
    beforeEach(() => {
        const pinia = createPinia();
        setActivePinia(pinia);
    });

    it('allows navigation when user is already authenticated', async () => {
        const auth = useAuthStore();
        auth.setUser({
            id: 1,
            name: 'Test User',
            email: 'test@example.com',
            username: 'testuser',
            avatar_url: null,
            projects: [],
        });
        const fetchSpy = vi.spyOn(auth, 'fetchUser');

        await router.push('/dashboard');

        expect(fetchSpy).not.toHaveBeenCalled();
        expect(router.currentRoute.value.path).toBe('/dashboard');
    });

    it('calls fetchUser on first load when auth state is unknown (user is null)', async () => {
        const auth = useAuthStore();
        // user starts as null (unknown state) — guard should call fetchUser
        const fetchSpy = vi.spyOn(auth, 'fetchUser').mockImplementation(async () => {
            // Simulate successful fetch — set user to authenticated
            auth.setUser({
                id: 1,
                name: 'Test User',
                email: 'test@example.com',
                username: 'testuser',
                avatar_url: null,
                projects: [],
            });
        });

        await router.push('/chat');

        expect(fetchSpy).toHaveBeenCalledOnce();
        expect(router.currentRoute.value.path).toBe('/chat');
    });

    it('redirects to /sign-in when fetchUser fails (user becomes guest)', async () => {
        const auth = useAuthStore();
        // user starts as null — guard will call fetchUser
        vi.spyOn(auth, 'fetchUser').mockImplementation(async () => {
            // Simulate failed fetch — user becomes false (guest)
            auth.clearUser();
        });

        // Start from a known route first so we can verify redirect
        auth.setUser({
            id: 1,
            name: 'Temp',
            email: 't@t.com',
            username: 'temp',
            avatar_url: null,
            projects: [],
        });
        await router.push('/dashboard');

        // Now clear auth state to simulate unknown user and try navigating
        auth.user = null;

        await router.push('/admin');

        // Guard should redirect guest to /sign-in
        expect(router.currentRoute.value.path).toBe('/sign-in');
    });

    it('does not re-fetch on subsequent navigations when user is authenticated', async () => {
        const auth = useAuthStore();
        // Simulate first load: fetchUser succeeds
        const fetchSpy = vi.spyOn(auth, 'fetchUser').mockImplementation(async () => {
            auth.setUser({
                id: 1,
                name: 'Test User',
                email: 'test@example.com',
                username: 'testuser',
                avatar_url: null,
                projects: [],
            });
        });

        // First navigation triggers fetchUser (user is null)
        await router.push('/chat');
        expect(fetchSpy).toHaveBeenCalledOnce();

        // Second navigation — user is now authenticated, no re-fetch needed
        await router.push('/dashboard');
        expect(fetchSpy).toHaveBeenCalledOnce(); // still just once
        expect(router.currentRoute.value.path).toBe('/dashboard');
    });

    it('does not call fetchUser when isLoading is true (fetch already in progress)', async () => {
        const auth = useAuthStore();
        // Simulate a state where another fetch is already in progress
        auth.loading = true;
        // user is still null but loading is true — guard should skip fetchUser

        const fetchSpy = vi.spyOn(auth, 'fetchUser').mockImplementation(async () => {
            auth.setUser({
                id: 1,
                name: 'Test',
                email: 't@t.com',
                username: 'test',
                avatar_url: null,
                projects: [],
            });
        });

        // user is null and isLoading is true — guard checks: user === null && !isLoading
        // Since isLoading is true, the condition is false, so fetchUser is NOT called.
        // Then isGuest check: user is null (not false), so isGuest is false → navigation proceeds.
        await router.push('/chat');

        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('redirects guest to /sign-in when visiting a protected route', async () => {
        const auth = useAuthStore();
        // Set user to false (guest) — this means auth was already checked
        auth.clearUser(); // sets user to false

        const fetchSpy = vi.spyOn(auth, 'fetchUser');

        await router.push('/dashboard');

        expect(fetchSpy).not.toHaveBeenCalled();
        // Guest should be redirected to /sign-in
        expect(router.currentRoute.value.path).toBe('/sign-in');
    });

    it('allows guest to stay on /sign-in', async () => {
        const auth = useAuthStore();
        auth.clearUser(); // sets user to false (guest)

        await router.push('/sign-in');

        expect(router.currentRoute.value.path).toBe('/sign-in');
    });

    it('redirects authenticated user away from /sign-in to /chat', async () => {
        const auth = useAuthStore();
        auth.setUser({
            id: 1,
            name: 'Test User',
            email: 'test@example.com',
            username: 'testuser',
            avatar_url: null,
            projects: [],
        });

        // Navigate to a known route first so /sign-in push isn't a no-op
        await router.push('/dashboard');
        await router.push('/sign-in');

        expect(router.currentRoute.value.path).toBe('/chat');
    });
});

import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAuthStore } from '@/stores/auth';
import router from './index';

describe('router', () => {
    it('uses history mode (no hash)', () => {
        // createWebHistory stores base as '' (root) — not hash-based
        expect(router.options.history.base).toBe('');
    });

    it('has four named routes: chat, chat-conversation, dashboard, admin', () => {
        const routeNames = router.getRoutes().map(r => r.name).filter(Boolean);
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
        const loginSpy = vi.spyOn(auth, 'login').mockImplementation(() => {});

        await router.push('/dashboard');

        expect(fetchSpy).not.toHaveBeenCalled();
        expect(loginSpy).not.toHaveBeenCalled();
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
        const loginSpy = vi.spyOn(auth, 'login').mockImplementation(() => {});

        await router.push('/chat');

        expect(fetchSpy).toHaveBeenCalledOnce();
        expect(loginSpy).not.toHaveBeenCalled();
        expect(router.currentRoute.value.path).toBe('/chat');
    });

    it('calls login and aborts navigation when fetchUser fails (user remains guest)', async () => {
        const auth = useAuthStore();
        // user starts as null — guard will call fetchUser
        const fetchSpy = vi.spyOn(auth, 'fetchUser').mockImplementation(async () => {
            // Simulate failed fetch — user becomes false (guest)
            auth.clearUser();
        });
        const loginSpy = vi.spyOn(auth, 'login').mockImplementation(() => {});

        // Start from a known route first so we can verify navigation was aborted
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

        expect(fetchSpy).toHaveBeenCalledOnce();
        expect(loginSpy).toHaveBeenCalledOnce();
        // Navigation should be aborted (return false in guard), so route stays at /dashboard
        expect(router.currentRoute.value.path).toBe('/dashboard');
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
        const loginSpy = vi.spyOn(auth, 'login').mockImplementation(() => {});

        // First navigation triggers fetchUser (user is null)
        await router.push('/chat');
        expect(fetchSpy).toHaveBeenCalledOnce();

        // Second navigation — user is now authenticated, no re-fetch needed
        await router.push('/dashboard');
        expect(fetchSpy).toHaveBeenCalledOnce(); // still just once
        expect(loginSpy).not.toHaveBeenCalled();
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
        const loginSpy = vi.spyOn(auth, 'login').mockImplementation(() => {});

        // user is null and isLoading is true — guard checks: user === null && !isLoading
        // Since isLoading is true, the condition is false, so fetchUser is NOT called.
        // Then isGuest check: user is null (not false), so isGuest is false → navigation proceeds.
        await router.push('/chat');

        expect(fetchSpy).not.toHaveBeenCalled();
        expect(loginSpy).not.toHaveBeenCalled();
    });

    it('redirects to OAuth login when user is explicitly a guest (user is false)', async () => {
        const auth = useAuthStore();
        // Set user to false (guest) — this means auth was already checked
        auth.clearUser(); // sets user to false

        const fetchSpy = vi.spyOn(auth, 'fetchUser');
        const loginSpy = vi.spyOn(auth, 'login').mockImplementation(() => {});

        // user is false: not null, so fetchUser is NOT called.
        // isGuest is true → login() is called and navigation is aborted.
        await router.push('/dashboard');

        expect(fetchSpy).not.toHaveBeenCalled();
        expect(loginSpy).toHaveBeenCalledOnce();
    });
});

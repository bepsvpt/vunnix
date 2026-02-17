import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAuthStore } from './auth';

vi.mock('axios');

beforeEach(() => {
    setActivePinia(createPinia());
    vi.restoreAllMocks();
    // Reset window.location mock
    delete window.location;
    window.location = { href: '' };
});

describe('useAuthStore', () => {
    describe('initial state', () => {
        it('starts with null user (unknown auth state)', () => {
            const auth = useAuthStore();
            expect(auth.user).toBeNull();
            expect(auth.isAuthenticated).toBe(false);
            expect(auth.isGuest).toBe(false);
            expect(auth.isLoading).toBe(false);
        });
    });

    describe('fetchUser', () => {
        it('sets user on successful API response', async () => {
            const userData = {
                id: 1,
                name: 'Jane Dev',
                email: 'jane@example.com',
                username: 'janedev',
                avatar_url: 'https://gitlab.com/avatar.png',
                projects: [],
            };
            axios.get.mockResolvedValue({ data: { data: userData } });

            const auth = useAuthStore();
            await auth.fetchUser();

            expect(axios.get).toHaveBeenCalledWith('/api/v1/user');
            expect(auth.user).toEqual(userData);
            expect(auth.isAuthenticated).toBe(true);
            expect(auth.isGuest).toBe(false);
            expect(auth.isLoading).toBe(false);
        });

        it('sets user to false on 401 (unauthenticated)', async () => {
            axios.get.mockRejectedValue({ response: { status: 401 } });

            const auth = useAuthStore();
            await auth.fetchUser();

            expect(auth.user).toBe(false);
            expect(auth.isAuthenticated).toBe(false);
            expect(auth.isGuest).toBe(true);
            expect(auth.isLoading).toBe(false);
        });

        it('sets loading during fetch', async () => {
            let resolvePromise;
            axios.get.mockReturnValue(new Promise((resolve) => {
                resolvePromise = resolve;
            }));

            const auth = useAuthStore();
            const fetchPromise = auth.fetchUser();

            expect(auth.isLoading).toBe(true);

            resolvePromise({ data: { data: { id: 1, name: 'Test' } } });
            await fetchPromise;

            expect(auth.isLoading).toBe(false);
        });
    });

    describe('login', () => {
        it('redirects to /auth/redirect', () => {
            const auth = useAuthStore();
            auth.login();

            expect(window.location.href).toBe('/auth/redirect');
        });
    });

    describe('logout', () => {
        it('posts to /auth/logout and redirects', async () => {
            axios.post.mockResolvedValue({});

            const auth = useAuthStore();
            auth.setUser({ id: 1, name: 'Test' });
            expect(auth.isAuthenticated).toBe(true);

            await auth.logout();

            expect(axios.post).toHaveBeenCalledWith('/auth/logout');
            expect(auth.user).toBe(false);
            expect(window.location.href).toBe('/auth/redirect');
        });

        it('clears user even if server logout fails', async () => {
            axios.post.mockRejectedValue(new Error('Network error'));

            const auth = useAuthStore();
            auth.setUser({ id: 1, name: 'Test' });

            await auth.logout();

            expect(auth.user).toBe(false);
            expect(window.location.href).toBe('/auth/redirect');
        });
    });

    describe('hasPermission', () => {
        it('returns true when any project has the permission', () => {
            const auth = useAuthStore();
            auth.setUser({
                id: 1,
                projects: [
                    { id: 1, permissions: ['chat.access'] },
                    { id: 2, permissions: ['admin.roles'] },
                ],
            });

            expect(auth.hasPermission('chat.access')).toBe(true);
            expect(auth.hasPermission('admin.roles')).toBe(true);
        });

        it('returns false when no project has the permission', () => {
            const auth = useAuthStore();
            auth.setUser({
                id: 1,
                projects: [{ id: 1, permissions: ['chat.access'] }],
            });

            expect(auth.hasPermission('admin.global_config')).toBe(false);
        });

        it('returns false when user is not authenticated', () => {
            const auth = useAuthStore();
            expect(auth.hasPermission('chat.access')).toBe(false);
        });
    });

    describe('hasProjectPermission', () => {
        it('returns true for matching project and permission', () => {
            const auth = useAuthStore();
            auth.setUser({
                id: 1,
                projects: [
                    { id: 1, permissions: ['chat.access'] },
                    { id: 2, permissions: ['admin.roles'] },
                ],
            });

            expect(auth.hasProjectPermission('chat.access', 1)).toBe(true);
            expect(auth.hasProjectPermission('admin.roles', 2)).toBe(true);
        });

        it('returns false for wrong project', () => {
            const auth = useAuthStore();
            auth.setUser({
                id: 1,
                projects: [{ id: 1, permissions: ['chat.access'] }],
            });

            expect(auth.hasProjectPermission('chat.access', 2)).toBe(false);
        });
    });

    describe('projects', () => {
        it('returns project list when authenticated', () => {
            const auth = useAuthStore();
            const projectList = [
                { id: 1, name: 'Project A' },
                { id: 2, name: 'Project B' },
            ];
            auth.setUser({ id: 1, projects: projectList });

            expect(auth.projects).toEqual(projectList);
        });

        it('returns empty array when not authenticated', () => {
            const auth = useAuthStore();
            expect(auth.projects).toEqual([]);
        });
    });

    describe('setUser / clearUser', () => {
        it('setUser sets the user object', () => {
            const auth = useAuthStore();
            auth.setUser({ id: 1, name: 'Test' });

            expect(auth.user).toEqual({ id: 1, name: 'Test' });
            expect(auth.isAuthenticated).toBe(true);
        });

        it('clearUser marks user as guest', () => {
            const auth = useAuthStore();
            auth.setUser({ id: 1, name: 'Test' });
            auth.clearUser();

            expect(auth.user).toBe(false);
            expect(auth.isGuest).toBe(true);
        });
    });
});

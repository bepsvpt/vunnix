import type { Router } from 'vue-router';
import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import AppNavigation from './AppNavigation.vue';

vi.mock('axios');

const mockedAxios = vi.mocked(axios, true);

let pinia: ReturnType<typeof createPinia>;

function createTestRouter(): Router {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/chat', name: 'chat', component: { template: '<div />' } },
            { path: '/dashboard', name: 'dashboard', component: { template: '<div />' } },
            { path: '/admin', name: 'admin', component: { template: '<div />' } },
        ],
    });
}

function mountNav(router: Router) {
    const auth = useAuthStore();
    auth.setUser({ id: 1, name: 'Test User', username: 'testuser', avatar_url: null, projects: [] });

    return mount(AppNavigation, {
        global: { plugins: [pinia, router] },
    });
}

beforeEach(() => {
    pinia = createPinia();
    setActivePinia(pinia);
    vi.restoreAllMocks();
    mockedAxios.post.mockResolvedValue({});
});

describe('appNavigation', () => {
    it('renders brand name', async () => {
        const router = createTestRouter();
        router.push('/chat');
        await router.isReady();

        const wrapper = mountNav(router);

        expect(wrapper.text()).toContain('Vunnix');
    });

    it('renders all three nav links', async () => {
        const router = createTestRouter();
        router.push('/chat');
        await router.isReady();

        const wrapper = mountNav(router);

        const links = wrapper.findAll('a');
        const labels = links.map(l => l.text());
        expect(labels.some(l => l.includes('Chat'))).toBe(true);
        expect(labels.some(l => l.includes('Dashboard'))).toBe(true);
        expect(labels.some(l => l.includes('Admin'))).toBe(true);
    });

    it('mobile menu is hidden by default', async () => {
        const router = createTestRouter();
        router.push('/chat');
        await router.isReady();

        const wrapper = mountNav(router);

        // The mobile panel uses v-if so it should not exist in DOM
        const mobilePanel = wrapper.find('[class*="md:hidden border-t"]');
        expect(mobilePanel.exists()).toBe(false);
    });

    it('toggles mobile menu on hamburger click', async () => {
        const router = createTestRouter();
        router.push('/chat');
        await router.isReady();

        const wrapper = mountNav(router);

        const button = wrapper.find('button[aria-label="Toggle navigation menu"]');
        await button.trigger('click');

        // Mobile panel should now be visible
        expect(wrapper.findAll('a').length).toBeGreaterThan(3); // desktop + mobile links
    });

    it('has hamburger button hidden on desktop (md:hidden class)', async () => {
        const router = createTestRouter();
        router.push('/chat');
        await router.isReady();

        const wrapper = mountNav(router);

        const button = wrapper.find('button[aria-label="Toggle navigation menu"]');
        expect(button.classes()).toContain('md:hidden');
    });

    describe('user display', () => {
        it('renders user name in the desktop user menu button', async () => {
            const router = createTestRouter();
            router.push('/chat');
            await router.isReady();

            const wrapper = mountNav(router);

            const userMenuBtn = wrapper.find('button[aria-label="User menu"]');
            expect(userMenuBtn.exists()).toBe(true);
            expect(userMenuBtn.text()).toContain('Test User');
        });

        it('renders user initial when no avatar_url is set', async () => {
            const router = createTestRouter();
            router.push('/chat');
            await router.isReady();

            const wrapper = mountNav(router);

            // The initial fallback span should show 'T' for 'Test User'
            const initialSpan = wrapper.find('button[aria-label="User menu"] span.rounded-full');
            expect(initialSpan.exists()).toBe(true);
            expect(initialSpan.text()).toBe('T');
        });

        it('renders avatar image when avatar_url is set', async () => {
            const router = createTestRouter();
            router.push('/chat');
            await router.isReady();

            const auth = useAuthStore();
            auth.setUser({
                id: 1,
                name: 'Avatar User',
                username: 'avataruser',
                avatar_url: 'https://example.com/avatar.png',
                projects: [],
            });

            const wrapper = mount(AppNavigation, {
                global: { plugins: [pinia, router] },
            });

            const img = wrapper.find('button[aria-label="User menu"] img');
            expect(img.exists()).toBe(true);
            expect(img.attributes('src')).toBe('https://example.com/avatar.png');
            expect(img.attributes('alt')).toBe('Avatar User');
        });

        it('shows username in the dropdown when user menu is opened', async () => {
            const router = createTestRouter();
            router.push('/chat');
            await router.isReady();

            const wrapper = mountNav(router);

            // Open user menu dropdown
            const userMenuBtn = wrapper.find('button[aria-label="User menu"]');
            await userMenuBtn.trigger('click');

            // The dropdown should show the username
            expect(wrapper.text()).toContain('testuser');
        });

        it('hides user menu when not authenticated', async () => {
            const router = createTestRouter();
            router.push('/chat');
            await router.isReady();

            const auth = useAuthStore();
            auth.clearUser();

            const wrapper = mount(AppNavigation, {
                global: { plugins: [pinia, router] },
            });

            const userMenuBtn = wrapper.find('button[aria-label="User menu"]');
            expect(userMenuBtn.exists()).toBe(false);
        });
    });

    describe('user menu toggle', () => {
        it('opens user menu dropdown on click', async () => {
            const router = createTestRouter();
            router.push('/chat');
            await router.isReady();

            const wrapper = mountNav(router);

            // Dropdown should not exist initially
            expect(wrapper.find('.absolute.right-0').exists()).toBe(false);

            // Click user menu button
            const userMenuBtn = wrapper.find('button[aria-label="User menu"]');
            await userMenuBtn.trigger('click');

            // Dropdown should now be visible
            expect(wrapper.find('.absolute.right-0').exists()).toBe(true);
        });

        it('closes user menu dropdown on second click', async () => {
            const router = createTestRouter();
            router.push('/chat');
            await router.isReady();

            const wrapper = mountNav(router);

            const userMenuBtn = wrapper.find('button[aria-label="User menu"]');
            // Open
            await userMenuBtn.trigger('click');
            expect(wrapper.find('.absolute.right-0').exists()).toBe(true);

            // Close
            await userMenuBtn.trigger('click');
            expect(wrapper.find('.absolute.right-0').exists()).toBe(false);
        });

        it('closes user menu dropdown when clicking the overlay', async () => {
            const router = createTestRouter();
            router.push('/chat');
            await router.isReady();

            const wrapper = mountNav(router);

            // Open user menu
            const userMenuBtn = wrapper.find('button[aria-label="User menu"]');
            await userMenuBtn.trigger('click');
            expect(wrapper.find('.absolute.right-0').exists()).toBe(true);

            // Click the click-away overlay (fixed inset-0)
            const overlay = wrapper.find('.fixed.inset-0');
            expect(overlay.exists()).toBe(true);
            await overlay.trigger('click');

            // Dropdown should be closed
            expect(wrapper.find('.absolute.right-0').exists()).toBe(false);
        });

        it('sets aria-expanded attribute on user menu button', async () => {
            const router = createTestRouter();
            router.push('/chat');
            await router.isReady();

            const wrapper = mountNav(router);

            const userMenuBtn = wrapper.find('button[aria-label="User menu"]');
            expect(userMenuBtn.attributes('aria-expanded')).toBe('false');

            await userMenuBtn.trigger('click');
            expect(userMenuBtn.attributes('aria-expanded')).toBe('true');
        });
    });

    describe('logout', () => {
        it('calls auth.logout() when desktop logout button is clicked', async () => {
            const router = createTestRouter();
            router.push('/chat');
            await router.isReady();

            const wrapper = mountNav(router);
            const auth = useAuthStore();
            const logoutSpy = vi.spyOn(auth, 'logout').mockResolvedValue();

            // Open user menu
            const userMenuBtn = wrapper.find('button[aria-label="User menu"]');
            await userMenuBtn.trigger('click');

            // Click logout button in dropdown
            const logoutBtn = wrapper.findAll('button').find(b => b.text().includes('Log out'));
            expect(logoutBtn).toBeTruthy();
            await logoutBtn!.trigger('click');
            await flushPromises();

            expect(logoutSpy).toHaveBeenCalledOnce();
        });

        it('closes user menu and mobile menu before logging out', async () => {
            const router = createTestRouter();
            router.push('/chat');
            await router.isReady();

            const wrapper = mountNav(router);
            const auth = useAuthStore();
            const logoutSpy = vi.spyOn(auth, 'logout').mockResolvedValue();

            // Open user menu
            const userMenuBtn = wrapper.find('button[aria-label="User menu"]');
            await userMenuBtn.trigger('click');

            // Click logout
            const logoutBtn = wrapper.findAll('button').find(b => b.text().includes('Log out'));
            await logoutBtn!.trigger('click');
            await flushPromises();

            // User menu dropdown should be closed (handleLogout calls closeUserMenu + closeMenu)
            expect(wrapper.find('.absolute.right-0').exists()).toBe(false);
            expect(logoutSpy).toHaveBeenCalled();
        });

        it('calls auth.logout() when mobile logout button is clicked', async () => {
            const router = createTestRouter();
            router.push('/chat');
            await router.isReady();

            const wrapper = mountNav(router);
            const auth = useAuthStore();
            const logoutSpy = vi.spyOn(auth, 'logout').mockResolvedValue();

            // Open mobile menu
            const hamburger = wrapper.find('button[aria-label="Toggle navigation menu"]');
            await hamburger.trigger('click');

            // Find the mobile logout button (in the mobile user section)
            const mobileButtons = wrapper.findAll('button').filter(b => b.text().includes('Log out'));
            // The mobile logout button should exist
            expect(mobileButtons.length).toBeGreaterThan(0);
            await mobileButtons[mobileButtons.length - 1].trigger('click');
            await flushPromises();

            expect(logoutSpy).toHaveBeenCalledOnce();
        });
    });

    describe('mobile menu interactions', () => {
        it('closes mobile menu when a nav link is clicked', async () => {
            const router = createTestRouter();
            router.push('/chat');
            await router.isReady();

            const wrapper = mountNav(router);

            // Open mobile menu
            const hamburger = wrapper.find('button[aria-label="Toggle navigation menu"]');
            await hamburger.trigger('click');

            // Verify mobile panel is open (has both desktop + mobile links)
            const linksBefore = wrapper.findAll('a');
            expect(linksBefore.length).toBeGreaterThan(3);

            // Click the Dashboard link in mobile menu (desktop links are in hidden md:flex div)
            // Mobile links are inside the md:hidden border-t div
            const mobileLinks = wrapper.findAll('a').filter(a => a.text().includes('Dashboard'));
            await mobileLinks[mobileLinks.length - 1].trigger('click');
            await flushPromises();

            // Mobile panel should be closed (closeMenu is called on link click)
            const mobilePanel = wrapper.find('[class*="md:hidden border-t"]');
            expect(mobilePanel.exists()).toBe(false);
        });

        it('renders mobile user section with name when authenticated', async () => {
            const router = createTestRouter();
            router.push('/chat');
            await router.isReady();

            const wrapper = mountNav(router);

            // Open mobile menu
            const hamburger = wrapper.find('button[aria-label="Toggle navigation menu"]');
            await hamburger.trigger('click');

            // Should show user info in mobile section
            expect(wrapper.text()).toContain('Test User');
            expect(wrapper.text()).toContain('testuser');
        });

        it('renders avatar in mobile user section when avatar_url is set', async () => {
            const router = createTestRouter();
            router.push('/chat');
            await router.isReady();

            const auth = useAuthStore();
            auth.setUser({
                id: 1,
                name: 'Mobile User',
                username: 'mobileuser',
                avatar_url: 'https://example.com/mobile-avatar.png',
                projects: [],
            });

            const wrapper = mount(AppNavigation, {
                global: { plugins: [pinia, router] },
            });

            // Open mobile menu
            const hamburger = wrapper.find('button[aria-label="Toggle navigation menu"]');
            await hamburger.trigger('click');

            // Find mobile user section images (look for the 8x8 sized avatar in mobile section)
            const imgs = wrapper.findAll('img');
            const mobileAvatar = imgs.find(img => img.classes().includes('h-8'));
            expect(mobileAvatar).toBeTruthy();
            expect(mobileAvatar!.attributes('src')).toBe('https://example.com/mobile-avatar.png');
        });

        it('hides mobile user section when not authenticated', async () => {
            const router = createTestRouter();
            router.push('/chat');
            await router.isReady();

            const auth = useAuthStore();
            auth.clearUser();

            const wrapper = mount(AppNavigation, {
                global: { plugins: [pinia, router] },
            });

            // Open mobile menu
            const hamburger = wrapper.find('button[aria-label="Toggle navigation menu"]');
            await hamburger.trigger('click');

            // Should not show logout button since not authenticated
            const logoutBtns = wrapper.findAll('button').filter(b => b.text().includes('Log out'));
            expect(logoutBtns.length).toBe(0);
        });
    });

    describe('active route highlighting', () => {
        it('applies active class to the current route link', async () => {
            const router = createTestRouter();
            router.push('/dashboard');
            await router.isReady();

            const wrapper = mountNav(router);

            // Find the Dashboard link — it should have the underline active-class applied by Vue Router
            const links = wrapper.findAll('a');
            const dashboardLink = links.find(l => l.text().includes('Dashboard'));
            expect(dashboardLink).toBeTruthy();
            // The component uses active-class with border-based underline
            expect(dashboardLink!.classes().some(c => c.includes('border-zinc-900'))).toBe(true);
        });

        it('does not apply active class to non-current route links', async () => {
            const router = createTestRouter();
            router.push('/chat');
            await router.isReady();

            const wrapper = mountNav(router);

            // Dashboard link should NOT have the active underline class
            const links = wrapper.findAll('a');
            const dashboardLink = links.find(l => l.text().includes('Dashboard'));
            expect(dashboardLink).toBeTruthy();
            expect(dashboardLink!.classes().some(c => c.includes('border-zinc-900'))).toBe(false);
        });
    });
});

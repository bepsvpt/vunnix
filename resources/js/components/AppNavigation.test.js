import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import AppNavigation from './AppNavigation.vue';

let pinia;

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

function mountNav(router) {
    const auth = useAuthStore();
    auth.setUser({ id: 1, name: 'Test User', username: 'testuser', avatar_url: null, projects: [] });

    return mount(AppNavigation, {
        global: { plugins: [pinia, router] },
    });
}

beforeEach(() => {
    pinia = createPinia();
    setActivePinia(pinia);
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
        expect(labels).toContain('Chat');
        expect(labels).toContain('Dashboard');
        expect(labels).toContain('Admin');
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
});

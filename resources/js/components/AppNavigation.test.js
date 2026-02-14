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

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

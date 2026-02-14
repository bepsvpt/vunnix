import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import App from './App.vue';
import { useAuthStore } from '@/stores/auth';
import ChatPage from './pages/ChatPage.vue';
import DashboardPage from './pages/DashboardPage.vue';
import AdminPage from './pages/AdminPage.vue';

let pinia;

function createTestRouter() {
    // Create a router without the auth guard — App.test focuses on rendering,
    // not auth flow. The production router guard is tested in auth.test.js.
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

beforeEach(() => {
    pinia = createPinia();
    setActivePinia(pinia);
});

describe('App', () => {
    it('mounts and renders navigation', async () => {
        const auth = useAuthStore();
        auth.setUser({ id: 1, name: 'Test User', username: 'testuser', avatar_url: null, projects: [] });

        const router = createTestRouter();
        router.push('/chat');
        await router.isReady();

        const wrapper = mount(App, {
            global: {
                plugins: [pinia, router],
            },
        });

        expect(wrapper.find('nav').exists()).toBe(true);
        expect(wrapper.text()).toContain('Vunnix');
    });

    it('renders ChatPage at /chat', async () => {
        const auth = useAuthStore();
        auth.setUser({ id: 1, name: 'Test User', username: 'testuser', avatar_url: null, projects: [] });

        const router = createTestRouter();
        router.push('/chat');
        await router.isReady();

        const wrapper = mount(App, {
            global: {
                plugins: [pinia, router],
            },
        });

        expect(wrapper.text()).toContain('Chat');
    });

    it('renders DashboardPage at /dashboard', async () => {
        const auth = useAuthStore();
        auth.setUser({ id: 1, name: 'Test User', username: 'testuser', avatar_url: null, projects: [] });

        const router = createTestRouter();
        router.push('/dashboard');
        await router.isReady();

        const wrapper = mount(App, {
            global: {
                plugins: [pinia, router],
            },
        });

        expect(wrapper.text()).toContain('Dashboard');
    });

    it('renders AdminPage at /admin', async () => {
        const auth = useAuthStore();
        auth.setUser({ id: 1, name: 'Test User', username: 'testuser', avatar_url: null, projects: [] });

        const router = createTestRouter();
        router.push('/admin');
        await router.isReady();

        const wrapper = mount(App, {
            global: {
                plugins: [pinia, router],
            },
        });

        expect(wrapper.text()).toContain('Admin');
    });

    it('shows loading state when auth is unknown', async () => {
        // Auth store starts with user=null (unknown), so App should show spinner
        const router = createTestRouter();
        router.push('/chat');
        await router.isReady();

        const wrapper = mount(App, {
            global: {
                plugins: [pinia, router],
            },
        });

        expect(wrapper.text()).toContain('Loading');
        expect(wrapper.find('nav').exists()).toBe(false);
    });
});

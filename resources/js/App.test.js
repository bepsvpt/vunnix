import { mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import App from './App.vue';
import AdminPage from './pages/AdminPage.vue';
import ChatPage from './pages/ChatPage.vue';
import DashboardPage from './pages/DashboardPage.vue';

vi.mock('axios');

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
    vi.clearAllMocks();
    // DashboardPage calls fetchActivity() + fetchOverview() on mount, which needs axios mocked
    axios.get.mockImplementation((url) => {
        if (url === '/api/v1/dashboard/overview') {
            return Promise.resolve({
                data: { data: { tasks_by_type: { code_review: 0, feature_dev: 0, ui_adjustment: 0, prd_creation: 0 }, active_tasks: 0, success_rate: null, total_completed: 0, total_failed: 0, recent_activity: null } },
            });
        }
        return Promise.resolve({
            data: { data: [], meta: { next_cursor: null, per_page: 25 } },
        });
    });
});

describe('app', () => {
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

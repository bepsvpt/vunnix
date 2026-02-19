import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAuthStore } from '@/stores/auth';
import { useDashboardStore } from '@/stores/dashboard';
import DashboardPage from './DashboardPage.vue';

vi.mock('axios');

const mockSubscribe = vi.fn();
const mockUnsubscribe = vi.fn();

vi.mock('@/composables/useDashboardRealtime', () => ({
    useDashboardRealtime: () => ({
        subscribe: mockSubscribe,
        unsubscribe: mockUnsubscribe,
    }),
}));

// Mock markdown module to avoid Shiki async loading
vi.mock('@/lib/markdown', () => ({
    getMarkdownRenderer: () => ({
        render: (content: string) => `<p>${content}</p>`,
    }),
    isHighlightReady: (): boolean => false,
    onHighlightLoaded: vi.fn(),
}));

const mockedAxios = vi.mocked(axios, true);

let pinia: ReturnType<typeof createPinia>;

function setUpAdminUser() {
    const auth = useAuthStore();
    auth.setUser({
        id: 1,
        name: 'Admin User',
        username: 'admin',
        email: 'admin@test.com',
        avatar_url: null,
        projects: [
            { id: 1, gitlab_project_id: 100, name: 'Test Project', slug: 'test-project', roles: ['admin'], permissions: ['admin.global_config'] },
        ],
    });
}

function setUpRegularUser() {
    const auth = useAuthStore();
    auth.setUser({
        id: 2,
        name: 'Regular User',
        username: 'regular',
        email: 'regular@test.com',
        avatar_url: null,
        projects: [
            { id: 1, gitlab_project_id: 100, name: 'Test Project', slug: 'test-project', roles: ['developer'], permissions: ['chat.send'] },
        ],
    });
}

beforeEach(() => {
    pinia = createPinia();
    setActivePinia(pinia);
    vi.restoreAllMocks();

    mockedAxios.get.mockImplementation((url: string) => {
        if (url === '/api/v1/dashboard/overview') {
            return Promise.resolve({
                data: {
                    data: {
                        tasks_by_type: { code_review: 0, feature_dev: 0, ui_adjustment: 0, prd_creation: 0 },
                        active_tasks: 0,
                        success_rate: null,
                        total_completed: 0,
                        total_failed: 0,
                        recent_activity: null,
                    },
                },
            });
        }
        return Promise.resolve({
            data: { data: [], meta: { next_cursor: null, per_page: 25 } },
        });
    });
});

function mountDashboard() {
    return mount(DashboardPage, {
        global: { plugins: [pinia] },
    });
}

describe('dashboardPage', () => {
    it('renders dashboard heading', () => {
        setUpAdminUser();
        const wrapper = mountDashboard();
        expect(wrapper.text()).toContain('Dashboard');
    });

    it('renders memory stats widget in overview', () => {
        setUpAdminUser();
        const wrapper = mountDashboard();
        expect(wrapper.find('[data-testid="memory-stats-widget"]').exists()).toBe(true);
    });

    it('shows base view tabs for all users', () => {
        setUpRegularUser();
        const wrapper = mountDashboard();

        expect(wrapper.find('[data-testid="tab-overview"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="tab-quality"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="tab-pm-activity"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="tab-designer-activity"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="tab-efficiency"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="tab-adoption"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="tab-activity"]').exists()).toBe(true);
    });

    it('shows cost and infrastructure tabs for admin users', () => {
        setUpAdminUser();
        const wrapper = mountDashboard();

        expect(wrapper.find('[data-testid="tab-cost"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="tab-infrastructure"]').exists()).toBe(true);
    });

    it('hides cost and infrastructure tabs for non-admin users', () => {
        setUpRegularUser();
        const wrapper = mountDashboard();

        expect(wrapper.find('[data-testid="tab-cost"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="tab-infrastructure"]').exists()).toBe(false);
    });

    it('activity tab always appears last (after admin tabs)', () => {
        setUpAdminUser();
        const wrapper = mountDashboard();

        const tabs = wrapper.findAll('[data-testid="dashboard-view-tabs"] button');
        const lastTab = tabs[tabs.length - 1];
        expect(lastTab.text()).toBe('Activity');
    });

    it('defaults to overview tab as active', () => {
        setUpAdminUser();
        const wrapper = mountDashboard();

        const overviewTab = wrapper.find('[data-testid="tab-overview"]');
        expect(overviewTab.classes()).toContain('border-zinc-900');
    });

    it('switches to quality tab on click', async () => {
        setUpAdminUser();
        const dashboard = useDashboardStore();
        vi.spyOn(dashboard, 'fetchQuality').mockResolvedValue();
        vi.spyOn(dashboard, 'fetchPromptVersions').mockResolvedValue();

        const wrapper = mountDashboard();
        await wrapper.find('[data-testid="tab-quality"]').trigger('click');
        await wrapper.vm.$nextTick();

        // Quality tab should now be active
        const qualityTab = wrapper.find('[data-testid="tab-quality"]');
        expect(qualityTab.classes()).toContain('border-zinc-900');

        // Overview tab should no longer be active
        const overviewTab = wrapper.find('[data-testid="tab-overview"]');
        expect(overviewTab.classes()).not.toContain('border-zinc-900');
    });

    it('switches to pm-activity tab on click', async () => {
        setUpAdminUser();
        const dashboard = useDashboardStore();
        vi.spyOn(dashboard, 'fetchPMActivity').mockResolvedValue();

        const wrapper = mountDashboard();
        await wrapper.find('[data-testid="tab-pm-activity"]').trigger('click');
        await wrapper.vm.$nextTick();

        const pmTab = wrapper.find('[data-testid="tab-pm-activity"]');
        expect(pmTab.classes()).toContain('border-zinc-900');
    });

    it('switches to designer-activity tab on click', async () => {
        setUpAdminUser();
        const dashboard = useDashboardStore();
        vi.spyOn(dashboard, 'fetchDesignerActivity').mockResolvedValue();

        const wrapper = mountDashboard();
        await wrapper.find('[data-testid="tab-designer-activity"]').trigger('click');
        await wrapper.vm.$nextTick();

        const designerTab = wrapper.find('[data-testid="tab-designer-activity"]');
        expect(designerTab.classes()).toContain('border-zinc-900');
    });

    it('switches to efficiency tab on click', async () => {
        setUpAdminUser();
        const dashboard = useDashboardStore();
        vi.spyOn(dashboard, 'fetchEfficiency').mockResolvedValue();

        const wrapper = mountDashboard();
        await wrapper.find('[data-testid="tab-efficiency"]').trigger('click');
        await wrapper.vm.$nextTick();

        const efficiencyTab = wrapper.find('[data-testid="tab-efficiency"]');
        expect(efficiencyTab.classes()).toContain('border-zinc-900');
    });

    it('switches to cost tab on click (admin only)', async () => {
        setUpAdminUser();
        const dashboard = useDashboardStore();
        vi.spyOn(dashboard, 'fetchCost').mockResolvedValue();
        vi.spyOn(dashboard, 'fetchCostAlerts').mockResolvedValue();

        const wrapper = mountDashboard();
        await wrapper.find('[data-testid="tab-cost"]').trigger('click');
        await wrapper.vm.$nextTick();

        const costTab = wrapper.find('[data-testid="tab-cost"]');
        expect(costTab.classes()).toContain('border-zinc-900');
    });

    it('switches to infrastructure tab on click (admin only)', async () => {
        setUpAdminUser();
        const dashboard = useDashboardStore();
        vi.spyOn(dashboard, 'fetchInfrastructureAlerts').mockResolvedValue();

        const wrapper = mountDashboard();
        await wrapper.find('[data-testid="tab-infrastructure"]').trigger('click');
        await wrapper.vm.$nextTick();

        const infraTab = wrapper.find('[data-testid="tab-infrastructure"]');
        expect(infraTab.classes()).toContain('border-zinc-900');
    });

    it('switches to adoption tab on click', async () => {
        setUpAdminUser();
        const dashboard = useDashboardStore();
        vi.spyOn(dashboard, 'fetchAdoption').mockResolvedValue();

        const wrapper = mountDashboard();
        await wrapper.find('[data-testid="tab-adoption"]').trigger('click');
        await wrapper.vm.$nextTick();

        const adoptionTab = wrapper.find('[data-testid="tab-adoption"]');
        expect(adoptionTab.classes()).toContain('border-zinc-900');
    });

    it('switches to activity tab on click', async () => {
        setUpAdminUser();

        const wrapper = mountDashboard();
        await wrapper.find('[data-testid="tab-activity"]').trigger('click');
        await wrapper.vm.$nextTick();

        const activityTab = wrapper.find('[data-testid="tab-activity"]');
        expect(activityTab.classes()).toContain('border-zinc-900');
    });

    it('calls fetchOverview and fetchActivity on mount', () => {
        setUpAdminUser();
        const dashboard = useDashboardStore();
        const fetchOverviewSpy = vi.spyOn(dashboard, 'fetchOverview').mockResolvedValue();
        const fetchActivitySpy = vi.spyOn(dashboard, 'fetchActivity').mockResolvedValue();

        mountDashboard();

        expect(fetchOverviewSpy).toHaveBeenCalled();
        expect(fetchActivitySpy).toHaveBeenCalled();
    });

    it('calls subscribe with user projects on mount', () => {
        setUpAdminUser();
        const auth = useAuthStore();

        mountDashboard();

        expect(mockSubscribe).toHaveBeenCalledWith(auth.projects);
    });

    it('calls unsubscribe on unmount', () => {
        setUpAdminUser();
        const wrapper = mountDashboard();

        wrapper.unmount();

        expect(mockUnsubscribe).toHaveBeenCalled();
    });

    it('re-fetches overview when metricsUpdates changes', async () => {
        setUpAdminUser();
        const dashboard = useDashboardStore();
        const fetchOverviewSpy = vi.spyOn(dashboard, 'fetchOverview').mockResolvedValue();

        mountDashboard();
        await flushPromises();

        // Clear the mount call count
        fetchOverviewSpy.mockClear();

        // Simulate a real-time metrics update arriving
        dashboard.metricsUpdates.push({ project_id: 1, tasks_completed: 5 });
        await flushPromises();

        expect(fetchOverviewSpy).toHaveBeenCalled();
    });

    it('does not show cost tab count for regular users', () => {
        setUpRegularUser();
        const wrapper = mountDashboard();

        const tabs = wrapper.findAll('[data-testid="dashboard-view-tabs"] button');
        // Regular user: overview, quality, pm-activity, designer-activity, efficiency, adoption, activity = 7
        expect(tabs.length).toBe(7);
    });

    it('shows correct tab count for admin users', () => {
        setUpAdminUser();
        const wrapper = mountDashboard();

        const tabs = wrapper.findAll('[data-testid="dashboard-view-tabs"] button');
        // Admin: overview, quality, pm-activity, designer-activity, efficiency, adoption, cost, infrastructure, activity = 9
        expect(tabs.length).toBe(9);
    });
});

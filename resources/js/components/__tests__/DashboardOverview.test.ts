import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useDashboardStore } from '@/stores/dashboard';
import DashboardOverview from '../DashboardOverview.vue';

let pinia: ReturnType<typeof createPinia>;

const RouterLinkStub = { template: '<a :href="to" :data-testid="$attrs[\'data-testid\']"><slot /></a>', props: ['to'] };

function mountOverview() {
    return mount(DashboardOverview, {
        global: {
            plugins: [pinia],
            stubs: { RouterLink: RouterLinkStub },
        },
    });
}

const sampleOverview = {
    tasks_by_type: {
        code_review: 15,
        feature_dev: 8,
        ui_adjustment: 3,
        prd_creation: 5,
    },
    active_tasks: 2,
    success_rate: 87.5,
    total_completed: 21,
    total_failed: 3,
    recent_activity: new Date().toISOString(),
};

const allZerosOverview = {
    tasks_by_type: {
        code_review: 0,
        feature_dev: 0,
        ui_adjustment: 0,
        prd_creation: 0,
    },
    active_tasks: 0,
    success_rate: null,
    total_completed: 0,
    total_failed: 0,
    recent_activity: null,
};

describe('dashboardOverview', () => {
    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
    });

    // -- Container --

    it('renders the overview container', () => {
        const store = useDashboardStore();
        store.overview = sampleOverview;
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="dashboard-overview"]').exists()).toBe(true);
    });

    // -- Loading state --

    it('shows loading indicator when loading and no data', () => {
        const store = useDashboardStore();
        store.overviewLoading = true;
        store.overview = null;
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="overview-loading"]').exists()).toBe(true);
    });

    it('hides loading indicator when data is present', () => {
        const store = useDashboardStore();
        store.overviewLoading = true;
        store.overview = sampleOverview;
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="overview-loading"]').exists()).toBe(false);
    });

    // -- Error / empty state (null overview) --

    it('shows error empty state when not loading and no data', () => {
        const store = useDashboardStore();
        store.overviewLoading = false;
        store.overview = null;
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="overview-empty"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Unable to load overview');
    });

    it('shows retry button in error state', () => {
        const store = useDashboardStore();
        store.overviewLoading = false;
        store.overview = null;
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="retry-btn"]').exists()).toBe(true);
    });

    it('calls fetchOverview when retry button is clicked', async () => {
        const store = useDashboardStore();
        store.overviewLoading = false;
        store.overview = null;
        const fetchSpy = vi.spyOn(store, 'fetchOverview').mockResolvedValue();
        const wrapper = mountOverview();
        await wrapper.find('[data-testid="retry-btn"]').trigger('click');
        expect(fetchSpy).toHaveBeenCalled();
    });

    // -- Onboarding state (all zeros) --

    it('shows onboarding state when all values are zero', () => {
        const store = useDashboardStore();
        store.overview = allZerosOverview;
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="overview-onboarding"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Welcome to Vunnix');
    });

    it('shows admin and chat links in onboarding state', () => {
        const store = useDashboardStore();
        store.overview = allZerosOverview;
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="onboarding-admin-link"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="onboarding-chat-link"]').exists()).toBe(true);
    });

    it('does not show onboarding when there is real data', () => {
        const store = useDashboardStore();
        store.overview = sampleOverview;
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="overview-onboarding"]').exists()).toBe(false);
    });

    // -- Active tasks card --

    it('displays active tasks count', () => {
        const store = useDashboardStore();
        store.overview = sampleOverview;
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="active-tasks-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="active-tasks-count"]').text()).toBe('2');
    });

    it('shows in-progress indicator when active tasks exist', () => {
        const store = useDashboardStore();
        store.overview = sampleOverview;
        const wrapper = mountOverview();
        const card = wrapper.find('[data-testid="active-tasks-card"]');
        expect(card.text()).toContain('In progress');
    });

    it('hides in-progress indicator when no active tasks', () => {
        const store = useDashboardStore();
        store.overview = { ...sampleOverview, active_tasks: 0 };
        const wrapper = mountOverview();
        const card = wrapper.find('[data-testid="active-tasks-card"]');
        expect(card.text()).not.toContain('In progress');
    });

    // -- Success rate card --

    it('displays success rate percentage', () => {
        const store = useDashboardStore();
        store.overview = sampleOverview;
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="success-rate-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="success-rate-value"]').text()).toBe('87.5%');
    });

    it('displays dash when success rate is null', () => {
        const store = useDashboardStore();
        store.overview = { ...sampleOverview, success_rate: null };
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="success-rate-value"]').text()).toBe('—');
    });

    it('displays completed and failed counts', () => {
        const store = useDashboardStore();
        store.overview = sampleOverview;
        const wrapper = mountOverview();
        const card = wrapper.find('[data-testid="success-rate-card"]');
        expect(card.text()).toContain('21 completed');
        expect(card.text()).toContain('3 failed');
    });

    // -- Recent activity card --

    it('displays recent activity card', () => {
        const store = useDashboardStore();
        store.overview = sampleOverview;
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="recent-activity-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="recent-activity-value"]').text()).toBe('Just now');
    });

    it('displays No activity when recent_activity is null', () => {
        const store = useDashboardStore();
        store.overview = { ...sampleOverview, recent_activity: null };
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="recent-activity-value"]').text()).toBe('No activity');
    });

    // -- Tasks by type cards --

    it('renders all four type cards', () => {
        const store = useDashboardStore();
        store.overview = sampleOverview;
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="type-card-code_review"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="type-card-feature_dev"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="type-card-ui_adjustment"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="type-card-prd_creation"]').exists()).toBe(true);
    });

    it('displays correct counts per type', () => {
        const store = useDashboardStore();
        store.overview = sampleOverview;
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="type-count-code_review"]').text()).toBe('15');
        expect(wrapper.find('[data-testid="type-count-feature_dev"]').text()).toBe('8');
        expect(wrapper.find('[data-testid="type-count-ui_adjustment"]').text()).toBe('3');
        expect(wrapper.find('[data-testid="type-count-prd_creation"]').text()).toBe('5');
    });

    it('displays type labels', () => {
        const store = useDashboardStore();
        store.overview = sampleOverview;
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="type-card-code_review"]').text()).toContain('Code Reviews');
        expect(wrapper.find('[data-testid="type-card-feature_dev"]').text()).toContain('Feature Dev');
        expect(wrapper.find('[data-testid="type-card-ui_adjustment"]').text()).toContain('UI Adjustments');
        expect(wrapper.find('[data-testid="type-card-prd_creation"]').text()).toContain('PRDs');
    });

    // -- Zero counts (with some completed tasks — not onboarding) --

    it('displays zero type counts when overview has completed tasks but zero type breakdown', () => {
        const store = useDashboardStore();
        store.overview = {
            ...sampleOverview,
            tasks_by_type: {
                code_review: 0,
                feature_dev: 0,
                ui_adjustment: 0,
                prd_creation: 0,
            },
            active_tasks: 0,
        };
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="active-tasks-count"]').text()).toBe('0');
        expect(wrapper.find('[data-testid="type-count-code_review"]').text()).toBe('0');
    });

    // -- Relative time formatting --

    it('displays minutes ago for recent_activity within the last hour', () => {
        const store = useDashboardStore();
        const tenMinutesAgo = new Date(Date.now() - 10 * 60 * 1000).toISOString();
        store.overview = { ...sampleOverview, recent_activity: tenMinutesAgo };
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="recent-activity-value"]').text()).toBe('10m ago');
    });

    it('displays hours ago for recent_activity within the last day', () => {
        const store = useDashboardStore();
        const threeHoursAgo = new Date(Date.now() - 3 * 60 * 60 * 1000).toISOString();
        store.overview = { ...sampleOverview, recent_activity: threeHoursAgo };
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="recent-activity-value"]').text()).toBe('3h ago');
    });

    it('displays days ago for recent_activity within the last 30 days', () => {
        const store = useDashboardStore();
        const fiveDaysAgo = new Date(Date.now() - 5 * 24 * 60 * 60 * 1000).toISOString();
        store.overview = { ...sampleOverview, recent_activity: fiveDaysAgo };
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="recent-activity-value"]').text()).toBe('5d ago');
    });

    it('displays formatted date for recent_activity older than 30 days', () => {
        const store = useDashboardStore();
        const sixtyDaysAgo = new Date(Date.now() - 60 * 24 * 60 * 60 * 1000);
        store.overview = { ...sampleOverview, recent_activity: sixtyDaysAgo.toISOString() };
        const wrapper = mountOverview();
        const displayed = wrapper.find('[data-testid="recent-activity-value"]').text();
        // toLocaleDateString() produces locale-dependent output; just verify it's not a relative format
        expect(displayed).not.toContain('ago');
        expect(displayed).not.toBe('Just now');
        expect(displayed).not.toBe('No activity');
        expect(displayed).toBe(sixtyDaysAgo.toLocaleDateString());
    });

    // -- Success rate edge values --

    it('displays 0% success rate', () => {
        const store = useDashboardStore();
        store.overview = { ...sampleOverview, success_rate: 0 };
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="success-rate-value"]').text()).toBe('0%');
    });

    it('displays 50% success rate', () => {
        const store = useDashboardStore();
        store.overview = { ...sampleOverview, success_rate: 50 };
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="success-rate-value"]').text()).toBe('50%');
    });

    it('displays 100% success rate', () => {
        const store = useDashboardStore();
        store.overview = { ...sampleOverview, success_rate: 100 };
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="success-rate-value"]').text()).toBe('100%');
    });

    // -- Edge cases for missing type counts --

    it('displays 0 when tasks_by_type is missing a key', () => {
        const store = useDashboardStore();
        store.overview = {
            ...sampleOverview,
            tasks_by_type: { code_review: 7 }, // missing feature_dev, ui_adjustment, prd_creation
        };
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="type-count-code_review"]').text()).toBe('7');
        expect(wrapper.find('[data-testid="type-count-feature_dev"]').text()).toBe('0');
        expect(wrapper.find('[data-testid="type-count-ui_adjustment"]').text()).toBe('0');
        expect(wrapper.find('[data-testid="type-count-prd_creation"]').text()).toBe('0');
    });
});

import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it } from 'vitest';
import { useDashboardStore } from '@/stores/dashboard';
import DashboardOverview from './DashboardOverview.vue';

let pinia: ReturnType<typeof createPinia>;

function mountOverview() {
    return mount(DashboardOverview, {
        global: {
            plugins: [pinia],
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

    // -- Empty state --

    it('shows empty state when not loading and no data', () => {
        const store = useDashboardStore();
        store.overviewLoading = false;
        store.overview = null;
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="overview-empty"]').exists()).toBe(true);
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

    // -- Zero counts --

    it('displays zero counts correctly', () => {
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
            total_completed: 0,
            total_failed: 0,
        };
        const wrapper = mountOverview();
        expect(wrapper.find('[data-testid="active-tasks-count"]').text()).toBe('0');
        expect(wrapper.find('[data-testid="type-count-code_review"]').text()).toBe('0');
    });
});

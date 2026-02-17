import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useDashboardStore } from '@/stores/dashboard';
import DashboardDesignerActivity from './DashboardDesignerActivity.vue';

vi.mock('axios', () => ({
    default: {
        get: vi.fn().mockResolvedValue({ data: { data: null } }),
    },
}));

let pinia;

function mountDesignerActivity() {
    return mount(DashboardDesignerActivity, {
        global: {
            plugins: [pinia],
        },
    });
}

const sampleDesignerActivity = {
    ui_adjustments_dispatched: 15,
    avg_iterations: 1.8,
    mrs_created_from_chat: 12,
    first_attempt_success_rate: 73.3,
};

describe('dashboardDesignerActivity', () => {
    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
    });

    // -- Container --

    it('renders the designer activity container', () => {
        const store = useDashboardStore();
        store.designerActivity = sampleDesignerActivity;
        const wrapper = mountDesignerActivity();
        expect(wrapper.find('[data-testid="dashboard-designer-activity"]').exists()).toBe(true);
    });

    // -- Loading state --

    it('shows loading indicator when loading and no data', () => {
        const store = useDashboardStore();
        store.designerActivityLoading = true;
        store.designerActivity = null;
        const wrapper = mountDesignerActivity();
        expect(wrapper.find('[data-testid="designer-activity-loading"]').exists()).toBe(true);
    });

    it('hides loading indicator when data is present', () => {
        const store = useDashboardStore();
        store.designerActivityLoading = true;
        store.designerActivity = sampleDesignerActivity;
        const wrapper = mountDesignerActivity();
        expect(wrapper.find('[data-testid="designer-activity-loading"]').exists()).toBe(false);
    });

    // -- Empty state --

    it('shows empty state when not loading and no data', () => {
        const store = useDashboardStore();
        store.designerActivityLoading = false;
        store.designerActivity = null;
        const wrapper = mountDesignerActivity();
        expect(wrapper.find('[data-testid="designer-activity-empty"]').exists()).toBe(true);
    });

    // -- UI Adjustments card --

    it('displays UI adjustments count', () => {
        const store = useDashboardStore();
        store.designerActivity = sampleDesignerActivity;
        const wrapper = mountDesignerActivity();
        expect(wrapper.find('[data-testid="ui-adjustments-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="ui-adjustments-value"]').text()).toBe('15');
    });

    // -- Avg Iterations card --

    it('displays avg iterations', () => {
        const store = useDashboardStore();
        store.designerActivity = sampleDesignerActivity;
        const wrapper = mountDesignerActivity();
        expect(wrapper.find('[data-testid="avg-iterations-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="avg-iterations-value"]').text()).toBe('1.8');
    });

    it('displays dash when avg iterations is null', () => {
        const store = useDashboardStore();
        store.designerActivity = { ...sampleDesignerActivity, avg_iterations: null };
        const wrapper = mountDesignerActivity();
        expect(wrapper.find('[data-testid="avg-iterations-value"]').text()).toBe('—');
    });

    it('shows "No adjustments yet" message when avg iterations is null', () => {
        const store = useDashboardStore();
        store.designerActivity = { ...sampleDesignerActivity, avg_iterations: null };
        const wrapper = mountDesignerActivity();
        const card = wrapper.find('[data-testid="avg-iterations-card"]');
        expect(card.text()).toContain('No adjustments yet');
    });

    // -- MRs from Chat card --

    it('displays MRs from chat count', () => {
        const store = useDashboardStore();
        store.designerActivity = sampleDesignerActivity;
        const wrapper = mountDesignerActivity();
        expect(wrapper.find('[data-testid="mrs-from-chat-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="mrs-from-chat-value"]').text()).toBe('12');
    });

    // -- First-Attempt Success Rate card --

    it('displays first-attempt success rate with percent sign', () => {
        const store = useDashboardStore();
        store.designerActivity = sampleDesignerActivity;
        const wrapper = mountDesignerActivity();
        expect(wrapper.find('[data-testid="success-rate-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="success-rate-value"]').text()).toBe('73.3%');
    });

    it('displays dash when success rate is null', () => {
        const store = useDashboardStore();
        store.designerActivity = { ...sampleDesignerActivity, first_attempt_success_rate: null };
        const wrapper = mountDesignerActivity();
        expect(wrapper.find('[data-testid="success-rate-value"]').text()).toBe('—');
    });

    it('shows "No adjustments yet" message when success rate is null', () => {
        const store = useDashboardStore();
        store.designerActivity = { ...sampleDesignerActivity, first_attempt_success_rate: null };
        const wrapper = mountDesignerActivity();
        const card = wrapper.find('[data-testid="success-rate-card"]');
        expect(card.text()).toContain('No adjustments yet');
    });

    // -- Zero counts --

    it('displays zero counts correctly', () => {
        const store = useDashboardStore();
        store.designerActivity = {
            ui_adjustments_dispatched: 0,
            avg_iterations: null,
            mrs_created_from_chat: 0,
            first_attempt_success_rate: null,
        };
        const wrapper = mountDesignerActivity();
        expect(wrapper.find('[data-testid="ui-adjustments-value"]').text()).toBe('0');
        expect(wrapper.find('[data-testid="avg-iterations-value"]').text()).toBe('—');
        expect(wrapper.find('[data-testid="mrs-from-chat-value"]').text()).toBe('0');
        expect(wrapper.find('[data-testid="success-rate-value"]').text()).toBe('—');
    });
});

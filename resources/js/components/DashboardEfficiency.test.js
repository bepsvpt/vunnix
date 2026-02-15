import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import DashboardEfficiency from './DashboardEfficiency.vue';
import { useDashboardStore } from '@/stores/dashboard';

vi.mock('axios', () => ({
    default: {
        get: vi.fn().mockResolvedValue({ data: { data: null } }),
    },
}));

let pinia;

function mountEfficiency() {
    return mount(DashboardEfficiency, {
        global: {
            plugins: [pinia],
        },
    });
}

const sampleEfficiency = {
    time_to_first_review: 3.2,
    review_turnaround: 7.5,
    completion_rate_by_type: {
        code_review: 95,
        feature_dev: 80,
        ui_adjustment: 100,
    },
};

describe('DashboardEfficiency', () => {
    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
    });

    // -- Container --

    it('renders the efficiency container', () => {
        const store = useDashboardStore();
        store.efficiency = sampleEfficiency;
        const wrapper = mountEfficiency();
        expect(wrapper.find('[data-testid="dashboard-efficiency"]').exists()).toBe(true);
    });

    // -- Loading state --

    it('shows loading indicator when loading and no data', () => {
        const store = useDashboardStore();
        store.efficiencyLoading = true;
        store.efficiency = null;
        const wrapper = mountEfficiency();
        expect(wrapper.find('[data-testid="efficiency-loading"]').exists()).toBe(true);
    });

    it('hides loading indicator when data is present', () => {
        const store = useDashboardStore();
        store.efficiencyLoading = true;
        store.efficiency = sampleEfficiency;
        const wrapper = mountEfficiency();
        expect(wrapper.find('[data-testid="efficiency-loading"]').exists()).toBe(false);
    });

    // -- Empty state --

    it('shows empty state when not loading and no data', () => {
        const store = useDashboardStore();
        store.efficiencyLoading = false;
        store.efficiency = null;
        const wrapper = mountEfficiency();
        expect(wrapper.find('[data-testid="efficiency-empty"]').exists()).toBe(true);
    });

    // -- Time to first review card --

    it('displays time to first review with minutes unit', () => {
        const store = useDashboardStore();
        store.efficiency = sampleEfficiency;
        const wrapper = mountEfficiency();
        expect(wrapper.find('[data-testid="time-to-first-review-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="time-to-first-review-value"]').text()).toBe('3.2 min');
    });

    it('displays dash when time to first review is null', () => {
        const store = useDashboardStore();
        store.efficiency = { ...sampleEfficiency, time_to_first_review: null };
        const wrapper = mountEfficiency();
        expect(wrapper.find('[data-testid="time-to-first-review-value"]').text()).toBe('—');
    });

    it('shows no completed reviews message when time is null', () => {
        const store = useDashboardStore();
        store.efficiency = { ...sampleEfficiency, time_to_first_review: null };
        const wrapper = mountEfficiency();
        const card = wrapper.find('[data-testid="time-to-first-review-card"]');
        expect(card.text()).toContain('No completed reviews yet');
    });

    // -- Review turnaround card --

    it('displays review turnaround with minutes unit', () => {
        const store = useDashboardStore();
        store.efficiency = sampleEfficiency;
        const wrapper = mountEfficiency();
        expect(wrapper.find('[data-testid="review-turnaround-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="review-turnaround-value"]').text()).toBe('7.5 min');
    });

    it('displays dash when review turnaround is null', () => {
        const store = useDashboardStore();
        store.efficiency = { ...sampleEfficiency, review_turnaround: null };
        const wrapper = mountEfficiency();
        expect(wrapper.find('[data-testid="review-turnaround-value"]').text()).toBe('—');
    });

    it('shows no completed reviews message when turnaround is null', () => {
        const store = useDashboardStore();
        store.efficiency = { ...sampleEfficiency, review_turnaround: null };
        const wrapper = mountEfficiency();
        const card = wrapper.find('[data-testid="review-turnaround-card"]');
        expect(card.text()).toContain('No completed reviews yet');
    });

    // -- Completion rate by type --

    it('renders completion rate by type section', () => {
        const store = useDashboardStore();
        store.efficiency = sampleEfficiency;
        const wrapper = mountEfficiency();
        expect(wrapper.find('[data-testid="completion-rate-by-type"]').exists()).toBe(true);
    });

    it('displays completion rate for each type', () => {
        const store = useDashboardStore();
        store.efficiency = sampleEfficiency;
        const wrapper = mountEfficiency();
        expect(wrapper.find('[data-testid="completion-rate-code_review-value"]').text()).toBe('95%');
        expect(wrapper.find('[data-testid="completion-rate-feature_dev-value"]').text()).toBe('80%');
        expect(wrapper.find('[data-testid="completion-rate-ui_adjustment-value"]').text()).toBe('100%');
    });

    it('displays human-readable type labels', () => {
        const store = useDashboardStore();
        store.efficiency = sampleEfficiency;
        const wrapper = mountEfficiency();
        const codeReview = wrapper.find('[data-testid="completion-rate-code_review"]');
        expect(codeReview.text()).toContain('Code Review');
        const featureDev = wrapper.find('[data-testid="completion-rate-feature_dev"]');
        expect(featureDev.text()).toContain('Feature Dev');
    });

    it('shows empty message when no completion rate data', () => {
        const store = useDashboardStore();
        store.efficiency = { ...sampleEfficiency, completion_rate_by_type: {} };
        const wrapper = mountEfficiency();
        expect(wrapper.find('[data-testid="completion-rate-empty"]').exists()).toBe(true);
    });

    // -- Integer display --

    it('displays integer time values without decimals', () => {
        const store = useDashboardStore();
        store.efficiency = { ...sampleEfficiency, time_to_first_review: 5, review_turnaround: 10 };
        const wrapper = mountEfficiency();
        expect(wrapper.find('[data-testid="time-to-first-review-value"]').text()).toBe('5 min');
        expect(wrapper.find('[data-testid="review-turnaround-value"]').text()).toBe('10 min');
    });
});

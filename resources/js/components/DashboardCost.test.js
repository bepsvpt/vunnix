import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import DashboardCost from './DashboardCost.vue';
import { useDashboardStore } from '@/stores/dashboard';

vi.mock('axios', () => ({
    default: {
        get: vi.fn().mockResolvedValue({ data: { data: null } }),
    },
}));

let pinia;

function mountCost() {
    return mount(DashboardCost, {
        global: {
            plugins: [pinia],
        },
    });
}

const sampleCost = {
    total_cost: 42.5,
    total_tokens: 1500000,
    token_usage_by_type: {
        code_review: 800000,
        feature_dev: 500000,
        ui_adjustment: 200000,
    },
    cost_per_type: {
        code_review: { avg_cost: 1.2, total_cost: 24.0, task_count: 20 },
        feature_dev: { avg_cost: 3.5, total_cost: 14.0, task_count: 4 },
        ui_adjustment: { avg_cost: 1.125, total_cost: 4.5, task_count: 4 },
    },
    cost_per_project: [
        { project_id: 1, project_name: 'Project Alpha', total_cost: 30.0, task_count: 18 },
        { project_id: 2, project_name: 'Project Beta', total_cost: 12.5, task_count: 10 },
    ],
    monthly_trend: [
        { month: '2026-01', total_cost: 20.0, total_tokens: 700000, task_count: 15 },
        { month: '2026-02', total_cost: 22.5, total_tokens: 800000, task_count: 13 },
    ],
};

describe('DashboardCost', () => {
    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
    });

    // -- Container --

    it('renders the cost container', () => {
        const store = useDashboardStore();
        store.cost = sampleCost;
        const wrapper = mountCost();
        expect(wrapper.find('[data-testid="dashboard-cost"]').exists()).toBe(true);
    });

    // -- Loading state --

    it('shows loading indicator when loading and no data', () => {
        const store = useDashboardStore();
        store.costLoading = true;
        store.cost = null;
        const wrapper = mountCost();
        expect(wrapper.find('[data-testid="cost-loading"]').exists()).toBe(true);
    });

    it('hides loading indicator when data is present', () => {
        const store = useDashboardStore();
        store.costLoading = true;
        store.cost = sampleCost;
        const wrapper = mountCost();
        expect(wrapper.find('[data-testid="cost-loading"]').exists()).toBe(false);
    });

    // -- Empty state --

    it('shows empty state when not loading and no data', () => {
        const store = useDashboardStore();
        store.costLoading = false;
        store.cost = null;
        const wrapper = mountCost();
        expect(wrapper.find('[data-testid="cost-empty"]').exists()).toBe(true);
    });

    // -- Total cost card --

    it('displays total cost formatted as currency', () => {
        const store = useDashboardStore();
        store.cost = sampleCost;
        const wrapper = mountCost();
        expect(wrapper.find('[data-testid="total-cost-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="total-cost-value"]').text()).toBe('$42.50');
    });

    // -- Total tokens card --

    it('displays total tokens with locale formatting', () => {
        const store = useDashboardStore();
        store.cost = sampleCost;
        const wrapper = mountCost();
        expect(wrapper.find('[data-testid="total-tokens-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="total-tokens-value"]').text()).toBe('1,500,000');
    });

    // -- Token usage by type --

    it('renders token usage by type section', () => {
        const store = useDashboardStore();
        store.cost = sampleCost;
        const wrapper = mountCost();
        expect(wrapper.find('[data-testid="token-usage-by-type"]').exists()).toBe(true);
    });

    it('displays token usage for each type', () => {
        const store = useDashboardStore();
        store.cost = sampleCost;
        const wrapper = mountCost();
        expect(wrapper.find('[data-testid="token-usage-code_review-value"]').text()).toBe('800,000');
        expect(wrapper.find('[data-testid="token-usage-feature_dev-value"]').text()).toBe('500,000');
        expect(wrapper.find('[data-testid="token-usage-ui_adjustment-value"]').text()).toBe('200,000');
    });

    it('displays human-readable type labels for token usage', () => {
        const store = useDashboardStore();
        store.cost = sampleCost;
        const wrapper = mountCost();
        const codeReview = wrapper.find('[data-testid="token-usage-code_review"]');
        expect(codeReview.text()).toContain('Code Review');
    });

    it('shows empty message when no token usage data', () => {
        const store = useDashboardStore();
        store.cost = { ...sampleCost, token_usage_by_type: {} };
        const wrapper = mountCost();
        expect(wrapper.find('[data-testid="token-usage-empty"]').exists()).toBe(true);
    });

    // -- Cost per type table --

    it('renders cost per type table', () => {
        const store = useDashboardStore();
        store.cost = sampleCost;
        const wrapper = mountCost();
        expect(wrapper.find('[data-testid="cost-per-type"]').exists()).toBe(true);
    });

    it('displays cost per type row for code review', () => {
        const store = useDashboardStore();
        store.cost = sampleCost;
        const wrapper = mountCost();
        const row = wrapper.find('[data-testid="cost-type-code_review"]');
        expect(row.exists()).toBe(true);
        expect(row.text()).toContain('Code Review');
        expect(row.text()).toContain('$1.2000');
        expect(row.text()).toContain('$24.00');
        expect(row.text()).toContain('20');
    });

    it('shows empty message when no cost per type data', () => {
        const store = useDashboardStore();
        store.cost = { ...sampleCost, cost_per_type: {} };
        const wrapper = mountCost();
        expect(wrapper.find('[data-testid="cost-per-type-empty"]').exists()).toBe(true);
    });

    // -- Cost per project table --

    it('renders cost per project table', () => {
        const store = useDashboardStore();
        store.cost = sampleCost;
        const wrapper = mountCost();
        expect(wrapper.find('[data-testid="cost-per-project"]').exists()).toBe(true);
    });

    it('displays cost per project rows', () => {
        const store = useDashboardStore();
        store.cost = sampleCost;
        const wrapper = mountCost();
        const alphaRow = wrapper.find('[data-testid="cost-project-1"]');
        expect(alphaRow.exists()).toBe(true);
        expect(alphaRow.text()).toContain('Project Alpha');
        expect(alphaRow.text()).toContain('$30.00');

        const betaRow = wrapper.find('[data-testid="cost-project-2"]');
        expect(betaRow.exists()).toBe(true);
        expect(betaRow.text()).toContain('Project Beta');
        expect(betaRow.text()).toContain('$12.50');
    });

    it('shows empty message when no cost per project data', () => {
        const store = useDashboardStore();
        store.cost = { ...sampleCost, cost_per_project: [] };
        const wrapper = mountCost();
        expect(wrapper.find('[data-testid="cost-per-project-empty"]').exists()).toBe(true);
    });

    // -- Monthly trend table --

    it('renders monthly trend table', () => {
        const store = useDashboardStore();
        store.cost = sampleCost;
        const wrapper = mountCost();
        expect(wrapper.find('[data-testid="monthly-trend"]').exists()).toBe(true);
    });

    it('displays monthly trend rows', () => {
        const store = useDashboardStore();
        store.cost = sampleCost;
        const wrapper = mountCost();
        const janRow = wrapper.find('[data-testid="trend-2026-01"]');
        expect(janRow.exists()).toBe(true);
        expect(janRow.text()).toContain('2026-01');
        expect(janRow.text()).toContain('$20.00');

        const febRow = wrapper.find('[data-testid="trend-2026-02"]');
        expect(febRow.exists()).toBe(true);
        expect(febRow.text()).toContain('2026-02');
        expect(febRow.text()).toContain('$22.50');
    });

    it('shows empty message when no monthly trend data', () => {
        const store = useDashboardStore();
        store.cost = { ...sampleCost, monthly_trend: [] };
        const wrapper = mountCost();
        expect(wrapper.find('[data-testid="monthly-trend-empty"]').exists()).toBe(true);
    });

    // -- Zero values --

    it('displays $0.00 for zero total cost', () => {
        const store = useDashboardStore();
        store.cost = { ...sampleCost, total_cost: 0 };
        const wrapper = mountCost();
        expect(wrapper.find('[data-testid="total-cost-value"]').text()).toBe('$0.00');
    });

    it('displays 0 for zero total tokens', () => {
        const store = useDashboardStore();
        store.cost = { ...sampleCost, total_tokens: 0 };
        const wrapper = mountCost();
        expect(wrapper.find('[data-testid="total-tokens-value"]').text()).toBe('0');
    });
});

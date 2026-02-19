import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useDashboardStore } from '@/stores/dashboard';
import DashboardAdoption from './DashboardAdoption.vue';

vi.mock('axios', () => ({
    default: {
        get: vi.fn().mockResolvedValue({ data: { data: null } }),
    },
}));

let pinia: ReturnType<typeof createPinia>;

function mountAdoption() {
    return mount(DashboardAdoption, {
        global: {
            plugins: [pinia],
        },
    });
}

const sampleAdoption = {
    ai_reviewed_mr_percent: 75.5,
    reviewed_mr_count: 30,
    total_mr_count: 40,
    chat_active_users: 12,
    tasks_by_type_over_time: {
        '2026-01': { code_review: 15, feature_dev: 5, ui_adjustment: 3 },
        '2026-02': { code_review: 20, feature_dev: 8 },
    },
    ai_mentions_per_week: [
        { week: '2026-W05', count: 12 },
        { week: '2026-W06', count: 18 },
    ],
};

describe('dashboardAdoption', () => {
    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
    });

    // -- Container --

    it('renders the adoption container', () => {
        const store = useDashboardStore();
        store.adoption = sampleAdoption;
        const wrapper = mountAdoption();
        expect(wrapper.find('[data-testid="dashboard-adoption"]').exists()).toBe(true);
    });

    // -- Loading state --

    it('shows loading indicator when loading and no data', () => {
        const store = useDashboardStore();
        store.adoptionLoading = true;
        store.adoption = null;
        const wrapper = mountAdoption();
        expect(wrapper.find('[data-testid="adoption-loading"]').exists()).toBe(true);
    });

    it('hides loading indicator when data is present', () => {
        const store = useDashboardStore();
        store.adoptionLoading = true;
        store.adoption = sampleAdoption;
        const wrapper = mountAdoption();
        expect(wrapper.find('[data-testid="adoption-loading"]').exists()).toBe(false);
    });

    // -- Empty state --

    it('shows empty state when not loading and no data', () => {
        const store = useDashboardStore();
        store.adoptionLoading = false;
        store.adoption = null;
        const wrapper = mountAdoption();
        expect(wrapper.find('[data-testid="adoption-empty"]').exists()).toBe(true);
    });

    // -- AI-Reviewed MR % card --

    it('displays AI-reviewed MR percentage', () => {
        const store = useDashboardStore();
        store.adoption = sampleAdoption;
        const wrapper = mountAdoption();
        expect(wrapper.find('[data-testid="ai-reviewed-mr-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="ai-reviewed-mr-value"]').text()).toBe('75.5%');
    });

    it('displays MR count context', () => {
        const store = useDashboardStore();
        store.adoption = sampleAdoption;
        const wrapper = mountAdoption();
        expect(wrapper.find('[data-testid="ai-reviewed-mr-count"]').text()).toBe('30 of 40 MRs');
    });

    it('displays dash when MR percent is null', () => {
        const store = useDashboardStore();
        store.adoption = { ...sampleAdoption, ai_reviewed_mr_percent: null };
        const wrapper = mountAdoption();
        expect(wrapper.find('[data-testid="ai-reviewed-mr-value"]').text()).toBe('—');
    });

    it('shows no merge requests message when MR percent is null', () => {
        const store = useDashboardStore();
        store.adoption = { ...sampleAdoption, ai_reviewed_mr_percent: null };
        const wrapper = mountAdoption();
        expect(wrapper.find('[data-testid="ai-reviewed-mr-count"]').text()).toContain('No merge requests yet');
    });

    // -- Chat Active Users card --

    it('displays chat active users count', () => {
        const store = useDashboardStore();
        store.adoption = sampleAdoption;
        const wrapper = mountAdoption();
        expect(wrapper.find('[data-testid="chat-active-users-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="chat-active-users-value"]').text()).toBe('12');
    });

    // -- Tasks by type over time --

    it('renders tasks by type over time table', () => {
        const store = useDashboardStore();
        store.adoption = sampleAdoption;
        const wrapper = mountAdoption();
        expect(wrapper.find('[data-testid="tasks-by-type-over-time"]').exists()).toBe(true);
    });

    it('displays month rows with type counts', () => {
        const store = useDashboardStore();
        store.adoption = sampleAdoption;
        const wrapper = mountAdoption();
        const janRow = wrapper.find('[data-testid="tasks-month-2026-01"]');
        expect(janRow.exists()).toBe(true);
        expect(janRow.text()).toContain('2026-01');
        expect(janRow.text()).toContain('15');

        const febRow = wrapper.find('[data-testid="tasks-month-2026-02"]');
        expect(febRow.exists()).toBe(true);
        expect(febRow.text()).toContain('2026-02');
        expect(febRow.text()).toContain('20');
    });

    it('shows empty message when no tasks over time data', () => {
        const store = useDashboardStore();
        store.adoption = { ...sampleAdoption, tasks_by_type_over_time: {} };
        const wrapper = mountAdoption();
        expect(wrapper.find('[data-testid="tasks-by-type-empty"]').exists()).toBe(true);
    });

    // -- @ai mentions per week --

    it('renders ai mentions per week table', () => {
        const store = useDashboardStore();
        store.adoption = sampleAdoption;
        const wrapper = mountAdoption();
        expect(wrapper.find('[data-testid="ai-mentions-per-week"]').exists()).toBe(true);
    });

    it('displays weekly mention rows', () => {
        const store = useDashboardStore();
        store.adoption = sampleAdoption;
        const wrapper = mountAdoption();
        const w5Row = wrapper.find('[data-testid="mentions-2026-W05"]');
        expect(w5Row.exists()).toBe(true);
        expect(w5Row.text()).toContain('2026-W05');
        expect(w5Row.text()).toContain('12');

        const w6Row = wrapper.find('[data-testid="mentions-2026-W06"]');
        expect(w6Row.exists()).toBe(true);
        expect(w6Row.text()).toContain('2026-W06');
        expect(w6Row.text()).toContain('18');
    });

    it('shows empty message when no mention data', () => {
        const store = useDashboardStore();
        store.adoption = { ...sampleAdoption, ai_mentions_per_week: [] };
        const wrapper = mountAdoption();
        expect(wrapper.find('[data-testid="ai-mentions-empty"]').exists()).toBe(true);
    });

    // -- Zero values --

    it('displays 0 for zero active users', () => {
        const store = useDashboardStore();
        store.adoption = { ...sampleAdoption, chat_active_users: 0 };
        const wrapper = mountAdoption();
        expect(wrapper.find('[data-testid="chat-active-users-value"]').text()).toBe('0');
    });

    it('displays 100% when all MRs are reviewed', () => {
        const store = useDashboardStore();
        store.adoption = { ...sampleAdoption, ai_reviewed_mr_percent: 100.0 };
        const wrapper = mountAdoption();
        expect(wrapper.find('[data-testid="ai-reviewed-mr-value"]').text()).toBe('100%');
    });

    it('computes fallback displays when adoption payload is missing', () => {
        const store = useDashboardStore();
        store.adoption = null;
        const wrapper = mountAdoption();

        const vm = wrapper.vm as unknown as {
            mrCountDisplay: string;
            activeUsersDisplay: string;
        };

        expect(vm.mrCountDisplay).toBe('');
        expect(vm.activeUsersDisplay).toBe('—');
    });

    it('returns empty arrays when optional adoption datasets are absent', () => {
        const store = useDashboardStore();
        store.adoption = {
            ai_reviewed_mr_percent: 50,
            reviewed_mr_count: 1,
            total_mr_count: 2,
            chat_active_users: 1,
            tasks_by_type_over_time: null,
            ai_mentions_per_week: null,
        } as unknown as typeof sampleAdoption;

        const wrapper = mountAdoption();
        const vm = wrapper.vm as unknown as {
            tasksByTypeMonths: unknown[];
            allTypeKeys: unknown[];
            aiMentions: unknown[];
        };

        expect(vm.tasksByTypeMonths).toEqual([]);
        expect(vm.allTypeKeys).toEqual([]);
        expect(vm.aiMentions).toEqual([]);
    });
});

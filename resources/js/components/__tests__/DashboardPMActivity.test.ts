import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useDashboardStore } from '@/features/dashboard';
import DashboardPMActivity from '../DashboardPMActivity.vue';

vi.mock('axios', () => ({
    default: {
        get: vi.fn().mockResolvedValue({ data: { data: null } }),
    },
}));

let pinia: ReturnType<typeof createPinia>;

function mountPMActivity() {
    return mount(DashboardPMActivity, {
        global: {
            plugins: [pinia],
        },
    });
}

const samplePMActivity = {
    prds_created: 5,
    conversations_held: 42,
    issues_from_chat: 12,
    avg_turns_per_prd: 7.3,
};

describe('dashboardPMActivity', () => {
    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
    });

    // -- Container --

    it('renders the PM activity container', () => {
        const store = useDashboardStore();
        store.pmActivity = samplePMActivity;
        const wrapper = mountPMActivity();
        expect(wrapper.find('[data-testid="dashboard-pm-activity"]').exists()).toBe(true);
    });

    // -- Loading state --

    it('shows loading indicator when loading and no data', () => {
        const store = useDashboardStore();
        store.pmActivityLoading = true;
        store.pmActivity = null;
        const wrapper = mountPMActivity();
        expect(wrapper.find('[data-testid="pm-activity-loading"]').exists()).toBe(true);
    });

    it('hides loading indicator when data is present', () => {
        const store = useDashboardStore();
        store.pmActivityLoading = true;
        store.pmActivity = samplePMActivity;
        const wrapper = mountPMActivity();
        expect(wrapper.find('[data-testid="pm-activity-loading"]').exists()).toBe(false);
    });

    // -- Empty state --

    it('shows empty state when not loading and no data', () => {
        const store = useDashboardStore();
        store.pmActivityLoading = false;
        store.pmActivity = null;
        const wrapper = mountPMActivity();
        expect(wrapper.find('[data-testid="pm-activity-empty"]').exists()).toBe(true);
    });

    // -- PRDs Created card --

    it('displays PRDs created count', () => {
        const store = useDashboardStore();
        store.pmActivity = samplePMActivity;
        const wrapper = mountPMActivity();
        expect(wrapper.find('[data-testid="prds-created-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="prds-created-value"]').text()).toBe('5');
    });

    // -- Conversations Held card --

    it('displays conversations held count', () => {
        const store = useDashboardStore();
        store.pmActivity = samplePMActivity;
        const wrapper = mountPMActivity();
        expect(wrapper.find('[data-testid="conversations-held-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="conversations-held-value"]').text()).toBe('42');
    });

    // -- Issues from Chat card --

    it('displays issues from chat count', () => {
        const store = useDashboardStore();
        store.pmActivity = samplePMActivity;
        const wrapper = mountPMActivity();
        expect(wrapper.find('[data-testid="issues-from-chat-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="issues-from-chat-value"]').text()).toBe('12');
    });

    // -- Avg Turns per PRD card --

    it('displays avg turns per PRD', () => {
        const store = useDashboardStore();
        store.pmActivity = samplePMActivity;
        const wrapper = mountPMActivity();
        expect(wrapper.find('[data-testid="avg-turns-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="avg-turns-value"]').text()).toBe('7.3');
    });

    it('displays dash when avg turns is null', () => {
        const store = useDashboardStore();
        store.pmActivity = { ...samplePMActivity, avg_turns_per_prd: null };
        const wrapper = mountPMActivity();
        expect(wrapper.find('[data-testid="avg-turns-value"]').text()).toBe('—');
    });

    it('shows "No PRDs yet" message when avg turns is null', () => {
        const store = useDashboardStore();
        store.pmActivity = { ...samplePMActivity, avg_turns_per_prd: null };
        const wrapper = mountPMActivity();
        const card = wrapper.find('[data-testid="avg-turns-card"]');
        expect(card.text()).toContain('No PRDs yet');
    });

    // -- Zero counts --

    it('displays zero counts correctly', () => {
        const store = useDashboardStore();
        store.pmActivity = {
            prds_created: 0,
            conversations_held: 0,
            issues_from_chat: 0,
            avg_turns_per_prd: null,
        };
        const wrapper = mountPMActivity();
        expect(wrapper.find('[data-testid="prds-created-value"]').text()).toBe('0');
        expect(wrapper.find('[data-testid="conversations-held-value"]').text()).toBe('0');
        expect(wrapper.find('[data-testid="issues-from-chat-value"]').text()).toBe('0');
        expect(wrapper.find('[data-testid="avg-turns-value"]').text()).toBe('—');
    });
});

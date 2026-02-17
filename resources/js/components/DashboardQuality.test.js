import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAdminStore } from '@/stores/admin';
import { useDashboardStore } from '@/stores/dashboard';
import DashboardQuality from './DashboardQuality.vue';

vi.mock('axios', () => ({
    default: {
        get: vi.fn().mockResolvedValue({ data: { data: null } }),
        patch: vi.fn().mockResolvedValue({ data: { success: true } }),
    },
}));

let pinia;

function mountQuality() {
    return mount(DashboardQuality, {
        global: {
            plugins: [pinia],
        },
    });
}

const sampleQuality = {
    acceptance_rate: null,
    severity_distribution: {
        critical: 3,
        major: 12,
        minor: 25,
    },
    total_findings: 40,
    total_reviews: 10,
    avg_findings_per_review: 4.0,
};

describe('dashboardQuality', () => {
    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
    });

    // -- Container --

    it('renders the quality container', () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        const wrapper = mountQuality();
        expect(wrapper.find('[data-testid="dashboard-quality"]').exists()).toBe(true);
    });

    // -- Loading state --

    it('shows loading indicator when loading and no data', () => {
        const store = useDashboardStore();
        store.qualityLoading = true;
        store.quality = null;
        const wrapper = mountQuality();
        expect(wrapper.find('[data-testid="quality-loading"]').exists()).toBe(true);
    });

    it('hides loading indicator when data is present', () => {
        const store = useDashboardStore();
        store.qualityLoading = true;
        store.quality = sampleQuality;
        const wrapper = mountQuality();
        expect(wrapper.find('[data-testid="quality-loading"]').exists()).toBe(false);
    });

    // -- Empty state --

    it('shows empty state when not loading and no data', () => {
        const store = useDashboardStore();
        store.qualityLoading = false;
        store.quality = null;
        const wrapper = mountQuality();
        expect(wrapper.find('[data-testid="quality-empty"]').exists()).toBe(true);
    });

    // -- Acceptance rate card --

    it('displays dash when acceptance rate is null', () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        const wrapper = mountQuality();
        expect(wrapper.find('[data-testid="acceptance-rate-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="acceptance-rate-value"]').text()).toBe('—');
    });

    it('shows not yet tracked message when acceptance rate is null', () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        const wrapper = mountQuality();
        const card = wrapper.find('[data-testid="acceptance-rate-card"]');
        expect(card.text()).toContain('Not yet tracked');
    });

    it('displays acceptance rate percentage when available', () => {
        const store = useDashboardStore();
        store.quality = { ...sampleQuality, acceptance_rate: 72.5 };
        const wrapper = mountQuality();
        expect(wrapper.find('[data-testid="acceptance-rate-value"]').text()).toBe('72.5%');
    });

    // -- Average findings per review card --

    it('displays average findings per review', () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        const wrapper = mountQuality();
        expect(wrapper.find('[data-testid="avg-findings-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="avg-findings-value"]').text()).toBe('4');
    });

    it('displays dash when avg findings is null', () => {
        const store = useDashboardStore();
        store.quality = { ...sampleQuality, avg_findings_per_review: null };
        const wrapper = mountQuality();
        expect(wrapper.find('[data-testid="avg-findings-value"]').text()).toBe('—');
    });

    it('displays total findings count', () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        const wrapper = mountQuality();
        const card = wrapper.find('[data-testid="avg-findings-card"]');
        expect(card.text()).toContain('40 total findings');
    });

    // -- Total reviews card --

    it('displays total reviews count', () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        const wrapper = mountQuality();
        expect(wrapper.find('[data-testid="total-reviews-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="total-reviews-value"]').text()).toBe('10');
    });

    // -- Severity distribution --

    it('renders severity distribution section', () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        const wrapper = mountQuality();
        expect(wrapper.find('[data-testid="severity-distribution"]').exists()).toBe(true);
    });

    it('displays critical severity count and percentage', () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        const wrapper = mountQuality();
        expect(wrapper.find('[data-testid="severity-critical-count"]').text()).toBe('3');
        // 3 / 40 = 7.5% → rounds to 8%
        expect(wrapper.find('[data-testid="severity-critical-pct"]').text()).toBe('8%');
    });

    it('displays major severity count and percentage', () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        const wrapper = mountQuality();
        expect(wrapper.find('[data-testid="severity-major-count"]').text()).toBe('12');
        // 12 / 40 = 30%
        expect(wrapper.find('[data-testid="severity-major-pct"]').text()).toBe('30%');
    });

    it('displays minor severity count and percentage', () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        const wrapper = mountQuality();
        expect(wrapper.find('[data-testid="severity-minor-count"]').text()).toBe('25');
        // 25 / 40 = 62.5% → rounds to 63%
        expect(wrapper.find('[data-testid="severity-minor-pct"]').text()).toBe('63%');
    });

    // -- Zero counts --

    it('displays zero counts correctly', () => {
        const store = useDashboardStore();
        store.quality = {
            ...sampleQuality,
            severity_distribution: { critical: 0, major: 0, minor: 0 },
            total_findings: 0,
            total_reviews: 0,
            avg_findings_per_review: null,
        };
        const wrapper = mountQuality();
        expect(wrapper.find('[data-testid="total-reviews-value"]').text()).toBe('0');
        expect(wrapper.find('[data-testid="severity-critical-count"]').text()).toBe('0');
        expect(wrapper.find('[data-testid="severity-critical-pct"]').text()).toBe('0%');
    });

    // -- Over-reliance alerts (T95) --

    const sampleOverrelianceAlerts = [
        {
            id: 1,
            rule: 'high_acceptance_rate',
            severity: 'warning',
            message: 'Acceptance rate has been above 95% for 2 consecutive weeks (avg: 97.5%).',
            context: { weekly_rates: [], consecutive_weeks: 2, threshold: 95 },
            acknowledged: false,
            created_at: '2026-02-15T09:00:00.000Z',
        },
        {
            id: 2,
            rule: 'zero_reactions',
            severity: 'info',
            message: 'Zero negative reactions across 30 findings in the last 30 days.',
            context: { total_findings: 30, negative_count: 0, lookback_days: 30 },
            acknowledged: false,
            created_at: '2026-02-15T09:00:00.000Z',
        },
    ];

    it('renders overreliance alert cards when data exists', () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        store.overrelianceAlerts = sampleOverrelianceAlerts;
        const wrapper = mountQuality();
        expect(wrapper.find('[data-testid="overreliance-alerts"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-testid^="overreliance-alert-"]')).toHaveLength(2);
    });

    it('does not render overreliance alerts section when empty', () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        store.overrelianceAlerts = [];
        const wrapper = mountQuality();
        expect(wrapper.find('[data-testid="overreliance-alerts"]').exists()).toBe(false);
    });

    it('displays rule label and message for overreliance alerts', () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        store.overrelianceAlerts = sampleOverrelianceAlerts;
        const wrapper = mountQuality();

        const alert1 = wrapper.find('[data-testid="overreliance-alert-1"]');
        expect(alert1.text()).toContain('High Acceptance Rate');
        expect(alert1.text()).toContain('above 95%');
    });

    it('calls acknowledgeOverrelianceAlert on dismiss click', async () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        store.overrelianceAlerts = [sampleOverrelianceAlerts[0]];
        store.fetchOverrelianceAlerts = vi.fn().mockResolvedValue(undefined);
        const adminStore = useAdminStore();
        adminStore.acknowledgeOverrelianceAlert = vi.fn().mockResolvedValue({ success: true });

        const wrapper = mountQuality();
        await wrapper.find('[data-testid="overreliance-acknowledge-btn"]').trigger('click');

        expect(adminStore.acknowledgeOverrelianceAlert).toHaveBeenCalledWith(1);
    });

    // -- Prompt version filter (T102) --

    it('renders prompt version filter dropdown when versions exist', () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        store.promptVersions = [
            { skill: 'frontend-review:1.0', claude_md: 'executor:1.0', schema: 'review:1.0' },
            { skill: 'frontend-review:1.1', claude_md: 'executor:1.0', schema: 'review:1.0' },
        ];
        const wrapper = mountQuality();
        expect(wrapper.find('[data-testid="prompt-version-filter"]').exists()).toBe(true);
    });

    it('does not render prompt version filter when no versions available', () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        store.promptVersions = [];
        const wrapper = mountQuality();
        expect(wrapper.find('[data-testid="prompt-version-filter"]').exists()).toBe(false);
    });

    it('lists all prompt version options in dropdown', () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        store.promptVersions = [
            { skill: 'frontend-review:1.0', claude_md: 'executor:1.0', schema: 'review:1.0' },
            { skill: 'backend-review:1.0', claude_md: 'executor:1.0', schema: 'review:1.0' },
        ];
        const wrapper = mountQuality();
        const options = wrapper.find('[data-testid="prompt-version-filter"]').findAll('option');
        // "All Versions" + 2 skill options
        expect(options).toHaveLength(3);
        expect(options[0].text()).toBe('All Versions');
        expect(options[1].text()).toBe('frontend-review:1.0');
        expect(options[2].text()).toBe('backend-review:1.0');
    });

    it('sets promptVersionFilter on dropdown change', async () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        store.overrelianceAlerts = [];
        store.promptVersions = [
            { skill: 'frontend-review:1.0', claude_md: 'executor:1.0', schema: 'review:1.0' },
        ];
        // Mock store methods to keep state stable during re-render
        store.fetchQuality = vi.fn().mockImplementation(() => {
            store.quality = sampleQuality;
            return Promise.resolve();
        });
        store.fetchPromptVersions = vi.fn().mockResolvedValue(undefined);
        store.fetchOverrelianceAlerts = vi.fn().mockResolvedValue(undefined);
        const wrapper = mountQuality();
        const select = wrapper.find('[data-testid="prompt-version-filter"]');
        await select.setValue('frontend-review:1.0');
        expect(store.promptVersionFilter).toBe('frontend-review:1.0');
    });

    it('resets promptVersionFilter to null when "All Versions" selected', async () => {
        const store = useDashboardStore();
        store.quality = sampleQuality;
        store.overrelianceAlerts = [];
        store.promptVersionFilter = 'frontend-review:1.0';
        store.promptVersions = [
            { skill: 'frontend-review:1.0', claude_md: 'executor:1.0', schema: 'review:1.0' },
        ];
        // Mock store methods to keep state stable during re-render
        store.fetchQuality = vi.fn().mockImplementation(() => {
            store.quality = sampleQuality;
            return Promise.resolve();
        });
        store.fetchPromptVersions = vi.fn().mockResolvedValue(undefined);
        store.fetchOverrelianceAlerts = vi.fn().mockResolvedValue(undefined);
        const wrapper = mountQuality();
        const select = wrapper.find('[data-testid="prompt-version-filter"]');
        await select.setValue('');
        expect(store.promptVersionFilter).toBe(null);
    });
});

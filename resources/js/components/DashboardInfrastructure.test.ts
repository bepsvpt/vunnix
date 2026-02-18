import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAdminStore } from '@/stores/admin';
import { useDashboardStore } from '@/stores/dashboard';
import DashboardInfrastructure from './DashboardInfrastructure.vue';

vi.mock('axios', () => ({
    default: {
        get: vi.fn().mockResolvedValue({ data: { data: [] } }),
        patch: vi.fn().mockResolvedValue({ data: {} }),
    },
}));

let pinia: ReturnType<typeof createPinia>;
let dashboard: ReturnType<typeof useDashboardStore>;
let admin: ReturnType<typeof useAdminStore>;

function makeAlert(overrides: Record<string, unknown> = {}) {
    return {
        id: 1,
        alert_type: 'cpu_usage',
        severity: 'warning',
        message: 'CPU usage is above 90%',
        created_at: '2026-02-15T10:00:00Z',
        ...overrides,
    };
}

function mountComponent() {
    return mount(DashboardInfrastructure, {
        global: { plugins: [pinia] },
    });
}

describe('dashboardInfrastructure', () => {
    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        dashboard = useDashboardStore();
        admin = useAdminStore();
        // Prevent real API calls during mount
        dashboard.fetchInfrastructureAlerts = vi.fn();
        admin.acknowledgeInfrastructureAlert = vi.fn().mockResolvedValue({ success: true });
    });

    // -- onMounted --

    it('calls fetchInfrastructureAlerts on mount', () => {
        mountComponent();
        expect(dashboard.fetchInfrastructureAlerts).toHaveBeenCalledOnce();
    });

    // -- Loading state --

    it('shows loading spinner when no status and no alerts', () => {
        dashboard.infrastructureAlerts = [];
        dashboard.infrastructureStatus = null;
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="infra-loading"]').exists()).toBe(true);
    });

    it('hides loading spinner when status is available', () => {
        dashboard.infrastructureStatus = { overall_status: 'healthy', active_alerts_count: 0 };
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="infra-loading"]').exists()).toBe(false);
    });

    // -- Empty/no-alerts state --

    it('shows no-alerts message when status present but no alerts', () => {
        dashboard.infrastructureAlerts = [];
        dashboard.infrastructureStatus = { overall_status: 'healthy', active_alerts_count: 0 };
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="infra-no-alerts"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="infra-no-alerts"]').text()).toContain('No active infrastructure alerts');
    });

    it('hides no-alerts message when alerts are present', () => {
        dashboard.infrastructureAlerts = [makeAlert()];
        dashboard.infrastructureStatus = { overall_status: 'degraded', active_alerts_count: 1 };
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="infra-no-alerts"]').exists()).toBe(false);
    });

    // -- Health status banner --

    it('shows healthy status banner when overall_status is healthy', () => {
        dashboard.infrastructureStatus = { overall_status: 'healthy', active_alerts_count: 0 };
        const wrapper = mountComponent();
        const banner = wrapper.find('[data-testid="infra-status-banner"]');
        expect(banner.exists()).toBe(true);
        expect(banner.text()).toContain('All Systems Healthy');
        expect(banner.classes()).toEqual(expect.arrayContaining([expect.stringContaining('green')]));
    });

    it('shows degraded status banner with alert count', () => {
        dashboard.infrastructureAlerts = [makeAlert({ id: 1 }), makeAlert({ id: 2 })];
        dashboard.infrastructureStatus = { overall_status: 'degraded', active_alerts_count: 2 };
        const wrapper = mountComponent();
        const banner = wrapper.find('[data-testid="infra-status-banner"]');
        expect(banner.exists()).toBe(true);
        expect(banner.text()).toContain('2 Active Alerts');
        expect(banner.classes()).toEqual(expect.arrayContaining([expect.stringContaining('orange')]));
    });

    it('shows singular "Alert" when exactly 1 active alert', () => {
        dashboard.infrastructureAlerts = [makeAlert()];
        dashboard.infrastructureStatus = { overall_status: 'degraded', active_alerts_count: 1 };
        const wrapper = mountComponent();
        const banner = wrapper.find('[data-testid="infra-status-banner"]');
        expect(banner.text()).toContain('1 Active Alert');
        expect(banner.text()).not.toContain('1 Active Alerts');
    });

    it('does not render status banner when status is null', () => {
        dashboard.infrastructureStatus = null;
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="infra-status-banner"]').exists()).toBe(false);
    });

    // -- System checks grid --

    it('renders 5 system check items in the grid', () => {
        dashboard.infrastructureAlerts = [];
        dashboard.infrastructureStatus = { overall_status: 'healthy', active_alerts_count: 0 };
        const wrapper = mountComponent();
        const grid = wrapper.find('[data-testid="infra-checks-grid"]');
        expect(grid.exists()).toBe(true);
        expect(wrapper.find('[data-testid="infra-check-container_health"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="infra-check-cpu_usage"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="infra-check-memory_usage"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="infra-check-disk_usage"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="infra-check-queue_depth"]').exists()).toBe(true);
    });

    it('shows correct human-readable labels for each check type', () => {
        dashboard.infrastructureAlerts = [];
        dashboard.infrastructureStatus = { overall_status: 'healthy', active_alerts_count: 0 };
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="infra-check-container_health"]').text()).toContain('Container Health');
        expect(wrapper.find('[data-testid="infra-check-cpu_usage"]').text()).toContain('CPU Usage');
        expect(wrapper.find('[data-testid="infra-check-memory_usage"]').text()).toContain('Memory Usage');
        expect(wrapper.find('[data-testid="infra-check-disk_usage"]').text()).toContain('Disk Usage');
        expect(wrapper.find('[data-testid="infra-check-queue_depth"]').text()).toContain('Queue Depth');
    });

    it('shows OK status for checks without matching alerts', () => {
        dashboard.infrastructureAlerts = [];
        dashboard.infrastructureStatus = { overall_status: 'healthy', active_alerts_count: 0 };
        const wrapper = mountComponent();
        const cpuCheck = wrapper.find('[data-testid="infra-check-cpu_usage"]');
        expect(cpuCheck.text()).toContain('OK');
        expect(cpuCheck.classes()).toEqual(expect.arrayContaining([expect.stringContaining('zinc')]));
    });

    it('shows Alert status for checks with matching alerts', () => {
        dashboard.infrastructureAlerts = [makeAlert({ alert_type: 'cpu_usage', severity: 'critical' })];
        dashboard.infrastructureStatus = { overall_status: 'degraded', active_alerts_count: 1 };
        const wrapper = mountComponent();
        const cpuCheck = wrapper.find('[data-testid="infra-check-cpu_usage"]');
        expect(cpuCheck.text()).toContain('Alert');
        expect(cpuCheck.classes()).toEqual(expect.arrayContaining([expect.stringContaining('orange')]));
    });

    it('marks only the matching check type as alerting', () => {
        dashboard.infrastructureAlerts = [makeAlert({ alert_type: 'disk_usage' })];
        dashboard.infrastructureStatus = { overall_status: 'degraded', active_alerts_count: 1 };
        const wrapper = mountComponent();
        // disk_usage should be alerting
        expect(wrapper.find('[data-testid="infra-check-disk_usage"]').text()).toContain('Alert');
        // other checks should be OK
        expect(wrapper.find('[data-testid="infra-check-cpu_usage"]').text()).toContain('OK');
        expect(wrapper.find('[data-testid="infra-check-memory_usage"]').text()).toContain('OK');
        expect(wrapper.find('[data-testid="infra-check-container_health"]').text()).toContain('OK');
        expect(wrapper.find('[data-testid="infra-check-queue_depth"]').text()).toContain('OK');
    });

    // -- Active alerts list --

    it('renders the active alerts section when alerts exist', () => {
        dashboard.infrastructureAlerts = [makeAlert()];
        dashboard.infrastructureStatus = { overall_status: 'degraded', active_alerts_count: 1 };
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="infra-alerts"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="infra-alerts"]').text()).toContain('Active Alerts');
    });

    it('does not render active alerts section when no alerts', () => {
        dashboard.infrastructureAlerts = [];
        dashboard.infrastructureStatus = { overall_status: 'healthy', active_alerts_count: 0 };
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="infra-alerts"]').exists()).toBe(false);
    });

    it('renders individual alerts with their data', () => {
        dashboard.infrastructureAlerts = [
            makeAlert({ id: 10, alert_type: 'memory_usage', message: 'Memory at 95%' }),
            makeAlert({ id: 11, alert_type: 'disk_usage', message: 'Disk at 98%' }),
        ];
        dashboard.infrastructureStatus = { overall_status: 'degraded', active_alerts_count: 2 };
        const wrapper = mountComponent();
        const alert10 = wrapper.find('[data-testid="infra-alert-10"]');
        const alert11 = wrapper.find('[data-testid="infra-alert-11"]');
        expect(alert10.exists()).toBe(true);
        expect(alert11.exists()).toBe(true);
        expect(alert10.text()).toContain('Memory Usage');
        expect(alert10.text()).toContain('Memory at 95%');
        expect(alert11.text()).toContain('Disk Usage');
        expect(alert11.text()).toContain('Disk at 98%');
    });

    it('shows type label for known alert types', () => {
        dashboard.infrastructureAlerts = [makeAlert({ alert_type: 'queue_depth', message: 'Queue backlog' })];
        dashboard.infrastructureStatus = { overall_status: 'degraded', active_alerts_count: 1 };
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="infra-alert-1"]').text()).toContain('Queue Depth');
    });

    it('falls back to raw alert_type for unknown types', () => {
        dashboard.infrastructureAlerts = [makeAlert({ alert_type: 'unknown_check', message: 'Something wrong' })];
        dashboard.infrastructureStatus = { overall_status: 'degraded', active_alerts_count: 1 };
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="infra-alert-1"]').text()).toContain('unknown_check');
    });

    // -- Severity coloring --

    it('applies critical severity styling', () => {
        dashboard.infrastructureAlerts = [makeAlert({ severity: 'critical' })];
        dashboard.infrastructureStatus = { overall_status: 'degraded', active_alerts_count: 1 };
        const wrapper = mountComponent();
        const alert = wrapper.find('[data-testid="infra-alert-1"]');
        expect(alert.classes()).toEqual(expect.arrayContaining([expect.stringContaining('red')]));
    });

    it('applies high severity styling', () => {
        dashboard.infrastructureAlerts = [makeAlert({ severity: 'high' })];
        dashboard.infrastructureStatus = { overall_status: 'degraded', active_alerts_count: 1 };
        const wrapper = mountComponent();
        const alert = wrapper.find('[data-testid="infra-alert-1"]');
        expect(alert.classes()).toEqual(expect.arrayContaining([expect.stringContaining('orange')]));
    });

    it('applies warning severity styling', () => {
        dashboard.infrastructureAlerts = [makeAlert({ severity: 'warning' })];
        dashboard.infrastructureStatus = { overall_status: 'degraded', active_alerts_count: 1 };
        const wrapper = mountComponent();
        const alert = wrapper.find('[data-testid="infra-alert-1"]');
        expect(alert.classes()).toEqual(expect.arrayContaining([expect.stringContaining('amber')]));
    });

    it('falls back to warning styling for unknown severity', () => {
        dashboard.infrastructureAlerts = [makeAlert({ severity: 'unknown_level' })];
        dashboard.infrastructureStatus = { overall_status: 'degraded', active_alerts_count: 1 };
        const wrapper = mountComponent();
        const alert = wrapper.find('[data-testid="infra-alert-1"]');
        // Falls back to severityColors.warning (amber)
        expect(alert.classes()).toEqual(expect.arrayContaining([expect.stringContaining('amber')]));
    });

    // -- Alert dismissal --

    it('renders dismiss button for each alert', () => {
        dashboard.infrastructureAlerts = [makeAlert({ id: 1 }), makeAlert({ id: 2 })];
        dashboard.infrastructureStatus = { overall_status: 'degraded', active_alerts_count: 2 };
        const wrapper = mountComponent();
        const dismissBtns = wrapper.findAll('[data-testid="acknowledge-btn"]');
        expect(dismissBtns).toHaveLength(2);
    });

    it('clicking dismiss calls admin.acknowledgeInfrastructureAlert with alert id', async () => {
        dashboard.infrastructureAlerts = [makeAlert({ id: 42 })];
        dashboard.infrastructureStatus = { overall_status: 'degraded', active_alerts_count: 1 };
        const wrapper = mountComponent();
        await wrapper.find('[data-testid="acknowledge-btn"]').trigger('click');
        await vi.dynamicImportSettled();

        expect(admin.acknowledgeInfrastructureAlert).toHaveBeenCalledWith(42);
    });

    it('refreshes infrastructure alerts after acknowledging', async () => {
        dashboard.infrastructureAlerts = [makeAlert({ id: 7 })];
        dashboard.infrastructureStatus = { overall_status: 'degraded', active_alerts_count: 1 };
        const wrapper = mountComponent();

        // Clear the initial mount call
        (dashboard.fetchInfrastructureAlerts as ReturnType<typeof vi.fn>).mockClear();

        await wrapper.find('[data-testid="acknowledge-btn"]').trigger('click');
        await vi.dynamicImportSettled();

        expect(dashboard.fetchInfrastructureAlerts).toHaveBeenCalledOnce();
    });

    // -- Timestamps --

    it('displays formatted timestamp for alerts', () => {
        dashboard.infrastructureAlerts = [makeAlert({ created_at: '2026-02-15T10:00:00Z' })];
        dashboard.infrastructureStatus = { overall_status: 'degraded', active_alerts_count: 1 };
        const wrapper = mountComponent();
        const alertEl = wrapper.find('[data-testid="infra-alert-1"]');
        // The date is formatted via toLocaleString — just ensure something date-like renders
        expect(alertEl.text()).toMatch(/2026|2\/15|Feb/);
    });

    // -- Multiple alerts on different check types --

    it('marks multiple check types as alerting when multiple alerts exist', () => {
        dashboard.infrastructureAlerts = [
            makeAlert({ id: 1, alert_type: 'cpu_usage' }),
            makeAlert({ id: 2, alert_type: 'memory_usage' }),
            makeAlert({ id: 3, alert_type: 'queue_depth' }),
        ];
        dashboard.infrastructureStatus = { overall_status: 'degraded', active_alerts_count: 3 };
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="infra-check-cpu_usage"]').text()).toContain('Alert');
        expect(wrapper.find('[data-testid="infra-check-memory_usage"]').text()).toContain('Alert');
        expect(wrapper.find('[data-testid="infra-check-queue_depth"]').text()).toContain('Alert');
        // These should still be OK
        expect(wrapper.find('[data-testid="infra-check-container_health"]').text()).toContain('OK');
        expect(wrapper.find('[data-testid="infra-check-disk_usage"]').text()).toContain('OK');
    });

    // -- Mutual exclusivity of states --

    it('shows only loading state when status is null and no alerts', () => {
        dashboard.infrastructureAlerts = [];
        dashboard.infrastructureStatus = null;
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="infra-loading"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="infra-no-alerts"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="infra-alerts"]').exists()).toBe(false);
    });

    it('shows only no-alerts state when status exists but no alerts', () => {
        dashboard.infrastructureAlerts = [];
        dashboard.infrastructureStatus = { overall_status: 'healthy', active_alerts_count: 0 };
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="infra-loading"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="infra-no-alerts"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="infra-alerts"]').exists()).toBe(false);
    });

    it('shows only alerts list when alerts exist', () => {
        dashboard.infrastructureAlerts = [makeAlert()];
        dashboard.infrastructureStatus = { overall_status: 'degraded', active_alerts_count: 1 };
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="infra-loading"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="infra-no-alerts"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="infra-alerts"]').exists()).toBe(true);
    });

    // -- Container always renders --

    it('always renders the root container', () => {
        dashboard.infrastructureStatus = null;
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="dashboard-infrastructure"]').exists()).toBe(true);
    });

    // -- System checks heading --

    it('renders System Checks heading', () => {
        dashboard.infrastructureStatus = { overall_status: 'healthy', active_alerts_count: 0 };
        const wrapper = mountComponent();
        expect(wrapper.text()).toContain('System Checks');
    });
});

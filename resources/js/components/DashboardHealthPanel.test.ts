import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useHealthStore } from '@/stores/health';
import DashboardHealthPanel from './DashboardHealthPanel.vue';

vi.mock('axios');

const mockListen = vi.fn();
const mockPrivate = vi.fn(() => ({ listen: mockListen }));
const mockLeave = vi.fn();
let realtimeHandler: ((event: unknown) => void) | null = null;

vi.mock('@/composables/useEcho', () => ({
    whenConnected: () => Promise.resolve(),
    getEcho: () => ({
        private: mockPrivate,
        leave: mockLeave,
    }),
}));

const mockedAxios = vi.mocked(axios, true);
let pinia: ReturnType<typeof createPinia>;

function mockApi({
    summary,
    trends,
    alerts,
}: {
    summary: Record<string, unknown>;
    trends: Array<Record<string, unknown>>;
    alerts: Array<Record<string, unknown>>;
}) {
    mockedAxios.get.mockImplementation((url: string) => {
        if (url.endsWith('/health/summary')) {
            return Promise.resolve({ data: { data: summary } });
        }
        if (url.endsWith('/health/trends')) {
            return Promise.resolve({ data: { data: trends } });
        }
        if (url.endsWith('/health/alerts')) {
            return Promise.resolve({
                data: {
                    data: alerts,
                    meta: { path: '/api/v1/projects/1/health/alerts', per_page: 25, next_cursor: null, prev_cursor: null },
                },
            });
        }

        return Promise.reject(new Error(`Unexpected URL: ${url}`));
    });
}

describe('dashboard health panel', () => {
    beforeEach(() => {
        pinia = createPinia();
        setActivePinia(pinia);
        vi.clearAllMocks();
        realtimeHandler = null;
        mockListen.mockImplementation((_, cb) => {
            realtimeHandler = cb as (event: unknown) => void;
            return { stopListening: vi.fn() };
        });
    });

    it('renders empty state when no health data exists', async () => {
        mockApi({
            summary: {
                coverage: { score: null, trend_direction: 'stable', last_checked_at: null },
                dependency: { score: null, trend_direction: 'stable', last_checked_at: null },
                complexity: { score: null, trend_direction: 'stable', last_checked_at: null },
            },
            trends: [],
            alerts: [],
        });

        const wrapper = mount(DashboardHealthPanel, {
            props: { projectId: 1 },
            global: { plugins: [pinia] },
        });

        await flushPromises();

        expect(wrapper.find('[data-testid="health-empty-state"]').exists()).toBe(true);
    });

    it('renders metrics, trend chart, and alerts when data exists', async () => {
        mockApi({
            summary: {
                coverage: { score: 72.5, trend_direction: 'down', last_checked_at: '2026-02-19T05:00:00Z' },
                dependency: { score: 94, trend_direction: 'stable', last_checked_at: '2026-02-19T05:00:00Z' },
                complexity: { score: 63, trend_direction: 'up', last_checked_at: '2026-02-19T05:00:00Z' },
            },
            trends: [
                { id: 1, dimension: 'coverage', score: 80, details: {}, source_ref: null, created_at: '2026-02-17T05:00:00Z' },
                { id: 2, dimension: 'coverage', score: 72.5, details: {}, source_ref: null, created_at: '2026-02-19T05:00:00Z' },
            ],
            alerts: [
                {
                    id: 10,
                    alert_type: 'health_coverage_decline',
                    status: 'active',
                    severity: 'warning',
                    message: 'Coverage dropped below threshold.',
                    context: { gitlab_issue_url: 'https://gitlab.example.com/issues/10' },
                    detected_at: '2026-02-19T05:00:00Z',
                    resolved_at: null,
                    created_at: '2026-02-19T05:00:00Z',
                    updated_at: '2026-02-19T05:00:00Z',
                },
            ],
        });

        const wrapper = mount(DashboardHealthPanel, {
            props: { projectId: 1 },
            global: { plugins: [pinia] },
        });

        await flushPromises();

        expect(wrapper.find('[data-testid="health-metric-coverage"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="health-trend-chart"]').exists()).toBe(true);
        expect(
            wrapper.find('[data-testid="health-alerts-list"]').exists()
            || wrapper.find('[data-testid="health-alerts-empty"]').exists(),
        ).toBe(true);
    });

    it('renders fallback labels for null score and null last checked timestamp', async () => {
        mockApi({
            summary: {
                coverage: { score: null, trend_direction: 'stable', last_checked_at: null },
                dependency: { score: 91, trend_direction: 'up', last_checked_at: '2026-02-19T05:00:00Z' },
                complexity: { score: null, trend_direction: 'stable', last_checked_at: null },
            },
            trends: [],
            alerts: [],
        });

        const wrapper = mount(DashboardHealthPanel, {
            props: { projectId: 1 },
            global: { plugins: [pinia] },
        });
        await flushPromises();

        expect(wrapper.text()).toContain('Not yet analyzed');
        expect(wrapper.text()).toContain('—');
    });

    it('fetches trends for clicked dimension', async () => {
        mockApi({
            summary: {
                coverage: { score: 80, trend_direction: 'stable', last_checked_at: '2026-02-19T05:00:00Z' },
                dependency: { score: 92, trend_direction: 'up', last_checked_at: '2026-02-19T05:00:00Z' },
                complexity: { score: 70, trend_direction: 'down', last_checked_at: '2026-02-19T05:00:00Z' },
            },
            trends: [],
            alerts: [],
        });

        const wrapper = mount(DashboardHealthPanel, {
            props: { projectId: 1 },
            global: { plugins: [pinia] },
        });
        await flushPromises();

        const health = useHealthStore();
        const fetchTrendsSpy = vi.spyOn(health, 'fetchTrends').mockResolvedValue();
        fetchTrendsSpy.mockClear();

        await wrapper.find('[data-testid="health-metric-dependency"]').trigger('click');

        expect(fetchTrendsSpy).toHaveBeenCalledWith(1, 'dependency');
    });

    it('reacts to realtime snapshot events and refreshes trends/alerts', async () => {
        mockApi({
            summary: {
                coverage: { score: 80, trend_direction: 'stable', last_checked_at: '2026-02-19T05:00:00Z' },
                dependency: { score: 92, trend_direction: 'up', last_checked_at: '2026-02-19T05:00:00Z' },
                complexity: { score: 70, trend_direction: 'down', last_checked_at: '2026-02-19T05:00:00Z' },
            },
            trends: [],
            alerts: [],
        });

        const wrapper = mount(DashboardHealthPanel, {
            props: { projectId: 1 },
            global: { plugins: [pinia] },
        });
        await flushPromises();

        const health = useHealthStore();
        health.selectedDimension = 'coverage';
        const applySpy = vi.spyOn(health, 'applyRealtimeSnapshot');
        const fetchTrendsSpy = vi.spyOn(health, 'fetchTrends').mockResolvedValue();
        const fetchAlertsSpy = vi.spyOn(health, 'fetchAlerts').mockResolvedValue();
        fetchTrendsSpy.mockClear();
        fetchAlertsSpy.mockClear();

        expect(realtimeHandler).not.toBeNull();
        realtimeHandler?.({
            dimension: 'coverage',
            score: 77,
            trend_direction: 'down',
            created_at: '2026-02-19T06:00:00Z',
        });

        expect(applySpy).toHaveBeenCalled();
        expect(fetchTrendsSpy).toHaveBeenCalledWith(1, 'coverage');
        expect(fetchAlertsSpy).toHaveBeenCalledWith(1);

        await wrapper.unmount();
    });

    it('watches selectedDimension changes and leaves channel on unmount', async () => {
        mockApi({
            summary: {
                coverage: { score: 80, trend_direction: 'stable', last_checked_at: '2026-02-19T05:00:00Z' },
                dependency: { score: 92, trend_direction: 'up', last_checked_at: '2026-02-19T05:00:00Z' },
                complexity: { score: 70, trend_direction: 'down', last_checked_at: '2026-02-19T05:00:00Z' },
            },
            trends: [],
            alerts: [],
        });

        const wrapper = mount(DashboardHealthPanel, {
            props: { projectId: 1 },
            global: { plugins: [pinia] },
        });
        await flushPromises();

        const health = useHealthStore();
        const fetchTrendsSpy = vi.spyOn(health, 'fetchTrends').mockResolvedValue();
        fetchTrendsSpy.mockClear();

        health.selectedDimension = 'dependency';
        await flushPromises();

        expect(fetchTrendsSpy).toHaveBeenCalledWith(1, 'dependency');

        await wrapper.unmount();
        expect(mockLeave).toHaveBeenCalledWith('project.1.health');
    });

    it('renders health alert cards when alerts are present', async () => {
        mockApi({
            summary: {
                coverage: { score: 72, trend_direction: 'down', last_checked_at: '2026-02-19T05:00:00Z' },
                dependency: { score: 94, trend_direction: 'stable', last_checked_at: '2026-02-19T05:00:00Z' },
                complexity: { score: 63, trend_direction: 'up', last_checked_at: '2026-02-19T05:00:00Z' },
            },
            trends: [],
            alerts: [
                {
                    id: 9,
                    alert_type: 'health_vulnerability_found',
                    status: 'active',
                    severity: 'critical',
                    message: 'Dependencies risk',
                    context: {},
                    detected_at: '2026-02-19T05:00:00Z',
                    resolved_at: null,
                    created_at: '2026-02-19T05:00:00Z',
                    updated_at: '2026-02-19T05:00:00Z',
                },
            ],
        });

        const wrapper = mount(DashboardHealthPanel, {
            props: { projectId: 1 },
            global: { plugins: [pinia] },
        });
        await flushPromises();

        expect(wrapper.find('[data-testid="health-alerts-list"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-testid="health-alert-card"]').length).toBe(1);
    });
});

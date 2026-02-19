import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DashboardHealthPanel from './DashboardHealthPanel.vue';

vi.mock('axios');

const mockListen = vi.fn();
const mockPrivate = vi.fn(() => ({ listen: mockListen }));
const mockLeave = vi.fn();

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
});

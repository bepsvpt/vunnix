import type { HealthSummary } from '@/types';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useHealthStore } from './health';

const fetchSummaryMock = vi.fn();
const fetchTrendsMock = vi.fn();
const fetchAlertsMock = vi.fn();

vi.mock('@/composables/useProjectHealth', () => ({
    useProjectHealth: () => ({
        fetchSummary: fetchSummaryMock,
        fetchTrends: fetchTrendsMock,
        fetchAlerts: fetchAlertsMock,
    }),
}));

function makeSummary(): HealthSummary {
    return {
        coverage: { score: 88, trend_direction: 'up', last_checked_at: '2026-02-19T00:00:00Z' },
        dependency: { score: 79, trend_direction: 'stable', last_checked_at: '2026-02-19T00:00:00Z' },
        complexity: { score: 66, trend_direction: 'down', last_checked_at: '2026-02-19T00:00:00Z' },
    };
}

describe('health store', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        fetchSummaryMock.mockResolvedValue(makeSummary());
        fetchTrendsMock.mockResolvedValue([]);
        fetchAlertsMock.mockResolvedValue({ data: [] });
    });

    it('sets summary to null when summary fetch fails', async () => {
        const store = useHealthStore();
        store.summary = makeSummary();
        fetchSummaryMock.mockRejectedValueOnce(new Error('boom'));

        await store.fetchSummary(1);

        expect(store.summary).toBeNull();
    });

    it('sets trends to empty array when trends fetch fails', async () => {
        const store = useHealthStore();
        store.trends = [{ id: 1 } as never];
        fetchTrendsMock.mockRejectedValueOnce(new Error('boom'));

        await store.fetchTrends(1, 'coverage');

        expect(store.trends).toEqual([]);
    });

    it('does nothing in applyRealtimeSnapshot when summary is null', () => {
        const store = useHealthStore();
        store.summary = null;

        store.applyRealtimeSnapshot({
            dimension: 'coverage',
            score: 42,
            trend_direction: 'down',
            created_at: '2026-02-19T10:00:00Z',
        });

        expect(store.summary).toBeNull();
    });

    it('updates only the target dimension in applyRealtimeSnapshot', () => {
        const store = useHealthStore();
        store.summary = makeSummary();

        store.applyRealtimeSnapshot({
            dimension: 'dependency',
            score: 91,
            trend_direction: 'up',
            created_at: '2026-02-19T10:00:00Z',
        });

        expect(store.summary?.dependency.score).toBe(91);
        expect(store.summary?.dependency.trend_direction).toBe('up');
        expect(store.summary?.dependency.last_checked_at).toBe('2026-02-19T10:00:00Z');
    });

    it('resets all reactive state via $reset', () => {
        const store = useHealthStore();
        store.summary = makeSummary();
        store.trends = [{ id: 1 } as never];
        store.alerts = [{ id: 2 } as never];
        store.selectedDimension = 'complexity';
        store.loading = true;
        store.summaryLoading = true;
        store.trendsLoading = true;
        store.alertsLoading = true;

        store.$reset();

        expect(store.summary).toBeNull();
        expect(store.trends).toEqual([]);
        expect(store.alerts).toEqual([]);
        expect(store.selectedDimension).toBe('coverage');
        expect(store.loading).toBe(false);
        expect(store.summaryLoading).toBe(false);
        expect(store.trendsLoading).toBe(false);
        expect(store.alertsLoading).toBe(false);
    });
});

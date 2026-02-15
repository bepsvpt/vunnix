import { describe, it, expect, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useDashboardStore } from './dashboard';

describe('dashboard store', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    it('starts with empty activity feed', () => {
        const store = useDashboardStore();
        expect(store.activityFeed).toEqual([]);
    });

    it('starts with empty metrics updates', () => {
        const store = useDashboardStore();
        expect(store.metricsUpdates).toEqual([]);
    });

    describe('addActivityItem', () => {
        it('prepends to feed', () => {
            const store = useDashboardStore();
            store.addActivityItem({
                task_id: 1,
                status: 'completed',
                type: 'code_review',
                project_id: 10,
                title: 'Review MR !5',
                timestamp: '2026-02-15T10:00:00Z',
            });
            expect(store.activityFeed).toHaveLength(1);
            expect(store.activityFeed[0].task_id).toBe(1);
        });

        it('deduplicates by task_id (updates in place)', () => {
            const store = useDashboardStore();
            store.addActivityItem({ task_id: 1, status: 'queued', type: 'code_review', project_id: 10, title: 'Review', timestamp: 't1' });
            store.addActivityItem({ task_id: 1, status: 'completed', type: 'code_review', project_id: 10, title: 'Review', timestamp: 't2' });
            expect(store.activityFeed).toHaveLength(1);
            expect(store.activityFeed[0].status).toBe('completed');
        });

        it('caps feed at 200 items', () => {
            const store = useDashboardStore();
            for (let i = 0; i < 210; i++) {
                store.addActivityItem({ task_id: i, status: 'completed', type: 'code_review', project_id: 10, title: `Task ${i}`, timestamp: `t${i}` });
            }
            expect(store.activityFeed).toHaveLength(200);
            // Most recent should be first
            expect(store.activityFeed[0].task_id).toBe(209);
        });
    });

    describe('addMetricsUpdate', () => {
        it('stores latest per project_id', () => {
            const store = useDashboardStore();
            store.addMetricsUpdate({ project_id: 10, data: { tasks_today: 5 }, timestamp: 't1' });
            store.addMetricsUpdate({ project_id: 10, data: { tasks_today: 6 }, timestamp: 't2' });
            expect(store.metricsUpdates).toHaveLength(1);
            expect(store.metricsUpdates[0].data.tasks_today).toBe(6);
        });

        it('stores separate entries for different projects', () => {
            const store = useDashboardStore();
            store.addMetricsUpdate({ project_id: 10, data: { tasks_today: 5 }, timestamp: 't1' });
            store.addMetricsUpdate({ project_id: 20, data: { tasks_today: 3 }, timestamp: 't2' });
            expect(store.metricsUpdates).toHaveLength(2);
        });
    });

    describe('filteredFeed', () => {
        it('returns all items when filter is null', () => {
            const store = useDashboardStore();
            store.addActivityItem({ task_id: 1, status: 'completed', type: 'code_review', project_id: 10, title: 'A', timestamp: 't1' });
            store.addActivityItem({ task_id: 2, status: 'completed', type: 'feature_dev', project_id: 10, title: 'B', timestamp: 't2' });
            expect(store.filteredFeed).toHaveLength(2);
        });

        it('filters by type when activeFilter is set', () => {
            const store = useDashboardStore();
            store.addActivityItem({ task_id: 1, status: 'completed', type: 'code_review', project_id: 10, title: 'A', timestamp: 't1' });
            store.addActivityItem({ task_id: 2, status: 'completed', type: 'feature_dev', project_id: 10, title: 'B', timestamp: 't2' });
            store.activeFilter = 'code_review';
            expect(store.filteredFeed).toHaveLength(1);
            expect(store.filteredFeed[0].type).toBe('code_review');
        });

        it('filters by project when projectFilter is set', () => {
            const store = useDashboardStore();
            store.addActivityItem({ task_id: 1, status: 'completed', type: 'code_review', project_id: 10, title: 'A', timestamp: 't1' });
            store.addActivityItem({ task_id: 2, status: 'completed', type: 'code_review', project_id: 20, title: 'B', timestamp: 't2' });
            store.projectFilter = 10;
            expect(store.filteredFeed).toHaveLength(1);
            expect(store.filteredFeed[0].project_id).toBe(10);
        });

        it('combines type and project filters', () => {
            const store = useDashboardStore();
            store.addActivityItem({ task_id: 1, status: 'completed', type: 'code_review', project_id: 10, title: 'A', timestamp: 't1' });
            store.addActivityItem({ task_id: 2, status: 'completed', type: 'feature_dev', project_id: 10, title: 'B', timestamp: 't2' });
            store.addActivityItem({ task_id: 3, status: 'completed', type: 'code_review', project_id: 20, title: 'C', timestamp: 't3' });
            store.activeFilter = 'code_review';
            store.projectFilter = 10;
            expect(store.filteredFeed).toHaveLength(1);
            expect(store.filteredFeed[0].task_id).toBe(1);
        });
    });

    describe('$reset', () => {
        it('clears all state', () => {
            const store = useDashboardStore();
            store.addActivityItem({ task_id: 1, status: 'completed', type: 'code_review', project_id: 10, title: 'A', timestamp: 't1' });
            store.addMetricsUpdate({ project_id: 10, data: {}, timestamp: 't1' });
            store.activeFilter = 'code_review';
            store.projectFilter = 10;
            store.$reset();
            expect(store.activityFeed).toEqual([]);
            expect(store.metricsUpdates).toEqual([]);
            expect(store.activeFilter).toBeNull();
            expect(store.projectFilter).toBeNull();
        });
    });
});

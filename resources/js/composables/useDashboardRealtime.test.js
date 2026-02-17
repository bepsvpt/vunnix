import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { useDashboardStore } from '@/stores/dashboard';
import { useDashboardRealtime } from './useDashboardRealtime';

// Mock Echo (same pattern as useEcho.test.js)
const { mockListen, mockPrivate, mockLeave } = vi.hoisted(() => {
    const mockListen = vi.fn().mockReturnThis();
    const mockStopListening = vi.fn().mockReturnThis();
    const mockPrivate = vi.fn().mockReturnValue({
        listen: mockListen,
        stopListening: mockStopListening,
    });
    const mockLeave = vi.fn();
    return { mockListen, mockStopListening, mockPrivate, mockLeave };
});

vi.mock('@/composables/useEcho', () => ({
    getEcho: () => ({
        private: mockPrivate,
        leave: mockLeave,
    }),
}));

describe('useDashboardRealtime', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('subscribes to project activity and metrics channels', () => {
        const projects = [{ id: 10 }, { id: 20 }];
        const { subscribe } = useDashboardRealtime();
        subscribe(projects);

        expect(mockPrivate).toHaveBeenCalledWith('project.10.activity');
        expect(mockPrivate).toHaveBeenCalledWith('project.20.activity');
        expect(mockPrivate).toHaveBeenCalledWith('metrics.10');
        expect(mockPrivate).toHaveBeenCalledWith('metrics.20');
        // 2 projects × 2 channels = 4 subscriptions
        expect(mockPrivate).toHaveBeenCalledTimes(4);
    });

    it('listens for task.status.changed on activity channels', () => {
        const { subscribe } = useDashboardRealtime();
        subscribe([{ id: 10 }]);

        expect(mockListen).toHaveBeenCalledWith('.task.status.changed', expect.any(Function));
    });

    it('listens for metrics.updated on metrics channels', () => {
        const { subscribe } = useDashboardRealtime();
        subscribe([{ id: 10 }]);

        const listenCalls = mockListen.mock.calls;
        const eventNames = listenCalls.map(c => c[0]);
        expect(eventNames).toContain('.task.status.changed');
        expect(eventNames).toContain('.metrics.updated');
    });

    it('adds activity items to dashboard store when event fires', () => {
        const store = useDashboardStore();
        const { subscribe } = useDashboardRealtime();
        subscribe([{ id: 10 }]);

        // Find the .task.status.changed handler
        const activityCall = mockListen.mock.calls.find(c => c[0] === '.task.status.changed');
        const handler = activityCall[1];

        handler({
            task_id: 42,
            status: 'completed',
            type: 'code_review',
            project_id: 10,
            title: 'Review MR !5',
            timestamp: '2026-02-15T10:00:00Z',
        });

        expect(store.activityFeed).toHaveLength(1);
        expect(store.activityFeed[0].task_id).toBe(42);
    });

    it('adds metrics updates to dashboard store when event fires', () => {
        const store = useDashboardStore();
        const { subscribe } = useDashboardRealtime();
        subscribe([{ id: 10 }]);

        // Find the .metrics.updated handler
        const metricsCall = mockListen.mock.calls.find(c => c[0] === '.metrics.updated');
        const handler = metricsCall[1];

        handler({
            project_id: 10,
            data: { tasks_today: 7 },
            timestamp: '2026-02-15T10:15:00Z',
        });

        expect(store.metricsUpdates).toHaveLength(1);
        expect(store.metricsUpdates[0].data.tasks_today).toBe(7);
    });

    it('unsubscribe leaves all channels', () => {
        const { subscribe, unsubscribe } = useDashboardRealtime();
        subscribe([{ id: 10 }, { id: 20 }]);
        unsubscribe();

        expect(mockLeave).toHaveBeenCalledWith('project.10.activity');
        expect(mockLeave).toHaveBeenCalledWith('project.20.activity');
        expect(mockLeave).toHaveBeenCalledWith('metrics.10');
        expect(mockLeave).toHaveBeenCalledWith('metrics.20');
    });

    it('resubscribe replaces previous subscriptions', () => {
        const { subscribe } = useDashboardRealtime();
        subscribe([{ id: 10 }]);
        subscribe([{ id: 20 }]);

        // Should have left old channels before subscribing new
        expect(mockLeave).toHaveBeenCalledWith('project.10.activity');
        expect(mockLeave).toHaveBeenCalledWith('metrics.10');
        // New channels subscribed
        expect(mockPrivate).toHaveBeenCalledWith('project.20.activity');
        expect(mockPrivate).toHaveBeenCalledWith('metrics.20');
    });

    it('does nothing if projects array is empty', () => {
        const { subscribe } = useDashboardRealtime();
        subscribe([]);
        expect(mockPrivate).not.toHaveBeenCalled();
    });

    it('does nothing if projects is null', () => {
        const { subscribe } = useDashboardRealtime();
        subscribe(null);
        expect(mockPrivate).not.toHaveBeenCalled();
    });
});

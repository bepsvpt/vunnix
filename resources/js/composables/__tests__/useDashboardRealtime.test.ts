import type { Mock } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { useDashboardStore } from '@/features/dashboard';
import { useDashboardRealtime } from '../useDashboardRealtime';

// Mock Echo (same pattern as useEcho.test.js)
const { mockListen, mockPrivate, mockLeave } = vi.hoisted(() => {
    const mockListen: Mock = vi.fn().mockReturnThis();
    const mockStopListening: Mock = vi.fn().mockReturnThis();
    const mockPrivate: Mock = vi.fn().mockReturnValue({
        listen: mockListen,
        stopListening: mockStopListening,
    });
    const mockLeave: Mock = vi.fn();
    return { mockListen, mockStopListening, mockPrivate, mockLeave };
});

vi.mock('@/composables/useEcho', () => ({
    getEcho: () => ({
        private: mockPrivate,
        leave: mockLeave,
    }),
    whenConnected: () => Promise.resolve(),
}));

describe('useDashboardRealtime', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('subscribes to project activity and metrics channels', async () => {
        const projects = [{ id: 10 }, { id: 20 }];
        const { subscribe } = useDashboardRealtime();
        subscribe(projects);

        // Wait for whenConnected() promise to resolve
        await vi.waitFor(() => {
            expect(mockPrivate).toHaveBeenCalledWith('project.10.activity');
        });

        expect(mockPrivate).toHaveBeenCalledWith('project.20.activity');
        expect(mockPrivate).toHaveBeenCalledWith('metrics.10');
        expect(mockPrivate).toHaveBeenCalledWith('metrics.20');
        // 2 projects x 2 channels = 4 subscriptions
        expect(mockPrivate).toHaveBeenCalledTimes(4);
    });

    it('listens for task.status.changed on activity channels', async () => {
        const { subscribe } = useDashboardRealtime();
        subscribe([{ id: 10 }]);

        await vi.waitFor(() => {
            expect(mockListen).toHaveBeenCalledWith('.task.status.changed', expect.any(Function));
        });
    });

    it('listens for metrics.updated on metrics channels', async () => {
        const { subscribe } = useDashboardRealtime();
        subscribe([{ id: 10 }]);

        await vi.waitFor(() => {
            expect(mockListen).toHaveBeenCalled();
        });

        const listenCalls = mockListen.mock.calls;
        const eventNames = listenCalls.map((c: unknown[]) => c[0]);
        expect(eventNames).toContain('.task.status.changed');
        expect(eventNames).toContain('.metrics.updated');
    });

    it('adds activity items to dashboard store when event fires', async () => {
        const store = useDashboardStore();
        const { subscribe } = useDashboardRealtime();
        subscribe([{ id: 10 }]);

        await vi.waitFor(() => {
            expect(mockListen).toHaveBeenCalled();
        });

        // Find the .task.status.changed handler
        const activityCall = mockListen.mock.calls.find((c: unknown[]) => c[0] === '.task.status.changed');
        const handler = activityCall[1] as (event: Record<string, unknown>) => void;

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

    it('adds metrics updates to dashboard store when event fires', async () => {
        const store = useDashboardStore();
        const { subscribe } = useDashboardRealtime();
        subscribe([{ id: 10 }]);

        await vi.waitFor(() => {
            expect(mockListen).toHaveBeenCalled();
        });

        // Find the .metrics.updated handler
        const metricsCall = mockListen.mock.calls.find((c: unknown[]) => c[0] === '.metrics.updated');
        const handler = metricsCall[1] as (event: Record<string, unknown>) => void;

        handler({
            project_id: 10,
            data: { tasks_today: 7 },
            timestamp: '2026-02-15T10:15:00Z',
        });

        expect(store.metricsUpdates).toHaveLength(1);
        expect(store.metricsUpdates[0].data.tasks_today).toBe(7);
    });

    it('unsubscribe leaves all channels', async () => {
        const { subscribe, unsubscribe } = useDashboardRealtime();
        subscribe([{ id: 10 }, { id: 20 }]);

        await vi.waitFor(() => {
            expect(mockPrivate).toHaveBeenCalled();
        });

        unsubscribe();

        expect(mockLeave).toHaveBeenCalledWith('project.10.activity');
        expect(mockLeave).toHaveBeenCalledWith('project.20.activity');
        expect(mockLeave).toHaveBeenCalledWith('metrics.10');
        expect(mockLeave).toHaveBeenCalledWith('metrics.20');
    });

    it('resubscribe replaces previous subscriptions', async () => {
        const { subscribe } = useDashboardRealtime();
        subscribe([{ id: 10 }]);

        await vi.waitFor(() => {
            expect(mockPrivate).toHaveBeenCalledWith('project.10.activity');
        });

        subscribe([{ id: 20 }]);

        // Should have left old channels before subscribing new
        expect(mockLeave).toHaveBeenCalledWith('project.10.activity');
        expect(mockLeave).toHaveBeenCalledWith('metrics.10');

        await vi.waitFor(() => {
            expect(mockPrivate).toHaveBeenCalledWith('project.20.activity');
        });

        expect(mockPrivate).toHaveBeenCalledWith('metrics.20');
    });

    it('does nothing if projects array is empty', () => {
        const { subscribe } = useDashboardRealtime();
        subscribe([]);
        expect(mockPrivate).not.toHaveBeenCalled();
    });

    it('does nothing if projects is null', () => {
        const { subscribe } = useDashboardRealtime();
        subscribe(null as unknown as { id: number }[]);
        expect(mockPrivate).not.toHaveBeenCalled();
    });

    it('cancels pending subscription if unsubscribed before connection', () => {
        const { subscribe, unsubscribe } = useDashboardRealtime();
        subscribe([{ id: 10 }]);
        // Unsubscribe before whenConnected resolves
        unsubscribe();
        // The then() callback should see subscribedChannels is empty and bail
        expect(mockPrivate).not.toHaveBeenCalled();
    });
});

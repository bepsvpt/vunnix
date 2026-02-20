import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useActivityStore } from '../activity';

vi.mock('axios');

const mockedAxios = vi.mocked(axios, true);

describe('activity store', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('starts with empty state and hasMore false', () => {
        const store = useActivityStore();

        expect(store.activityFeed).toEqual([]);
        expect(store.activeFilter).toBeNull();
        expect(store.isLoading).toBe(false);
        expect(store.nextCursor).toBeNull();
        expect(store.hasMore).toBe(false);
    });

    it('fetchActivity requests first page without type filter', async () => {
        mockedAxios.get.mockResolvedValueOnce({
            data: {
                data: [
                    {
                        task_id: 1,
                        type: 'code_review',
                        status: 'completed',
                        project_id: 10,
                        project_name: 'proj',
                        summary: 'done',
                        created_at: '2026-02-20T00:00:00Z',
                    },
                ],
                meta: { next_cursor: 'cursor-1', per_page: 25 },
            },
        });

        const store = useActivityStore();
        store.nextCursor = 'stale';

        await store.fetchActivity();

        expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/activity', {
            params: { per_page: 25 },
        });
        expect(store.activeFilter).toBeNull();
        expect(store.activityFeed).toHaveLength(1);
        expect(store.nextCursor).toBe('cursor-1');
        expect(store.hasMore).toBe(true);
        expect(store.isLoading).toBe(false);
    });

    it('fetchActivity includes type filter and handles missing next cursor', async () => {
        mockedAxios.get.mockResolvedValueOnce({
            data: {
                data: [],
                meta: {},
            },
        });

        const store = useActivityStore();

        await store.fetchActivity('code_review');

        expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/activity', {
            params: { per_page: 25, type: 'code_review' },
        });
        expect(store.activeFilter).toBe('code_review');
        expect(store.nextCursor).toBeNull();
        expect(store.hasMore).toBe(false);
    });

    it('loadMore does nothing when there is no next cursor', async () => {
        const store = useActivityStore();
        store.nextCursor = null;

        await store.loadMore();

        expect(mockedAxios.get).not.toHaveBeenCalled();
    });

    it('loadMore does nothing while already loading', async () => {
        const store = useActivityStore();
        store.nextCursor = 'cursor-1';
        store.isLoading = true;

        await store.loadMore();

        expect(mockedAxios.get).not.toHaveBeenCalled();
    });

    it('loadMore appends data and carries active type filter', async () => {
        mockedAxios.get.mockResolvedValueOnce({
            data: {
                data: [
                    {
                        task_id: 2,
                        type: 'feature_dev',
                        status: 'running',
                        project_id: 10,
                        project_name: 'proj',
                        summary: 'in progress',
                        created_at: '2026-02-20T01:00:00Z',
                    },
                ],
                meta: { next_cursor: null, per_page: 25 },
            },
        });

        const store = useActivityStore();
        store.activityFeed = [
            {
                task_id: 1,
                type: 'code_review',
                status: 'completed',
                project_id: 10,
                project_name: 'proj',
                summary: 'done',
                created_at: '2026-02-20T00:00:00Z',
            },
        ];
        store.activeFilter = 'code_review';
        store.nextCursor = 'cursor-1';

        await store.loadMore();

        expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/activity', {
            params: { per_page: 25, cursor: 'cursor-1', type: 'code_review' },
        });
        expect(store.activityFeed).toHaveLength(2);
        expect(store.nextCursor).toBeNull();
        expect(store.hasMore).toBe(false);
        expect(store.isLoading).toBe(false);
    });

    it('loadMore resets loading state when request fails', async () => {
        mockedAxios.get.mockRejectedValueOnce(new Error('network error'));

        const store = useActivityStore();
        store.nextCursor = 'cursor-1';

        await expect(store.loadMore()).rejects.toThrow('network error');
        expect(store.isLoading).toBe(false);
    });
});

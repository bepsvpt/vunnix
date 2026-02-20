import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useDashboardStore } from '@/features/dashboard';

vi.mock('axios');

const mockedAxios = vi.mocked(axios, true);

describe('dashboard store — prompt version filter', () => {
    let store: ReturnType<typeof useDashboardStore>;

    beforeEach(() => {
        setActivePinia(createPinia());
        store = useDashboardStore();
        mockedAxios.get.mockResolvedValue({ data: { data: [] } });
    });

    it('has promptVersionFilter ref initialized to null', () => {
        expect(store.promptVersionFilter).toBe(null);
    });

    it('has promptVersions ref initialized to empty array', () => {
        expect(store.promptVersions).toEqual([]);
    });

    it('fetchPromptVersions populates promptVersions', async () => {
        const mockVersions = [
            { skill: 'frontend-review:1.0', claude_md: 'executor:1.0', schema: 'review:1.0' },
            { skill: 'frontend-review:1.1', claude_md: 'executor:1.0', schema: 'review:1.0' },
        ];
        mockedAxios.get.mockResolvedValueOnce({ data: { data: mockVersions } });

        await store.fetchPromptVersions();

        expect(store.promptVersions).toEqual(mockVersions);
        expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/prompt-versions');
    });

    it('fetchPromptVersions normalizes strings and drops invalid entries', async () => {
        mockedAxios.get.mockResolvedValueOnce({
            data: {
                data: [
                    'backend-review:1.0',
                    { skill: 'frontend-review:1.2', schema: 'review:1.2' },
                    123,
                    { skill: 999 },
                ],
            },
        });

        await store.fetchPromptVersions();

        expect(store.promptVersions).toEqual([
            { skill: 'backend-review:1.0' },
            { skill: 'frontend-review:1.2', schema: 'review:1.2' },
        ]);
    });

    it('fetchPromptVersions clears promptVersions when API payload is not an array', async () => {
        store.promptVersions = [{ skill: 'stale:1.0' }];
        mockedAxios.get.mockResolvedValueOnce({ data: { data: { skill: 'invalid-shape' } } });

        await store.fetchPromptVersions();

        expect(store.promptVersions).toEqual([]);
    });

    it('fetchQuality passes prompt_version param when filter is set', async () => {
        store.promptVersionFilter = 'frontend-review:1.0';
        mockedAxios.get.mockResolvedValueOnce({ data: { data: {} } });

        await store.fetchQuality();

        expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/dashboard/quality', {
            params: { prompt_version: 'frontend-review:1.0' },
        });
    });

    it('fetchQuality sends no prompt_version when filter is null', async () => {
        store.promptVersionFilter = null;
        mockedAxios.get.mockResolvedValueOnce({ data: { data: {} } });

        await store.fetchQuality();

        expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/dashboard/quality', {
            params: {},
        });
    });

    it('$reset clears promptVersionFilter and promptVersions', () => {
        store.promptVersionFilter = 'test:1.0';
        store.promptVersions = [{ skill: 'test:1.0' }];

        store.$reset();

        expect(store.promptVersionFilter).toBe(null);
        expect(store.promptVersions).toEqual([]);
    });
});

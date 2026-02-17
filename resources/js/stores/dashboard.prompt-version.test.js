import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useDashboardStore } from './dashboard';

vi.mock('axios');

describe('dashboard store — prompt version filter', () => {
    let store;

    beforeEach(() => {
        setActivePinia(createPinia());
        store = useDashboardStore();
        axios.get.mockResolvedValue({ data: { data: [] } });
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
        axios.get.mockResolvedValueOnce({ data: { data: mockVersions } });

        await store.fetchPromptVersions();

        expect(store.promptVersions).toEqual(mockVersions);
        expect(axios.get).toHaveBeenCalledWith('/api/v1/prompt-versions');
    });

    it('fetchQuality passes prompt_version param when filter is set', async () => {
        store.promptVersionFilter = 'frontend-review:1.0';
        axios.get.mockResolvedValueOnce({ data: { data: {} } });

        await store.fetchQuality();

        expect(axios.get).toHaveBeenCalledWith('/api/v1/dashboard/quality', {
            params: { prompt_version: 'frontend-review:1.0' },
        });
    });

    it('fetchQuality sends no prompt_version when filter is null', async () => {
        store.promptVersionFilter = null;
        axios.get.mockResolvedValueOnce({ data: { data: {} } });

        await store.fetchQuality();

        expect(axios.get).toHaveBeenCalledWith('/api/v1/dashboard/quality', {
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

import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAdminStore } from '@/features/admin';

vi.mock('axios');

const mockedAxios = vi.mocked(axios, true);

describe('admin Store — Settings (T90)', () => {
    let store: ReturnType<typeof useAdminStore>;

    beforeEach(() => {
        setActivePinia(createPinia());
        store = useAdminStore();
        vi.clearAllMocks();
    });

    it('initializes settings as empty array', () => {
        expect(store.settings).toEqual([]);
    });

    it('initializes apiKeyConfigured as false', () => {
        expect(store.apiKeyConfigured).toBe(false);
    });

    it('initializes settingsDefaults as empty object', () => {
        expect(store.settingsDefaults).toEqual({});
    });

    describe('fetchSettings', () => {
        it('loads settings from API', async () => {
            mockedAxios.get.mockResolvedValue({
                data: {
                    data: [
                        { key: 'ai_model', value: 'opus', type: 'string', description: 'Default AI model' },
                    ],
                    api_key_configured: true,
                    defaults: { ai_model: 'opus', ai_language: 'en', timeout_minutes: 10, max_tokens: 8192 },
                },
            });

            await store.fetchSettings();

            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/admin/settings');
            expect(store.settings).toHaveLength(1);
            expect(store.settings[0].key).toBe('ai_model');
            expect(store.apiKeyConfigured).toBe(true);
            expect(store.settingsDefaults.ai_model).toBe('opus');
        });

        it('sets settingsError on failure', async () => {
            mockedAxios.get.mockRejectedValue(new Error('Network error'));

            await store.fetchSettings();

            expect(store.settingsError).toBe('Failed to load settings.');
        });

        it('sets settingsLoading during fetch', async () => {
            let resolvePromise: (value: unknown) => void;
            mockedAxios.get.mockReturnValue(new Promise((resolve) => {
                resolvePromise = resolve;
            }) as never);

            const fetchPromise = store.fetchSettings();
            expect(store.settingsLoading).toBe(true);

            resolvePromise!({ data: { data: [], api_key_configured: false, defaults: {} } });
            await fetchPromise;

            expect(store.settingsLoading).toBe(false);
        });
    });

    describe('updateSettings', () => {
        it('sends PUT request and updates store', async () => {
            mockedAxios.put.mockResolvedValue({
                data: {
                    success: true,
                    data: [
                        { key: 'ai_model', value: 'sonnet', type: 'string', description: 'Default AI model' },
                    ],
                },
            });

            const result = await store.updateSettings([
                { key: 'ai_model', value: 'sonnet', type: 'string' },
            ]);

            expect(mockedAxios.put).toHaveBeenCalledWith('/api/v1/admin/settings', {
                settings: [{ key: 'ai_model', value: 'sonnet', type: 'string' }],
            });
            expect(result.success).toBe(true);
            expect(store.settings[0].value).toBe('sonnet');
        });

        it('returns error on failure', async () => {
            mockedAxios.put.mockRejectedValue({
                response: { data: { error: 'Validation failed' } },
            });

            const result = await store.updateSettings([
                { key: 'ai_model', value: '', type: 'string' },
            ]);

            expect(result.success).toBe(false);
            expect(result.error).toBe('Validation failed');
        });
    });
});

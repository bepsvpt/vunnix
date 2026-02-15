import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import axios from 'axios';
import { useAdminStore } from './admin';

vi.mock('axios');

describe('admin store', () => {
    let admin;

    beforeEach(() => {
        setActivePinia(createPinia());
        admin = useAdminStore();
        vi.clearAllMocks();
    });

    describe('fetchProjects', () => {
        it('fetches and stores project list', async () => {
            const projects = [
                { id: 1, name: 'Project A', enabled: true, webhook_configured: true },
                { id: 2, name: 'Project B', enabled: false, webhook_configured: false },
            ];
            axios.get.mockResolvedValue({ data: { data: projects } });

            await admin.fetchProjects();

            expect(admin.projects).toEqual(projects);
            expect(admin.loading).toBe(false);
            expect(axios.get).toHaveBeenCalledWith('/api/v1/admin/projects');
        });

        it('sets loading state during fetch', async () => {
            let resolvePromise;
            axios.get.mockReturnValue(
                new Promise((resolve) => {
                    resolvePromise = resolve;
                }),
            );

            const fetchPromise = admin.fetchProjects();
            expect(admin.loading).toBe(true);

            resolvePromise({ data: { data: [] } });
            await fetchPromise;
            expect(admin.loading).toBe(false);
        });

        it('handles fetch error', async () => {
            axios.get.mockRejectedValue(new Error('Network error'));

            await admin.fetchProjects();

            expect(admin.error).toBe('Failed to load projects.');
            expect(admin.loading).toBe(false);
        });
    });

    describe('enableProject', () => {
        it('calls enable API and updates project in list', async () => {
            admin.projects = [{ id: 1, name: 'Project A', enabled: false }];

            const updatedProject = { id: 1, name: 'Project A', enabled: true, webhook_configured: true };
            axios.post.mockResolvedValue({
                data: { success: true, warnings: [], data: updatedProject },
            });

            const result = await admin.enableProject(1);

            expect(result.success).toBe(true);
            expect(admin.projects[0].enabled).toBe(true);
            expect(axios.post).toHaveBeenCalledWith('/api/v1/admin/projects/1/enable');
        });

        it('returns error details on failure', async () => {
            admin.projects = [{ id: 1, enabled: false }];

            axios.post.mockRejectedValue({
                response: { data: { success: false, error: 'Bot not a member' } },
            });

            const result = await admin.enableProject(1);

            expect(result.success).toBe(false);
            expect(result.error).toBe('Bot not a member');
        });
    });

    describe('disableProject', () => {
        it('calls disable API and updates project in list', async () => {
            admin.projects = [{ id: 1, name: 'Project A', enabled: true }];

            const updatedProject = { id: 1, name: 'Project A', enabled: false, webhook_configured: false };
            axios.post.mockResolvedValue({
                data: { success: true, data: updatedProject },
            });

            const result = await admin.disableProject(1);

            expect(result.success).toBe(true);
            expect(admin.projects[0].enabled).toBe(false);
            expect(axios.post).toHaveBeenCalledWith('/api/v1/admin/projects/1/disable');
        });
    });
});

import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAdminStore } from '@/features/admin';

vi.mock('axios');

const mockedAxios = vi.mocked(axios, true);

describe('admin store', () => {
    let admin: ReturnType<typeof useAdminStore>;

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
            mockedAxios.get.mockResolvedValue({ data: { data: projects } });

            await admin.fetchProjects();

            expect(admin.projects).toEqual(projects);
            expect(admin.loading).toBe(false);
            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/admin/projects');
        });

        it('sets loading state during fetch', async () => {
            let resolvePromise: (value: unknown) => void;
            mockedAxios.get.mockReturnValue(
                new Promise((resolve) => {
                    resolvePromise = resolve;
                }) as never,
            );

            const fetchPromise = admin.fetchProjects();
            expect(admin.loading).toBe(true);

            resolvePromise!({ data: { data: [] } });
            await fetchPromise;
            expect(admin.loading).toBe(false);
        });

        it('handles fetch error', async () => {
            mockedAxios.get.mockRejectedValue(new Error('Network error'));

            await admin.fetchProjects();

            expect(admin.error).toBe('Failed to load projects.');
            expect(admin.loading).toBe(false);
        });
    });

    describe('enableProject', () => {
        it('calls enable API and updates project in list', async () => {
            admin.projects = [{ id: 1, name: 'Project A', enabled: false }];

            const updatedProject = { id: 1, name: 'Project A', enabled: true, webhook_configured: true };
            mockedAxios.post.mockResolvedValue({
                data: { success: true, warnings: [], data: updatedProject },
            });

            const result = await admin.enableProject(1);

            expect(result.success).toBe(true);
            expect(admin.projects[0].enabled).toBe(true);
            expect(mockedAxios.post).toHaveBeenCalledWith('/api/v1/admin/projects/1/enable');
        });

        it('returns error details on failure', async () => {
            admin.projects = [{ id: 1, enabled: false }];

            mockedAxios.post.mockRejectedValue({
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
            mockedAxios.post.mockResolvedValue({
                data: { success: true, data: updatedProject },
            });

            const result = await admin.disableProject(1);

            expect(result.success).toBe(true);
            expect(admin.projects[0].enabled).toBe(false);
            expect(mockedAxios.post).toHaveBeenCalledWith('/api/v1/admin/projects/1/disable');
        });
    });

    // --- Per-project config (T91) ---

    describe('fetchProjectConfig', () => {
        it('populates projectConfig state', async () => {
            mockedAxios.get.mockResolvedValue({
                data: {
                    data: {
                        settings: { ai_model: 'sonnet' },
                        effective: {
                            ai_model: { value: 'sonnet', source: 'project' },
                            ai_language: { value: 'en', source: 'default' },
                        },
                        setting_keys: { ai_model: 'string' },
                    },
                },
            });

            await admin.fetchProjectConfig(42);

            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/admin/projects/42/config');
            expect(admin.projectConfig).toEqual({
                settings: { ai_model: 'sonnet' },
                effective: {
                    ai_model: { value: 'sonnet', source: 'project' },
                    ai_language: { value: 'en', source: 'default' },
                },
                setting_keys: { ai_model: 'string' },
            });
        });

        it('sets loading state', async () => {
            let resolvePromise: (value: unknown) => void;
            mockedAxios.get.mockReturnValue(new Promise((r) => {
                resolvePromise = r;
            }) as never);

            const promise = admin.fetchProjectConfig(42);
            expect(admin.projectConfigLoading).toBe(true);

            resolvePromise!({ data: { data: { settings: {}, effective: {}, setting_keys: {} } } });
            await promise;

            expect(admin.projectConfigLoading).toBe(false);
        });

        it('sets error on failure', async () => {
            mockedAxios.get.mockRejectedValue(new Error('Network error'));

            await admin.fetchProjectConfig(42);

            expect(admin.projectConfigError).toBe('Failed to load project configuration.');
        });
    });

    describe('updateProjectConfig', () => {
        it('sends PUT and updates state', async () => {
            mockedAxios.put.mockResolvedValue({
                data: {
                    success: true,
                    data: {
                        settings: { ai_model: 'haiku' },
                        effective: { ai_model: { value: 'haiku', source: 'project' } },
                        setting_keys: {},
                    },
                },
            });

            const result = await admin.updateProjectConfig(42, { ai_model: 'haiku' });

            expect(mockedAxios.put).toHaveBeenCalledWith('/api/v1/admin/projects/42/config', {
                settings: { ai_model: 'haiku' },
            });
            expect(result.success).toBe(true);
            expect(admin.projectConfig.settings).toEqual({ ai_model: 'haiku' });
        });

        it('returns error on failure', async () => {
            mockedAxios.put.mockRejectedValue({
                response: { data: { error: 'Validation failed' } },
            });

            const result = await admin.updateProjectConfig(42, { ai_model: 'bad' });

            expect(result.success).toBe(false);
            expect(result.error).toBe('Validation failed');
        });
    });

    // --- Role management (T89) ---

    describe('fetchRoles', () => {
        it('fetches and stores roles list', async () => {
            const roles = [
                { id: 1, name: 'Admin', description: 'Full access', project_id: 1, permissions: ['*'] },
                { id: 2, name: 'Reviewer', description: 'Review only', project_id: 1, permissions: ['review'] },
            ];
            mockedAxios.get.mockResolvedValue({ data: { data: roles } });

            await admin.fetchRoles();

            expect(admin.roles).toEqual(roles);
            expect(admin.rolesLoading).toBe(false);
            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/admin/roles', { params: {} });
        });

        it('passes project_id as param when provided', async () => {
            mockedAxios.get.mockResolvedValue({ data: { data: [] } });

            await admin.fetchRoles(5);

            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/admin/roles', { params: { project_id: 5 } });
        });

        it('sets loading state during fetch', async () => {
            let resolvePromise: (value: unknown) => void;
            mockedAxios.get.mockReturnValue(
                new Promise((resolve) => {
                    resolvePromise = resolve;
                }) as never,
            );

            const fetchPromise = admin.fetchRoles();
            expect(admin.rolesLoading).toBe(true);

            resolvePromise!({ data: { data: [] } });
            await fetchPromise;
            expect(admin.rolesLoading).toBe(false);
        });

        it('sets error on failure', async () => {
            mockedAxios.get.mockRejectedValue(new Error('Network error'));

            await admin.fetchRoles();

            expect(admin.rolesError).toBe('Failed to load roles.');
            expect(admin.rolesLoading).toBe(false);
        });
    });

    describe('fetchPermissions', () => {
        it('fetches and stores permissions list', async () => {
            const perms = ['review', 'manage', 'deploy'];
            mockedAxios.get.mockResolvedValue({ data: { data: perms } });

            await admin.fetchPermissions();

            expect(admin.permissions).toEqual(perms);
            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/admin/permissions');
        });

        it('silently handles errors', async () => {
            mockedAxios.get.mockRejectedValue(new Error('Network error'));

            await admin.fetchPermissions();

            expect(admin.permissions).toEqual([]);
        });
    });

    describe('createRole', () => {
        it('creates role and appends to list', async () => {
            const newRole = { id: 3, name: 'Tester', description: 'Test role', project_id: 1, permissions: ['test'] };
            mockedAxios.post.mockResolvedValue({
                data: { success: true, data: newRole },
            });

            const payload = { name: 'Tester', description: 'Test role', project_id: 1, permissions: ['test'] };
            const result = await admin.createRole(payload);

            expect(result.success).toBe(true);
            expect(admin.roles).toContainEqual(newRole);
            expect(mockedAxios.post).toHaveBeenCalledWith('/api/v1/admin/roles', payload);
        });

        it('returns error on failure', async () => {
            mockedAxios.post.mockRejectedValue({
                response: { data: { error: 'Role name taken' } },
            });

            const result = await admin.createRole({ name: 'Admin', project_id: 1 });

            expect(result.success).toBe(false);
            expect(result.error).toBe('Role name taken');
        });

        it('uses fallback error message when no response data', async () => {
            mockedAxios.post.mockRejectedValue(new Error('Network error'));

            const result = await admin.createRole({ name: 'Admin', project_id: 1 });

            expect(result.success).toBe(false);
            expect(result.error).toBe('Failed to create role.');
        });
    });

    describe('updateRole', () => {
        it('updates role and replaces in list', async () => {
            admin.roles = [
                { id: 1, name: 'Admin', description: 'Old', project_id: 1, permissions: [] },
            ] as never;
            const updatedRole = { id: 1, name: 'Admin', description: 'Updated', project_id: 1, permissions: ['manage'] };
            mockedAxios.put.mockResolvedValue({
                data: { success: true, data: updatedRole },
            });

            const result = await admin.updateRole(1, { description: 'Updated', permissions: ['manage'] });

            expect(result.success).toBe(true);
            expect(admin.roles[0]).toEqual(updatedRole);
            expect(mockedAxios.put).toHaveBeenCalledWith('/api/v1/admin/roles/1', { description: 'Updated', permissions: ['manage'] });
        });

        it('returns error on failure', async () => {
            mockedAxios.put.mockRejectedValue({
                response: { data: { error: 'Invalid permissions' } },
            });

            const result = await admin.updateRole(1, { permissions: ['bad'] });

            expect(result.success).toBe(false);
            expect(result.error).toBe('Invalid permissions');
        });

        it('uses fallback error message when no response data', async () => {
            mockedAxios.put.mockRejectedValue(new Error('Network error'));

            const result = await admin.updateRole(1, {});

            expect(result.success).toBe(false);
            expect(result.error).toBe('Failed to update role.');
        });
    });

    describe('deleteRole', () => {
        it('deletes role and removes from list', async () => {
            admin.roles = [
                { id: 1, name: 'Admin' },
                { id: 2, name: 'Reviewer' },
            ] as never;
            mockedAxios.delete.mockResolvedValue({
                data: { success: true },
            });

            const result = await admin.deleteRole(1);

            expect(result.success).toBe(true);
            expect(admin.roles).toHaveLength(1);
            expect(admin.roles[0].id).toBe(2);
            expect(mockedAxios.delete).toHaveBeenCalledWith('/api/v1/admin/roles/1');
        });

        it('returns error on failure', async () => {
            mockedAxios.delete.mockRejectedValue({
                response: { data: { error: 'Role in use' } },
            });

            const result = await admin.deleteRole(1);

            expect(result.success).toBe(false);
            expect(result.error).toBe('Role in use');
        });

        it('uses fallback error message when no response data', async () => {
            mockedAxios.delete.mockRejectedValue(new Error('Network error'));

            const result = await admin.deleteRole(1);

            expect(result.success).toBe(false);
            expect(result.error).toBe('Failed to delete role.');
        });
    });

    // --- Role assignments ---

    describe('fetchAssignments', () => {
        it('fetches and stores assignments', async () => {
            const assignments = [
                { id: 1, user_id: 10, role_id: 1 },
                { id: 2, user_id: 20, role_id: 2 },
            ];
            mockedAxios.get.mockResolvedValue({ data: { data: assignments } });

            await admin.fetchAssignments();

            expect(admin.roleAssignments).toEqual(assignments);
            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/admin/role-assignments', { params: {} });
        });

        it('passes project_id as param when provided', async () => {
            mockedAxios.get.mockResolvedValue({ data: { data: [] } });

            await admin.fetchAssignments(7);

            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/admin/role-assignments', { params: { project_id: 7 } });
        });

        it('sets rolesError on failure', async () => {
            mockedAxios.get.mockRejectedValue(new Error('Network error'));

            await admin.fetchAssignments();

            expect(admin.rolesError).toBe('Failed to load role assignments.');
        });
    });

    describe('assignRole', () => {
        it('calls POST and returns success', async () => {
            mockedAxios.post.mockResolvedValue({ data: { success: true } });

            const payload = { user_id: 10, role_id: 1 };
            const result = await admin.assignRole(payload);

            expect(result.success).toBe(true);
            expect(mockedAxios.post).toHaveBeenCalledWith('/api/v1/admin/role-assignments', payload);
        });

        it('returns error on failure', async () => {
            mockedAxios.post.mockRejectedValue({
                response: { data: { error: 'User already has this role' } },
            });

            const result = await admin.assignRole({ user_id: 10, role_id: 1 });

            expect(result.success).toBe(false);
            expect(result.error).toBe('User already has this role');
        });

        it('uses fallback error message when no response data', async () => {
            mockedAxios.post.mockRejectedValue(new Error('Network error'));

            const result = await admin.assignRole({ user_id: 10, role_id: 1 });

            expect(result.success).toBe(false);
            expect(result.error).toBe('Failed to assign role.');
        });
    });

    describe('revokeRole', () => {
        it('calls DELETE with data payload and returns success', async () => {
            mockedAxios.delete.mockResolvedValue({ data: { success: true } });

            const payload = { user_id: 10, role_id: 1 };
            const result = await admin.revokeRole(payload);

            expect(result.success).toBe(true);
            expect(mockedAxios.delete).toHaveBeenCalledWith('/api/v1/admin/role-assignments', { data: payload });
        });

        it('returns error on failure', async () => {
            mockedAxios.delete.mockRejectedValue({
                response: { data: { error: 'Assignment not found' } },
            });

            const result = await admin.revokeRole({ user_id: 10, role_id: 1 });

            expect(result.success).toBe(false);
            expect(result.error).toBe('Assignment not found');
        });

        it('uses fallback error message when no response data', async () => {
            mockedAxios.delete.mockRejectedValue(new Error('Network error'));

            const result = await admin.revokeRole({ user_id: 10, role_id: 1 });

            expect(result.success).toBe(false);
            expect(result.error).toBe('Failed to revoke role.');
        });
    });

    describe('fetchUsers', () => {
        it('fetches and stores users list', async () => {
            const usersList = [
                { id: 10, name: 'Alice', email: 'alice@example.com' },
                { id: 20, name: 'Bob', email: 'bob@example.com' },
            ];
            mockedAxios.get.mockResolvedValue({ data: { data: usersList } });

            await admin.fetchUsers();

            expect(admin.users).toEqual(usersList);
            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/admin/users');
        });

        it('silently handles errors', async () => {
            mockedAxios.get.mockRejectedValue(new Error('Network error'));

            await admin.fetchUsers();

            expect(admin.users).toEqual([]);
        });
    });

    // --- Global settings (T90) ---

    describe('fetchSettings', () => {
        it('fetches and stores settings, api_key_configured, and defaults', async () => {
            const settingsList = [
                { key: 'ai_model', value: 'opus', label: 'AI Model' },
                { key: 'max_tokens', value: 4096, label: 'Max Tokens' },
            ];
            mockedAxios.get.mockResolvedValue({
                data: {
                    data: settingsList,
                    api_key_configured: true,
                    defaults: { ai_model: 'sonnet', max_tokens: 2048 },
                },
            });

            await admin.fetchSettings();

            expect(admin.settings).toEqual(settingsList);
            expect(admin.apiKeyConfigured).toBe(true);
            expect(admin.settingsDefaults).toEqual({ ai_model: 'sonnet', max_tokens: 2048 });
            expect(admin.settingsLoading).toBe(false);
            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/admin/settings');
        });

        it('sets loading state during fetch', async () => {
            let resolvePromise: (value: unknown) => void;
            mockedAxios.get.mockReturnValue(
                new Promise((resolve) => {
                    resolvePromise = resolve;
                }) as never,
            );

            const fetchPromise = admin.fetchSettings();
            expect(admin.settingsLoading).toBe(true);

            resolvePromise!({ data: { data: [], api_key_configured: false, defaults: {} } });
            await fetchPromise;
            expect(admin.settingsLoading).toBe(false);
        });

        it('sets error on failure', async () => {
            mockedAxios.get.mockRejectedValue(new Error('Network error'));

            await admin.fetchSettings();

            expect(admin.settingsError).toBe('Failed to load settings.');
            expect(admin.settingsLoading).toBe(false);
        });
    });

    describe('updateSettings', () => {
        it('sends PUT with settings list and updates state', async () => {
            const updatedSettings = [
                { key: 'ai_model', value: 'haiku', label: 'AI Model' },
            ];
            mockedAxios.put.mockResolvedValue({
                data: { success: true, data: updatedSettings },
            });

            const payload = [{ key: 'ai_model', value: 'haiku' }];
            const result = await admin.updateSettings(payload);

            expect(result.success).toBe(true);
            expect(admin.settings).toEqual(updatedSettings);
            expect(mockedAxios.put).toHaveBeenCalledWith('/api/v1/admin/settings', {
                settings: payload,
            });
        });

        it('returns error on failure', async () => {
            mockedAxios.put.mockRejectedValue({
                response: { data: { error: 'Invalid setting key' } },
            });

            const result = await admin.updateSettings([{ key: 'bad_key', value: 'x' }]);

            expect(result.success).toBe(false);
            expect(result.error).toBe('Invalid setting key');
        });

        it('uses fallback error message when no response data', async () => {
            mockedAxios.put.mockRejectedValue(new Error('Network error'));

            const result = await admin.updateSettings([]);

            expect(result.success).toBe(false);
            expect(result.error).toBe('Failed to update settings.');
        });
    });

    describe('testWebhook', () => {
        it('sends POST and returns response data on success', async () => {
            mockedAxios.post.mockResolvedValue({
                data: { success: true, data: { status_code: 200 } },
            });

            const result = await admin.testWebhook('https://hooks.example.com/test', 'slack');

            expect(result.success).toBe(true);
            expect(mockedAxios.post).toHaveBeenCalledWith('/api/v1/admin/settings/test-webhook', {
                webhook_url: 'https://hooks.example.com/test',
                platform: 'slack',
            });
        });

        it('returns error on failure', async () => {
            mockedAxios.post.mockRejectedValue({
                response: { data: { error: 'Invalid URL' } },
            });

            const result = await admin.testWebhook('bad-url', 'slack');

            expect(result.success).toBe(false);
            expect(result.error).toBe('Invalid URL');
        });

        it('uses fallback error message when no response data', async () => {
            mockedAxios.post.mockRejectedValue(new Error('Network error'));

            const result = await admin.testWebhook('https://hooks.example.com/test', 'slack');

            expect(result.success).toBe(false);
            expect(result.error).toBe('Failed to test webhook.');
        });
    });

    // --- PRD templates (T93) ---

    describe('fetchPrdTemplate', () => {
        it('fetches and stores project PRD template', async () => {
            const template = { id: 1, template: '## PRD\n...', project_id: 42 };
            mockedAxios.get.mockResolvedValue({ data: { data: template } });

            await admin.fetchPrdTemplate(42);

            expect(admin.prdTemplate).toEqual(template);
            expect(admin.prdTemplateLoading).toBe(false);
            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/admin/projects/42/prd-template');
        });

        it('sets loading state during fetch', async () => {
            let resolvePromise: (value: unknown) => void;
            mockedAxios.get.mockReturnValue(
                new Promise((resolve) => {
                    resolvePromise = resolve;
                }) as never,
            );

            const fetchPromise = admin.fetchPrdTemplate(42);
            expect(admin.prdTemplateLoading).toBe(true);

            resolvePromise!({ data: { data: null } });
            await fetchPromise;
            expect(admin.prdTemplateLoading).toBe(false);
        });

        it('sets error on failure', async () => {
            mockedAxios.get.mockRejectedValue(new Error('Network error'));

            await admin.fetchPrdTemplate(42);

            expect(admin.prdTemplateError).toBe('Failed to load PRD template.');
            expect(admin.prdTemplateLoading).toBe(false);
        });
    });

    describe('updatePrdTemplate', () => {
        it('sends PUT with template and updates state', async () => {
            const updatedTemplate = { id: 1, template: '## Updated PRD', project_id: 42 };
            mockedAxios.put.mockResolvedValue({
                data: { success: true, data: updatedTemplate },
            });

            const result = await admin.updatePrdTemplate(42, '## Updated PRD');

            expect(result.success).toBe(true);
            expect(admin.prdTemplate).toEqual(updatedTemplate);
            expect(mockedAxios.put).toHaveBeenCalledWith('/api/v1/admin/projects/42/prd-template', {
                template: '## Updated PRD',
            });
        });

        it('returns error on failure', async () => {
            mockedAxios.put.mockRejectedValue({
                response: { data: { error: 'Template too long' } },
            });

            const result = await admin.updatePrdTemplate(42, 'x'.repeat(100000));

            expect(result.success).toBe(false);
            expect(result.error).toBe('Template too long');
        });

        it('uses fallback error message when no response data', async () => {
            mockedAxios.put.mockRejectedValue(new Error('Network error'));

            const result = await admin.updatePrdTemplate(42, '## PRD');

            expect(result.success).toBe(false);
            expect(result.error).toBe('Failed to update PRD template.');
        });
    });

    describe('fetchGlobalPrdTemplate', () => {
        it('fetches and stores global PRD template', async () => {
            const template = { id: 1, template: '## Global PRD\n...' };
            mockedAxios.get.mockResolvedValue({ data: { data: template } });

            await admin.fetchGlobalPrdTemplate();

            expect(admin.globalPrdTemplate).toEqual(template);
            expect(admin.globalPrdTemplateLoading).toBe(false);
            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/admin/prd-template');
        });

        it('sets loading state during fetch', async () => {
            let resolvePromise: (value: unknown) => void;
            mockedAxios.get.mockReturnValue(
                new Promise((resolve) => {
                    resolvePromise = resolve;
                }) as never,
            );

            const fetchPromise = admin.fetchGlobalPrdTemplate();
            expect(admin.globalPrdTemplateLoading).toBe(true);

            resolvePromise!({ data: { data: null } });
            await fetchPromise;
            expect(admin.globalPrdTemplateLoading).toBe(false);
        });

        it('silently handles errors', async () => {
            mockedAxios.get.mockRejectedValue(new Error('Network error'));

            await admin.fetchGlobalPrdTemplate();

            expect(admin.globalPrdTemplate).toBeNull();
            expect(admin.globalPrdTemplateLoading).toBe(false);
        });
    });

    describe('updateGlobalPrdTemplate', () => {
        it('sends PUT with template and updates state', async () => {
            const updatedTemplate = { id: 1, template: '## Updated Global PRD' };
            mockedAxios.put.mockResolvedValue({
                data: { success: true, data: updatedTemplate },
            });

            const result = await admin.updateGlobalPrdTemplate('## Updated Global PRD');

            expect(result.success).toBe(true);
            expect(admin.globalPrdTemplate).toEqual(updatedTemplate);
            expect(mockedAxios.put).toHaveBeenCalledWith('/api/v1/admin/prd-template', {
                template: '## Updated Global PRD',
            });
        });

        it('returns error on failure', async () => {
            mockedAxios.put.mockRejectedValue({
                response: { data: { error: 'Forbidden' } },
            });

            const result = await admin.updateGlobalPrdTemplate('## PRD');

            expect(result.success).toBe(false);
            expect(result.error).toBe('Forbidden');
        });

        it('uses fallback error message when no response data', async () => {
            mockedAxios.put.mockRejectedValue(new Error('Network error'));

            const result = await admin.updateGlobalPrdTemplate('## PRD');

            expect(result.success).toBe(false);
            expect(result.error).toBe('Failed to update global PRD template.');
        });
    });

    // --- Cost alerts (T94) ---

    describe('fetchCostAlerts', () => {
        it('fetches and stores cost alerts', async () => {
            const alerts = [
                { id: 1, type: 'budget_exceeded', message: 'Budget exceeded for Project A' },
                { id: 2, type: 'rate_limit', message: 'Rate limit approaching' },
            ];
            mockedAxios.get.mockResolvedValue({ data: { data: alerts } });

            await admin.fetchCostAlerts();

            expect(admin.costAlerts).toEqual(alerts);
            expect(admin.costAlertsLoading).toBe(false);
            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/dashboard/cost-alerts');
        });

        it('sets loading state during fetch', async () => {
            let resolvePromise: (value: unknown) => void;
            mockedAxios.get.mockReturnValue(
                new Promise((resolve) => {
                    resolvePromise = resolve;
                }) as never,
            );

            const fetchPromise = admin.fetchCostAlerts();
            expect(admin.costAlertsLoading).toBe(true);

            resolvePromise!({ data: { data: [] } });
            await fetchPromise;
            expect(admin.costAlertsLoading).toBe(false);
        });

        it('sets error on failure', async () => {
            mockedAxios.get.mockRejectedValue(new Error('Network error'));

            await admin.fetchCostAlerts();

            expect(admin.costAlertsError).toBe('Failed to load cost alerts.');
            expect(admin.costAlertsLoading).toBe(false);
        });
    });

    describe('acknowledgeCostAlert', () => {
        it('calls PATCH and removes alert from list', async () => {
            admin.costAlerts = [
                { id: 1, type: 'budget_exceeded' },
                { id: 2, type: 'rate_limit' },
            ];
            mockedAxios.patch.mockResolvedValue({ data: { success: true } });

            const result = await admin.acknowledgeCostAlert(1);

            expect(result.success).toBe(true);
            expect(admin.costAlerts).toHaveLength(1);
            expect(admin.costAlerts[0].id).toBe(2);
            expect(mockedAxios.patch).toHaveBeenCalledWith('/api/v1/dashboard/cost-alerts/1/acknowledge');
        });

        it('returns error on failure', async () => {
            admin.costAlerts = [{ id: 1, type: 'budget_exceeded' }];
            mockedAxios.patch.mockRejectedValue({
                response: { data: { error: 'Alert not found' } },
            });

            const result = await admin.acknowledgeCostAlert(1);

            expect(result.success).toBe(false);
            expect(result.error).toBe('Alert not found');
            // Alert should not be removed on failure
            expect(admin.costAlerts).toHaveLength(1);
        });

        it('uses fallback error message when no response data', async () => {
            mockedAxios.patch.mockRejectedValue(new Error('Network error'));

            const result = await admin.acknowledgeCostAlert(99);

            expect(result.success).toBe(false);
            expect(result.error).toBe('Failed to acknowledge alert.');
        });
    });

    // --- Over-reliance alerts (T95) ---

    describe('fetchOverrelianceAlerts', () => {
        it('fetches and stores over-reliance alerts', async () => {
            const alerts = [
                { id: 1, user_id: 10, score: 0.95, message: 'High AI reliance detected' },
            ];
            mockedAxios.get.mockResolvedValue({ data: { data: alerts } });

            await admin.fetchOverrelianceAlerts();

            expect(admin.overrelianceAlerts).toEqual(alerts);
            expect(admin.overrelianceAlertsLoading).toBe(false);
            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/dashboard/overreliance-alerts');
        });

        it('sets loading state during fetch', async () => {
            let resolvePromise: (value: unknown) => void;
            mockedAxios.get.mockReturnValue(
                new Promise((resolve) => {
                    resolvePromise = resolve;
                }) as never,
            );

            const fetchPromise = admin.fetchOverrelianceAlerts();
            expect(admin.overrelianceAlertsLoading).toBe(true);

            resolvePromise!({ data: { data: [] } });
            await fetchPromise;
            expect(admin.overrelianceAlertsLoading).toBe(false);
        });

        it('sets error on failure', async () => {
            mockedAxios.get.mockRejectedValue(new Error('Network error'));

            await admin.fetchOverrelianceAlerts();

            expect(admin.overrelianceAlertsError).toBe('Failed to load over-reliance alerts.');
            expect(admin.overrelianceAlertsLoading).toBe(false);
        });
    });

    describe('acknowledgeOverrelianceAlert', () => {
        it('calls PATCH and removes alert from list', async () => {
            admin.overrelianceAlerts = [
                { id: 1, user_id: 10, score: 0.95 },
                { id: 2, user_id: 20, score: 0.85 },
            ];
            mockedAxios.patch.mockResolvedValue({ data: { success: true } });

            const result = await admin.acknowledgeOverrelianceAlert(1);

            expect(result.success).toBe(true);
            expect(admin.overrelianceAlerts).toHaveLength(1);
            expect(admin.overrelianceAlerts[0].id).toBe(2);
            expect(mockedAxios.patch).toHaveBeenCalledWith('/api/v1/dashboard/overreliance-alerts/1/acknowledge');
        });

        it('returns error on failure', async () => {
            admin.overrelianceAlerts = [{ id: 1, user_id: 10 }];
            mockedAxios.patch.mockRejectedValue({
                response: { data: { error: 'Alert not found' } },
            });

            const result = await admin.acknowledgeOverrelianceAlert(1);

            expect(result.success).toBe(false);
            expect(result.error).toBe('Alert not found');
            expect(admin.overrelianceAlerts).toHaveLength(1);
        });

        it('uses fallback error message when no response data', async () => {
            mockedAxios.patch.mockRejectedValue(new Error('Network error'));

            const result = await admin.acknowledgeOverrelianceAlert(99);

            expect(result.success).toBe(false);
            expect(result.error).toBe('Failed to acknowledge alert.');
        });
    });

    // --- Infrastructure alerts (T104) ---

    describe('acknowledgeInfrastructureAlert', () => {
        it('calls PATCH and returns success', async () => {
            mockedAxios.patch.mockResolvedValue({ data: { success: true } });

            const result = await admin.acknowledgeInfrastructureAlert(5);

            expect(result.success).toBe(true);
            expect(mockedAxios.patch).toHaveBeenCalledWith('/api/v1/dashboard/infrastructure-alerts/5/acknowledge');
        });

        it('returns error on failure', async () => {
            mockedAxios.patch.mockRejectedValue({
                response: { data: { error: 'Alert not found' } },
            });

            const result = await admin.acknowledgeInfrastructureAlert(5);

            expect(result.success).toBe(false);
            expect(result.error).toBe('Alert not found');
        });

        it('uses fallback error message when no response data', async () => {
            mockedAxios.patch.mockRejectedValue(new Error('Network error'));

            const result = await admin.acknowledgeInfrastructureAlert(5);

            expect(result.success).toBe(false);
            expect(result.error).toBe('Failed to acknowledge alert.');
        });
    });

    // --- Dead letter queue (T97) ---

    describe('fetchDeadLetterEntries', () => {
        it('fetches and stores dead letter entries', async () => {
            const entries = [
                { id: 1, job_class: 'PostSummaryComment', failed_at: '2026-01-15' },
                { id: 2, job_class: 'PostInlineThreads', failed_at: '2026-01-16' },
            ];
            mockedAxios.get.mockResolvedValue({ data: { data: entries } });

            await admin.fetchDeadLetterEntries();

            expect(admin.deadLetterEntries).toEqual(entries);
            expect(admin.deadLetterLoading).toBe(false);
            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/admin/dead-letter', { params: {} });
        });

        it('passes filters as params', async () => {
            mockedAxios.get.mockResolvedValue({ data: { data: [] } });

            await admin.fetchDeadLetterEntries({ status: 'failed', job_class: 'PostSummaryComment' });

            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/admin/dead-letter', {
                params: { status: 'failed', job_class: 'PostSummaryComment' },
            });
        });

        it('sets loading state during fetch', async () => {
            let resolvePromise: (value: unknown) => void;
            mockedAxios.get.mockReturnValue(
                new Promise((resolve) => {
                    resolvePromise = resolve;
                }) as never,
            );

            const fetchPromise = admin.fetchDeadLetterEntries();
            expect(admin.deadLetterLoading).toBe(true);

            resolvePromise!({ data: { data: [] } });
            await fetchPromise;
            expect(admin.deadLetterLoading).toBe(false);
        });

        it('sets error on failure', async () => {
            mockedAxios.get.mockRejectedValue(new Error('Network error'));

            await admin.fetchDeadLetterEntries();

            expect(admin.deadLetterError).toBe('Failed to load dead letter queue.');
            expect(admin.deadLetterLoading).toBe(false);
        });
    });

    describe('fetchDeadLetterDetail', () => {
        it('fetches and stores entry detail', async () => {
            const detail = {
                id: 1,
                job_class: 'PostSummaryComment',
                payload: { task_id: 'abc' },
                exception: 'Connection refused',
                failed_at: '2026-01-15',
            };
            mockedAxios.get.mockResolvedValue({ data: { data: detail } });

            await admin.fetchDeadLetterDetail(1);

            expect(admin.deadLetterDetail).toEqual(detail);
            expect(admin.deadLetterDetailLoading).toBe(false);
            expect(mockedAxios.get).toHaveBeenCalledWith('/api/v1/admin/dead-letter/1');
        });

        it('sets loading state during fetch', async () => {
            let resolvePromise: (value: unknown) => void;
            mockedAxios.get.mockReturnValue(
                new Promise((resolve) => {
                    resolvePromise = resolve;
                }) as never,
            );

            const fetchPromise = admin.fetchDeadLetterDetail(1);
            expect(admin.deadLetterDetailLoading).toBe(true);

            resolvePromise!({ data: { data: { id: 1 } } });
            await fetchPromise;
            expect(admin.deadLetterDetailLoading).toBe(false);
        });

        it('sets error on failure', async () => {
            mockedAxios.get.mockRejectedValue(new Error('Network error'));

            await admin.fetchDeadLetterDetail(1);

            expect(admin.deadLetterError).toBe('Failed to load entry details.');
            expect(admin.deadLetterDetailLoading).toBe(false);
        });
    });

    describe('retryDeadLetterEntry', () => {
        it('calls POST and removes entry from list on success', async () => {
            admin.deadLetterEntries = [
                { id: 1, job_class: 'PostSummaryComment' },
                { id: 2, job_class: 'PostInlineThreads' },
            ];
            admin.deadLetterDetail = { id: 1, job_class: 'PostSummaryComment' };
            mockedAxios.post.mockResolvedValue({
                data: { success: true, data: { job_id: 'new-job-123' } },
            });

            const result = await admin.retryDeadLetterEntry(1);

            expect(result.success).toBe(true);
            expect(result.data).toEqual({ job_id: 'new-job-123' });
            expect(admin.deadLetterEntries).toHaveLength(1);
            expect(admin.deadLetterEntries[0].id).toBe(2);
            expect(admin.deadLetterDetail).toBeNull();
            expect(mockedAxios.post).toHaveBeenCalledWith('/api/v1/admin/dead-letter/1/retry');
        });

        it('does not remove entry when response success is false', async () => {
            admin.deadLetterEntries = [
                { id: 1, job_class: 'PostSummaryComment' },
            ];
            mockedAxios.post.mockResolvedValue({
                data: { success: false },
            });

            const result = await admin.retryDeadLetterEntry(1);

            expect(result.success).toBe(true);
            expect(admin.deadLetterEntries).toHaveLength(1);
        });

        it('returns error on failure', async () => {
            admin.deadLetterEntries = [{ id: 1 }];
            mockedAxios.post.mockRejectedValue({
                response: { data: { error: 'Job class not found' } },
            });

            const result = await admin.retryDeadLetterEntry(1);

            expect(result.success).toBe(false);
            expect(result.error).toBe('Job class not found');
            expect(admin.deadLetterEntries).toHaveLength(1);
        });

        it('uses fallback error message when no response data', async () => {
            mockedAxios.post.mockRejectedValue(new Error('Network error'));

            const result = await admin.retryDeadLetterEntry(1);

            expect(result.success).toBe(false);
            expect(result.error).toBe('Failed to retry entry.');
        });
    });

    describe('dismissDeadLetterEntry', () => {
        it('calls POST and removes entry from list', async () => {
            admin.deadLetterEntries = [
                { id: 1, job_class: 'PostSummaryComment' },
                { id: 2, job_class: 'PostInlineThreads' },
            ];
            admin.deadLetterDetail = { id: 1, job_class: 'PostSummaryComment' };
            mockedAxios.post.mockResolvedValue({ data: { success: true } });

            const result = await admin.dismissDeadLetterEntry(1);

            expect(result.success).toBe(true);
            expect(admin.deadLetterEntries).toHaveLength(1);
            expect(admin.deadLetterEntries[0].id).toBe(2);
            expect(admin.deadLetterDetail).toBeNull();
            expect(mockedAxios.post).toHaveBeenCalledWith('/api/v1/admin/dead-letter/1/dismiss');
        });

        it('returns error on failure', async () => {
            admin.deadLetterEntries = [{ id: 1 }];
            mockedAxios.post.mockRejectedValue({
                response: { data: { error: 'Entry not found' } },
            });

            const result = await admin.dismissDeadLetterEntry(1);

            expect(result.success).toBe(false);
            expect(result.error).toBe('Entry not found');
            expect(admin.deadLetterEntries).toHaveLength(1);
        });

        it('uses fallback error message when no response data', async () => {
            mockedAxios.post.mockRejectedValue(new Error('Network error'));

            const result = await admin.dismissDeadLetterEntry(1);

            expect(result.success).toBe(false);
            expect(result.error).toBe('Failed to dismiss entry.');
        });
    });
});

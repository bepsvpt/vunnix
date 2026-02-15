import { defineStore } from 'pinia';
import { ref } from 'vue';
import axios from 'axios';

export const useAdminStore = defineStore('admin', () => {
    const projects = ref([]);
    const loading = ref(false);
    const error = ref(null);

    async function fetchProjects() {
        loading.value = true;
        error.value = null;
        try {
            const { data } = await axios.get('/api/v1/admin/projects');
            projects.value = data.data;
        } catch (e) {
            error.value = 'Failed to load projects.';
        } finally {
            loading.value = false;
        }
    }

    async function enableProject(projectId) {
        try {
            const { data } = await axios.post(`/api/v1/admin/projects/${projectId}/enable`);
            if (data.success && data.data) {
                const idx = projects.value.findIndex((p) => p.id === projectId);
                if (idx !== -1) {
                    projects.value[idx] = data.data;
                }
            }
            return { success: true, warnings: data.warnings || [] };
        } catch (e) {
            const errorMsg = e.response?.data?.error || 'Failed to enable project.';
            return { success: false, error: errorMsg };
        }
    }

    async function disableProject(projectId) {
        try {
            const { data } = await axios.post(`/api/v1/admin/projects/${projectId}/disable`);
            if (data.success && data.data) {
                const idx = projects.value.findIndex((p) => p.id === projectId);
                if (idx !== -1) {
                    projects.value[idx] = data.data;
                }
            }
            return { success: true };
        } catch (e) {
            const errorMsg = e.response?.data?.error || 'Failed to disable project.';
            return { success: false, error: errorMsg };
        }
    }

    // ─── Role management state (T89) ────────────────────────────
    const roles = ref([]);
    const permissions = ref([]);
    const roleAssignments = ref([]);
    const users = ref([]);
    const rolesLoading = ref(false);
    const rolesError = ref(null);

    async function fetchRoles(projectId = null) {
        rolesLoading.value = true;
        rolesError.value = null;
        try {
            const params = projectId ? { project_id: projectId } : {};
            const { data } = await axios.get('/api/v1/admin/roles', { params });
            roles.value = data.data;
        } catch (e) {
            rolesError.value = 'Failed to load roles.';
        } finally {
            rolesLoading.value = false;
        }
    }

    async function fetchPermissions() {
        try {
            const { data } = await axios.get('/api/v1/admin/permissions');
            permissions.value = data.data;
        } catch (e) {
            // Permissions are supplementary — don't block on error
        }
    }

    async function createRole(payload) {
        try {
            const { data } = await axios.post('/api/v1/admin/roles', payload);
            if (data.success) {
                roles.value.push(data.data);
            }
            return { success: true };
        } catch (e) {
            return { success: false, error: e.response?.data?.error || 'Failed to create role.' };
        }
    }

    async function updateRole(roleId, payload) {
        try {
            const { data } = await axios.put(`/api/v1/admin/roles/${roleId}`, payload);
            if (data.success) {
                const idx = roles.value.findIndex((r) => r.id === roleId);
                if (idx !== -1) roles.value[idx] = data.data;
            }
            return { success: true };
        } catch (e) {
            return { success: false, error: e.response?.data?.error || 'Failed to update role.' };
        }
    }

    async function deleteRole(roleId) {
        try {
            const { data } = await axios.delete(`/api/v1/admin/roles/${roleId}`);
            if (data.success) {
                roles.value = roles.value.filter((r) => r.id !== roleId);
            }
            return { success: true };
        } catch (e) {
            return { success: false, error: e.response?.data?.error || 'Failed to delete role.' };
        }
    }

    async function fetchAssignments(projectId = null) {
        try {
            const params = projectId ? { project_id: projectId } : {};
            const { data } = await axios.get('/api/v1/admin/role-assignments', { params });
            roleAssignments.value = data.data;
        } catch (e) {
            rolesError.value = 'Failed to load role assignments.';
        }
    }

    async function assignRole(payload) {
        try {
            await axios.post('/api/v1/admin/role-assignments', payload);
            return { success: true };
        } catch (e) {
            return { success: false, error: e.response?.data?.error || 'Failed to assign role.' };
        }
    }

    async function revokeRole(payload) {
        try {
            await axios.delete('/api/v1/admin/role-assignments', { data: payload });
            return { success: true };
        } catch (e) {
            return { success: false, error: e.response?.data?.error || 'Failed to revoke role.' };
        }
    }

    async function fetchUsers() {
        try {
            const { data } = await axios.get('/api/v1/admin/users');
            users.value = data.data;
        } catch (e) {
            // Users list is supplementary
        }
    }

    // ─── Global settings state (T90) ─────────────────────────────
    const settings = ref([]);
    const settingsLoading = ref(false);
    const settingsError = ref(null);
    const apiKeyConfigured = ref(false);
    const settingsDefaults = ref({});

    async function fetchSettings() {
        settingsLoading.value = true;
        settingsError.value = null;
        try {
            const { data } = await axios.get('/api/v1/admin/settings');
            settings.value = data.data;
            apiKeyConfigured.value = data.api_key_configured;
            settingsDefaults.value = data.defaults;
        } catch (e) {
            settingsError.value = 'Failed to load settings.';
        } finally {
            settingsLoading.value = false;
        }
    }

    async function updateSettings(settingsList) {
        try {
            const { data } = await axios.put('/api/v1/admin/settings', {
                settings: settingsList,
            });
            if (data.success && data.data) {
                settings.value = data.data;
            }
            return { success: true };
        } catch (e) {
            return { success: false, error: e.response?.data?.error || 'Failed to update settings.' };
        }
    }

    // ─── Per-project configuration (T91) ────────────────────────
    const projectConfig = ref(null);
    const projectConfigLoading = ref(false);
    const projectConfigError = ref(null);

    async function fetchProjectConfig(projectId) {
        projectConfigLoading.value = true;
        projectConfigError.value = null;
        try {
            const { data } = await axios.get(`/api/v1/admin/projects/${projectId}/config`);
            projectConfig.value = data.data;
        } catch (e) {
            projectConfigError.value = 'Failed to load project configuration.';
        } finally {
            projectConfigLoading.value = false;
        }
    }

    async function updateProjectConfig(projectId, settings) {
        try {
            const { data } = await axios.put(`/api/v1/admin/projects/${projectId}/config`, {
                settings,
            });
            if (data.success && data.data) {
                projectConfig.value = data.data;
            }
            return { success: true };
        } catch (e) {
            return {
                success: false,
                error: e.response?.data?.error || 'Failed to update project configuration.',
            };
        }
    }

    return {
        projects,
        loading,
        error,
        fetchProjects,
        enableProject,
        disableProject,
        // Role management (T89)
        roles,
        permissions,
        roleAssignments,
        users,
        rolesLoading,
        rolesError,
        fetchRoles,
        fetchPermissions,
        createRole,
        updateRole,
        deleteRole,
        fetchAssignments,
        assignRole,
        revokeRole,
        fetchUsers,
        // Global settings (T90)
        settings,
        settingsLoading,
        settingsError,
        apiKeyConfigured,
        settingsDefaults,
        fetchSettings,
        updateSettings,
        // Per-project config (T91)
        projectConfig,
        projectConfigLoading,
        projectConfigError,
        fetchProjectConfig,
        updateProjectConfig,
    };
});

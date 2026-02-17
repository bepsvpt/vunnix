import axios from 'axios';
import { defineStore } from 'pinia';
import { ref } from 'vue';

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
        } catch {
            error.value = 'Failed to load projects.';
        } finally {
            loading.value = false;
        }
    }

    async function enableProject(projectId) {
        try {
            const { data } = await axios.post(`/api/v1/admin/projects/${projectId}/enable`);
            if (data.success && data.data) {
                const idx = projects.value.findIndex(p => p.id === projectId);
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
                const idx = projects.value.findIndex(p => p.id === projectId);
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
        } catch {
            rolesError.value = 'Failed to load roles.';
        } finally {
            rolesLoading.value = false;
        }
    }

    async function fetchPermissions() {
        try {
            const { data } = await axios.get('/api/v1/admin/permissions');
            permissions.value = data.data;
        } catch {
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
                const idx = roles.value.findIndex(r => r.id === roleId);
                if (idx !== -1)
                    roles.value[idx] = data.data;
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
                roles.value = roles.value.filter(r => r.id !== roleId);
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
        } catch {
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
        } catch {
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
        } catch {
            settingsError.value = 'Failed to load settings.';
        } finally {
            settingsLoading.value = false;
        }
    }

    async function testWebhook(webhookUrl, platform) {
        try {
            const response = await axios.post('/api/v1/admin/settings/test-webhook', {
                webhook_url: webhookUrl,
                platform,
            });
            return response.data;
        } catch (error) {
            return {
                success: false,
                message: error.response?.data?.message || 'Failed to test webhook.',
            };
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
        } catch {
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

    // ─── PRD template management (T93) ──────────────────────────
    const prdTemplate = ref(null);
    const prdTemplateLoading = ref(false);
    const prdTemplateError = ref(null);
    const globalPrdTemplate = ref(null);
    const globalPrdTemplateLoading = ref(false);

    async function fetchPrdTemplate(projectId) {
        prdTemplateLoading.value = true;
        prdTemplateError.value = null;
        try {
            const { data } = await axios.get(`/api/v1/admin/projects/${projectId}/prd-template`);
            prdTemplate.value = data.data;
        } catch {
            prdTemplateError.value = 'Failed to load PRD template.';
        } finally {
            prdTemplateLoading.value = false;
        }
    }

    async function updatePrdTemplate(projectId, template) {
        try {
            const { data } = await axios.put(`/api/v1/admin/projects/${projectId}/prd-template`, {
                template,
            });
            if (data.success && data.data) {
                prdTemplate.value = data.data;
            }
            return { success: true };
        } catch (e) {
            return {
                success: false,
                error: e.response?.data?.error || 'Failed to update PRD template.',
            };
        }
    }

    async function fetchGlobalPrdTemplate() {
        globalPrdTemplateLoading.value = true;
        try {
            const { data } = await axios.get('/api/v1/admin/prd-template');
            globalPrdTemplate.value = data.data;
        } catch {
            // Supplementary — don't block
        } finally {
            globalPrdTemplateLoading.value = false;
        }
    }

    async function updateGlobalPrdTemplate(template) {
        try {
            const { data } = await axios.put('/api/v1/admin/prd-template', {
                template,
            });
            if (data.success && data.data) {
                globalPrdTemplate.value = data.data;
            }
            return { success: true };
        } catch (e) {
            return {
                success: false,
                error: e.response?.data?.error || 'Failed to update global PRD template.',
            };
        }
    }

    // ─── Cost alerts (T94) ─────────────────────────────────────
    const costAlerts = ref([]);
    const costAlertsLoading = ref(false);
    const costAlertsError = ref(null);

    async function fetchCostAlerts() {
        costAlertsLoading.value = true;
        costAlertsError.value = null;
        try {
            const { data } = await axios.get('/api/v1/dashboard/cost-alerts');
            costAlerts.value = data.data;
        } catch {
            costAlertsError.value = 'Failed to load cost alerts.';
        } finally {
            costAlertsLoading.value = false;
        }
    }

    async function acknowledgeCostAlert(alertId) {
        try {
            await axios.patch(`/api/v1/dashboard/cost-alerts/${alertId}/acknowledge`);
            costAlerts.value = costAlerts.value.filter(a => a.id !== alertId);
            return { success: true };
        } catch (e) {
            return { success: false, error: e.response?.data?.error || 'Failed to acknowledge alert.' };
        }
    }

    // ─── Over-reliance alerts (T95) ──────────────────────────────
    const overrelianceAlerts = ref([]);
    const overrelianceAlertsLoading = ref(false);
    const overrelianceAlertsError = ref(null);

    async function fetchOverrelianceAlerts() {
        overrelianceAlertsLoading.value = true;
        overrelianceAlertsError.value = null;
        try {
            const { data } = await axios.get('/api/v1/dashboard/overreliance-alerts');
            overrelianceAlerts.value = data.data;
        } catch {
            overrelianceAlertsError.value = 'Failed to load over-reliance alerts.';
        } finally {
            overrelianceAlertsLoading.value = false;
        }
    }

    async function acknowledgeOverrelianceAlert(alertId) {
        try {
            await axios.patch(`/api/v1/dashboard/overreliance-alerts/${alertId}/acknowledge`);
            overrelianceAlerts.value = overrelianceAlerts.value.filter(a => a.id !== alertId);
            return { success: true };
        } catch (e) {
            return { success: false, error: e.response?.data?.error || 'Failed to acknowledge alert.' };
        }
    }

    // ─── Infrastructure alerts (T104) ──────────────────────────────
    async function acknowledgeInfrastructureAlert(alertId) {
        try {
            await axios.patch(`/api/v1/dashboard/infrastructure-alerts/${alertId}/acknowledge`);
            return { success: true };
        } catch (e) {
            return { success: false, error: e.response?.data?.error || 'Failed to acknowledge alert.' };
        }
    }

    // ─── Dead letter queue (T97) ─────────────────────────────────
    const deadLetterEntries = ref([]);
    const deadLetterLoading = ref(false);
    const deadLetterError = ref(null);
    const deadLetterDetail = ref(null);
    const deadLetterDetailLoading = ref(false);

    async function fetchDeadLetterEntries(filters = {}) {
        deadLetterLoading.value = true;
        deadLetterError.value = null;
        try {
            const { data } = await axios.get('/api/v1/admin/dead-letter', { params: filters });
            deadLetterEntries.value = data.data;
        } catch {
            deadLetterError.value = 'Failed to load dead letter queue.';
        } finally {
            deadLetterLoading.value = false;
        }
    }

    async function fetchDeadLetterDetail(entryId) {
        deadLetterDetailLoading.value = true;
        try {
            const { data } = await axios.get(`/api/v1/admin/dead-letter/${entryId}`);
            deadLetterDetail.value = data.data;
        } catch {
            deadLetterError.value = 'Failed to load entry details.';
        } finally {
            deadLetterDetailLoading.value = false;
        }
    }

    async function retryDeadLetterEntry(entryId) {
        try {
            const { data } = await axios.post(`/api/v1/admin/dead-letter/${entryId}/retry`);
            if (data.success) {
                deadLetterEntries.value = deadLetterEntries.value.filter(e => e.id !== entryId);
                deadLetterDetail.value = null;
            }
            return { success: true, data: data.data };
        } catch (e) {
            return { success: false, error: e.response?.data?.error || 'Failed to retry entry.' };
        }
    }

    async function dismissDeadLetterEntry(entryId) {
        try {
            await axios.post(`/api/v1/admin/dead-letter/${entryId}/dismiss`);
            deadLetterEntries.value = deadLetterEntries.value.filter(e => e.id !== entryId);
            deadLetterDetail.value = null;
            return { success: true };
        } catch (e) {
            return { success: false, error: e.response?.data?.error || 'Failed to dismiss entry.' };
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
        testWebhook,
        updateSettings,
        // Per-project config (T91)
        projectConfig,
        projectConfigLoading,
        projectConfigError,
        fetchProjectConfig,
        updateProjectConfig,
        // PRD template (T93)
        prdTemplate,
        prdTemplateLoading,
        prdTemplateError,
        globalPrdTemplate,
        globalPrdTemplateLoading,
        fetchPrdTemplate,
        updatePrdTemplate,
        fetchGlobalPrdTemplate,
        updateGlobalPrdTemplate,
        // Cost alerts (T94)
        costAlerts,
        costAlertsLoading,
        costAlertsError,
        fetchCostAlerts,
        acknowledgeCostAlert,
        // Over-reliance alerts (T95)
        overrelianceAlerts,
        overrelianceAlertsLoading,
        overrelianceAlertsError,
        fetchOverrelianceAlerts,
        acknowledgeOverrelianceAlert,
        // Infrastructure alerts (T104)
        acknowledgeInfrastructureAlert,
        // Dead letter queue (T97)
        deadLetterEntries,
        deadLetterLoading,
        deadLetterError,
        deadLetterDetail,
        deadLetterDetailLoading,
        fetchDeadLetterEntries,
        fetchDeadLetterDetail,
        retryDeadLetterEntry,
        dismissDeadLetterEntry,
    };
});

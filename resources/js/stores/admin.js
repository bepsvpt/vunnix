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

    return {
        projects,
        loading,
        error,
        fetchProjects,
        enableProject,
        disableProject,
    };
});

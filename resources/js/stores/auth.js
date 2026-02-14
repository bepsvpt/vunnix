import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import axios from 'axios';

export const useAuthStore = defineStore('auth', () => {
    // null = unknown (not yet checked), object = authenticated, false = not authenticated
    const user = ref(null);
    const loading = ref(false);

    const isAuthenticated = computed(() => user.value !== null && user.value !== false);
    const isGuest = computed(() => user.value === false);
    const isLoading = computed(() => loading.value);

    /**
     * Fetch the authenticated user's profile from /api/v1/user.
     * Sets user to the response data on success, or false on 401 (guest).
     */
    async function fetchUser() {
        loading.value = true;
        try {
            const response = await axios.get('/api/v1/user');
            user.value = response.data.data;
        } catch (error) {
            user.value = false;
        } finally {
            loading.value = false;
        }
    }

    /**
     * Redirect to GitLab OAuth login.
     * Uses window.location for a full-page redirect (OAuth requires leaving the SPA).
     */
    function login() {
        window.location.href = '/auth/redirect';
    }

    /**
     * Log out: POST to /auth/logout, clear store, redirect to login.
     */
    async function logout() {
        try {
            await axios.post('/auth/logout');
        } catch {
            // Proceed with client-side cleanup even if the server request fails
        }
        user.value = false;
        window.location.href = '/auth/redirect';
    }

    /**
     * Check if the user has a specific permission on any of their projects.
     */
    function hasPermission(permissionName) {
        if (!user.value || !user.value.projects) return false;
        return user.value.projects.some(
            (project) => project.permissions.includes(permissionName)
        );
    }

    /**
     * Check if the user has a specific permission on a given project (by ID).
     */
    function hasProjectPermission(permissionName, projectId) {
        if (!user.value || !user.value.projects) return false;
        const project = user.value.projects.find((p) => p.id === projectId);
        return project ? project.permissions.includes(permissionName) : false;
    }

    /**
     * Get all projects the user has access to.
     */
    const projects = computed(() => {
        if (!user.value || !user.value.projects) return [];
        return user.value.projects;
    });

    function setUser(userData) {
        user.value = userData;
    }

    function clearUser() {
        user.value = false;
    }

    return {
        user,
        loading,
        isAuthenticated,
        isGuest,
        isLoading,
        projects,
        fetchUser,
        login,
        logout,
        hasPermission,
        hasProjectPermission,
        setUser,
        clearUser,
    };
});

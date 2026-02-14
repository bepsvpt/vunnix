import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

export const useAuthStore = defineStore('auth', () => {
    // null = unknown (not yet checked), object = authenticated, false = not authenticated
    const user = ref(null);
    const loading = ref(false);

    const isAuthenticated = computed(() => user.value !== null && user.value !== false);
    const isGuest = computed(() => user.value === false);
    const isLoading = computed(() => loading.value);

    // Placeholder — T62 will implement the full check against /api/v1/user
    async function fetchUser() {
        // T62: implement API call
    }

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
        fetchUser,
        setUser,
        clearUser,
    };
});

<script setup lang="ts">
import { watch } from 'vue';
import AppNavigation from '@/components/AppNavigation.vue';
import { whenConnected } from '@/composables/useEcho';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();

// Eagerly establish WebSocket connection once authenticated.
// This ensures Echo is ready before any page needs real-time subscriptions
// (chat task updates, dashboard activity feeds, etc.).
watch(() => auth.isAuthenticated, (authenticated) => {
    if (authenticated) {
        whenConnected();
    }
}, { immediate: true });
</script>

<template>
    <div class="min-h-screen flex flex-col">
        <template v-if="auth.isAuthenticated">
            <AppNavigation />
            <main class="flex-1 p-4 lg:p-8">
                <router-view />
            </main>
        </template>
        <div v-else class="flex-1 flex items-center justify-center">
            <div class="text-center text-zinc-500 dark:text-zinc-400">
                <svg class="animate-spin h-8 w-8 mx-auto mb-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                </svg>
                <p class="text-sm">
                    Loading…
                </p>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { watch } from 'vue';
import AppNavigation from '@/components/AppNavigation.vue';
import BaseSpinner from '@/components/ui/BaseSpinner.vue';
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
    <div class="h-dvh flex flex-col">
        <template v-if="auth.isAuthenticated">
            <AppNavigation />
            <main class="flex-1 min-h-0 overflow-auto">
                <router-view />
            </main>
        </template>
        <template v-else-if="auth.isGuest">
            <router-view />
        </template>
        <div v-else class="flex-1 flex items-center justify-center">
            <div class="text-center text-zinc-500 dark:text-zinc-400">
                <BaseSpinner size="lg" class="mx-auto mb-3" />
                <p class="text-sm">
                    Loading…
                </p>
            </div>
        </div>
    </div>
</template>

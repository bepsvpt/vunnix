<script setup>
import { onMounted, onUnmounted } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useDashboardRealtime } from '@/composables/useDashboardRealtime';

const auth = useAuthStore();
const { subscribe, unsubscribe } = useDashboardRealtime();

onMounted(() => {
    subscribe(auth.projects);
});

onUnmounted(() => {
    unsubscribe();
});
</script>

<template>
  <div>
    <h1 class="text-2xl font-semibold">Dashboard</h1>
    <p class="mt-2 text-zinc-500 dark:text-zinc-400">Activity feed and metrics — coming in M4.</p>
  </div>
</template>

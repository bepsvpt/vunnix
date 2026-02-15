<script setup>
import { onMounted, onUnmounted } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useDashboardStore } from '@/stores/dashboard';
import { useDashboardRealtime } from '@/composables/useDashboardRealtime';
import ActivityFeed from '@/components/ActivityFeed.vue';

const auth = useAuthStore();
const dashboard = useDashboardStore();
const { subscribe, unsubscribe } = useDashboardRealtime();

onMounted(() => {
    dashboard.fetchActivity();
    subscribe(auth.projects);
});

onUnmounted(() => {
    unsubscribe();
});
</script>

<template>
  <div>
    <h1 class="text-2xl font-semibold mb-6">Dashboard</h1>
    <ActivityFeed />
  </div>
</template>

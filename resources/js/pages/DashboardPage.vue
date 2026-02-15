<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useDashboardStore } from '@/stores/dashboard';
import { useDashboardRealtime } from '@/composables/useDashboardRealtime';
import ActivityFeed from '@/components/ActivityFeed.vue';
import DashboardOverview from '@/components/DashboardOverview.vue';
import DashboardQuality from '@/components/DashboardQuality.vue';
import DashboardPMActivity from '@/components/DashboardPMActivity.vue';
import DashboardDesignerActivity from '@/components/DashboardDesignerActivity.vue';
import DashboardEfficiency from '@/components/DashboardEfficiency.vue';

const auth = useAuthStore();
const dashboard = useDashboardStore();
const { subscribe, unsubscribe } = useDashboardRealtime();

const activeView = ref('overview');

const views = [
    { key: 'overview', label: 'Overview' },
    { key: 'quality', label: 'Quality' },
    { key: 'pm-activity', label: 'PM Activity' },
    { key: 'designer-activity', label: 'Designer Activity' },
    { key: 'efficiency', label: 'Efficiency' },
    { key: 'activity', label: 'Activity' },
];

onMounted(() => {
    dashboard.fetchOverview();
    dashboard.fetchActivity();
    subscribe(auth.projects);
});

onUnmounted(() => {
    unsubscribe();
});

// Re-fetch overview when real-time metrics arrive
watch(() => dashboard.metricsUpdates.length, () => {
    dashboard.fetchOverview();
});
</script>

<template>
  <div>
    <h1 class="text-2xl font-semibold mb-6">Dashboard</h1>

    <!-- View tabs -->
    <div class="flex items-center gap-2 mb-6" data-testid="dashboard-view-tabs">
      <button
        v-for="view in views"
        :key="view.key"
        :data-testid="`view-tab-${view.key}`"
        class="px-4 py-2 text-sm font-medium rounded-lg border transition-colors"
        :class="activeView === view.key
          ? 'border-zinc-500 bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300'
          : 'border-zinc-300 dark:border-zinc-700 text-zinc-500 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800'"
        @click="activeView = view.key"
      >
        {{ view.label }}
      </button>
    </div>

    <!-- View content -->
    <DashboardOverview v-if="activeView === 'overview'" />
    <DashboardQuality v-else-if="activeView === 'quality'" />
    <DashboardPMActivity v-else-if="activeView === 'pm-activity'" />
    <DashboardDesignerActivity v-else-if="activeView === 'designer-activity'" />
    <DashboardEfficiency v-else-if="activeView === 'efficiency'" />
    <ActivityFeed v-else-if="activeView === 'activity'" />
  </div>
</template>

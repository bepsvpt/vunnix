<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import ActivityFeed from '@/components/ActivityFeed.vue';
import DashboardAdoption from '@/components/DashboardAdoption.vue';
import DashboardCost from '@/components/DashboardCost.vue';
import DashboardDesignerActivity from '@/components/DashboardDesignerActivity.vue';
import DashboardEfficiency from '@/components/DashboardEfficiency.vue';
import DashboardHealthPanel from '@/components/DashboardHealthPanel.vue';
import DashboardInfrastructure from '@/components/DashboardInfrastructure.vue';
import DashboardOverview from '@/components/DashboardOverview.vue';
import DashboardPMActivity from '@/components/DashboardPMActivity.vue';
import DashboardQuality from '@/components/DashboardQuality.vue';
import MemoryStatsWidget from '@/components/MemoryStatsWidget.vue';
import BaseTabGroup from '@/components/ui/BaseTabGroup.vue';
import { useDashboardRealtime } from '@/composables/useDashboardRealtime';
import { useDashboardStore } from '@/features/dashboard';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const dashboard = useDashboardStore();
const { subscribe, unsubscribe } = useDashboardRealtime();

const activeView = ref('overview');

const baseViews: Array<{ key: string; label: string }> = [
    { key: 'overview', label: 'Overview' },
    { key: 'quality', label: 'Quality' },
    { key: 'health', label: 'Health' },
    { key: 'pm-activity', label: 'PM Activity' },
    { key: 'designer-activity', label: 'Designer Activity' },
    { key: 'efficiency', label: 'Efficiency' },
    { key: 'adoption', label: 'Adoption' },
];

// Cost tab is admin-only (D29)
const views = computed(() => {
    const v = [...baseViews];
    if (auth.hasPermission('admin.global_config')) {
        v.push({ key: 'cost', label: 'Cost' });
        v.push({ key: 'infrastructure', label: 'Infrastructure' });
    }
    v.push({ key: 'activity', label: 'Activity' });
    return v;
});

const activeProjectId = computed<number | null>(() => {
    const project = auth.projects[0];
    return project ? project.id : null;
});

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
    <div class="max-w-[var(--width-content)] mx-auto">
        <!-- Page header -->
        <div class="px-6 lg:px-8 pt-6 pb-0">
            <h1 class="text-xl font-semibold mb-4">
                Dashboard
            </h1>
            <BaseTabGroup
                :tabs="views"
                :model-value="activeView"
                data-testid="dashboard-view-tabs"
                @update:model-value="activeView = $event"
            />
        </div>

        <!-- Tab content -->
        <div class="px-6 lg:px-8 py-6">
            <div v-if="activeView === 'overview'" class="space-y-6">
                <DashboardOverview />
                <MemoryStatsWidget v-if="activeProjectId !== null" :project-id="activeProjectId" />
            </div>
            <DashboardQuality v-else-if="activeView === 'quality'" />
            <DashboardHealthPanel
                v-else-if="activeView === 'health' && activeProjectId !== null"
                :project-id="activeProjectId"
            />
            <DashboardPMActivity v-else-if="activeView === 'pm-activity'" />
            <DashboardDesignerActivity v-else-if="activeView === 'designer-activity'" />
            <DashboardEfficiency v-else-if="activeView === 'efficiency'" />
            <DashboardCost v-else-if="activeView === 'cost'" />
            <DashboardInfrastructure v-else-if="activeView === 'infrastructure'" />
            <DashboardAdoption v-else-if="activeView === 'adoption'" />
            <ActivityFeed v-else-if="activeView === 'activity'" />
        </div>
    </div>
</template>

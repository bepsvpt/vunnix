import type { Activity } from '@/types';
import axios from 'axios';
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

export const useDashboardStore = defineStore('dashboard', () => {
    const activityFeed = ref<Activity[]>([]);
    const metricsUpdates = ref<Array<{ project_id: number; [key: string]: unknown }>>([]);
    const overview = ref<Record<string, unknown> | null>(null);
    const overviewLoading = ref(false);
    const quality = ref<Record<string, unknown> | null>(null);
    const qualityLoading = ref(false);
    const pmActivity = ref<Record<string, unknown> | null>(null);
    const pmActivityLoading = ref(false);
    const designerActivity = ref<Record<string, unknown> | null>(null);
    const designerActivityLoading = ref(false);
    const efficiency = ref<Record<string, unknown> | null>(null);
    const efficiencyLoading = ref(false);
    const cost = ref<Record<string, unknown> | null>(null);
    const costLoading = ref(false);
    const costAlerts = ref<Array<Record<string, unknown>>>([]);
    const infrastructureAlerts = ref<Array<Record<string, unknown>>>([]);
    const infrastructureStatus = ref<{ overall_status: string; active_alerts_count: number } | null>(null);
    const overrelianceAlerts = ref<Array<Record<string, unknown>>>([]);
    const adoption = ref<Record<string, unknown> | null>(null);
    const adoptionLoading = ref(false);
    const isLoading = ref(false);
    const nextCursor = ref<string | null>(null);
    const hasMore = computed(() => nextCursor.value !== null);

    // Filter state for activity feed tabs (§5.3)
    const activeFilter = ref<string | null>(null); // null = 'All', or: 'code_review', 'feature_dev', 'ui_adjustment', 'prd_creation'
    const projectFilter = ref<number | null>(null); // null = all projects, or project_id
    const promptVersionFilter = ref<string | null>(null); // null = all versions, or skill string like 'frontend-review:1.0'
    const promptVersions = ref<string[]>([]); // Available prompt versions for filter dropdown

    const FEED_CAP = 200;

    /**
     * Add or update an activity feed item.
     * Deduplicates by task_id (updates in-place on status change).
     * Prepends new items (most recent first). Caps at FEED_CAP.
     */
    function addActivityItem(item: Activity) {
        const idx = activityFeed.value.findIndex(a => a.task_id === item.task_id);
        if (idx !== -1) {
            activityFeed.value.splice(idx, 1);
        }
        activityFeed.value.unshift(item);
        if (activityFeed.value.length > FEED_CAP) {
            activityFeed.value = activityFeed.value.slice(0, FEED_CAP);
        }
    }

    /**
     * Add or update a metrics snapshot for a project.
     * Keeps one entry per project_id (latest wins).
     */
    function addMetricsUpdate(update: { project_id: number; [key: string]: unknown }) {
        const idx = metricsUpdates.value.findIndex(m => m.project_id === update.project_id);
        if (idx !== -1) {
            metricsUpdates.value.splice(idx, 1, update);
        } else {
            metricsUpdates.value.push(update);
        }
    }

    /**
     * Filtered activity feed respecting activeFilter (type) and projectFilter.
     */
    const filteredFeed = computed(() => {
        let items = activityFeed.value;
        if (activeFilter.value) {
            items = items.filter(i => i.type === activeFilter.value);
        }
        if (projectFilter.value) {
            items = items.filter(i => i.project_id === projectFilter.value);
        }
        return items;
    });

    async function fetchActivity(filter: string | null = null) {
        isLoading.value = true;
        activeFilter.value = filter;
        nextCursor.value = null;

        try {
            const params: Record<string, unknown> = { per_page: 25 };
            if (filter)
                params.type = filter;

            const response = await axios.get('/api/v1/activity', { params });
            activityFeed.value = response.data.data;
            nextCursor.value = response.data.meta?.next_cursor ?? null;
        } finally {
            isLoading.value = false;
        }
    }

    async function loadMore() {
        if (!nextCursor.value || isLoading.value)
            return;
        isLoading.value = true;

        try {
            const params: Record<string, unknown> = { per_page: 25, cursor: nextCursor.value };
            if (activeFilter.value)
                params.type = activeFilter.value;

            const response = await axios.get('/api/v1/activity', { params });
            activityFeed.value.push(...response.data.data);
            nextCursor.value = response.data.meta?.next_cursor ?? null;
        } finally {
            isLoading.value = false;
        }
    }

    async function fetchOverview() {
        overviewLoading.value = true;
        try {
            const response = await axios.get('/api/v1/dashboard/overview');
            overview.value = response.data.data;
        } finally {
            overviewLoading.value = false;
        }
    }

    async function fetchQuality() {
        qualityLoading.value = true;
        try {
            const params: Record<string, unknown> = {};
            if (promptVersionFilter.value) {
                params.prompt_version = promptVersionFilter.value;
            }
            const response = await axios.get('/api/v1/dashboard/quality', { params });
            quality.value = response.data.data;
        } finally {
            qualityLoading.value = false;
        }
    }

    async function fetchPMActivity() {
        pmActivityLoading.value = true;
        try {
            const response = await axios.get('/api/v1/dashboard/pm-activity');
            pmActivity.value = response.data.data;
        } finally {
            pmActivityLoading.value = false;
        }
    }

    async function fetchDesignerActivity() {
        designerActivityLoading.value = true;
        try {
            const response = await axios.get('/api/v1/dashboard/designer-activity');
            designerActivity.value = response.data.data;
        } finally {
            designerActivityLoading.value = false;
        }
    }

    async function fetchEfficiency() {
        efficiencyLoading.value = true;
        try {
            const response = await axios.get('/api/v1/dashboard/efficiency');
            efficiency.value = response.data.data;
        } finally {
            efficiencyLoading.value = false;
        }
    }

    async function fetchCost() {
        costLoading.value = true;
        try {
            const response = await axios.get('/api/v1/dashboard/cost');
            cost.value = response.data.data;
        } finally {
            costLoading.value = false;
        }
    }

    async function fetchCostAlerts() {
        try {
            const response = await axios.get('/api/v1/dashboard/cost-alerts');
            costAlerts.value = response.data.data;
        } catch {
            // Supplementary — don't block dashboard
        }
    }

    async function fetchPromptVersions() {
        try {
            const response = await axios.get('/api/v1/prompt-versions');
            promptVersions.value = response.data.data;
        } catch {
            // Supplementary — don't block dashboard
        }
    }

    async function fetchInfrastructureAlerts() {
        try {
            const response = await axios.get('/api/v1/dashboard/infrastructure-alerts');
            infrastructureAlerts.value = response.data.data;
            // Derive overall status from active alerts
            infrastructureStatus.value = {
                overall_status: infrastructureAlerts.value.length > 0 ? 'degraded' : 'healthy',
                active_alerts_count: infrastructureAlerts.value.length,
            };
        } catch {
            // Supplementary — don't block dashboard
        }
    }

    async function fetchOverrelianceAlerts() {
        try {
            const response = await axios.get('/api/v1/dashboard/overreliance-alerts');
            overrelianceAlerts.value = response.data.data;
        } catch {
            // Supplementary — don't block dashboard
        }
    }

    async function fetchAdoption() {
        adoptionLoading.value = true;
        try {
            const response = await axios.get('/api/v1/dashboard/adoption');
            adoption.value = response.data.data;
        } finally {
            adoptionLoading.value = false;
        }
    }

    function $reset() {
        activityFeed.value = [];
        metricsUpdates.value = [];
        overview.value = null;
        overviewLoading.value = false;
        quality.value = null;
        qualityLoading.value = false;
        pmActivity.value = null;
        pmActivityLoading.value = false;
        designerActivity.value = null;
        designerActivityLoading.value = false;
        efficiency.value = null;
        efficiencyLoading.value = false;
        cost.value = null;
        costLoading.value = false;
        costAlerts.value = [];
        infrastructureAlerts.value = [];
        infrastructureStatus.value = null;
        overrelianceAlerts.value = [];
        adoption.value = null;
        adoptionLoading.value = false;
        activeFilter.value = null;
        projectFilter.value = null;
        promptVersionFilter.value = null;
        promptVersions.value = [];
        isLoading.value = false;
        nextCursor.value = null;
    }

    return {
        activityFeed,
        metricsUpdates,
        overview,
        overviewLoading,
        quality,
        qualityLoading,
        pmActivity,
        pmActivityLoading,
        designerActivity,
        designerActivityLoading,
        efficiency,
        efficiencyLoading,
        cost,
        costLoading,
        costAlerts,
        infrastructureAlerts,
        infrastructureStatus,
        overrelianceAlerts,
        adoption,
        adoptionLoading,
        activeFilter,
        projectFilter,
        promptVersionFilter,
        promptVersions,
        isLoading,
        nextCursor,
        hasMore,
        filteredFeed,
        addActivityItem,
        addMetricsUpdate,
        fetchActivity,
        fetchOverview,
        fetchQuality,
        fetchPMActivity,
        fetchDesignerActivity,
        fetchEfficiency,
        fetchCost,
        fetchCostAlerts,
        fetchInfrastructureAlerts,
        fetchPromptVersions,
        fetchOverrelianceAlerts,
        fetchAdoption,
        loadMore,
        $reset,
    };
});
